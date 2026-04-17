<?php

namespace Modules\ControlModule\Services;

use App\Helpers\SystemLogHelper;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Modules\ControlModule\Models\ControlUrl;
use Modules\ControlModule\Models\Workflow;
use Modules\ControlModule\Services\Workflows\WorkflowRunDataHelper;
use Modules\ControlModule\Services\Workflows\WorkflowRunHttpHelper;
use Modules\ControlModule\Services\Workflows\WorkflowRunStateStore;
use Modules\ControlModule\Services\Workflows\WorkflowStatusEventService;

class WorkflowRunService
{
    private ControlUrlService $controlUrlService;
    private ?string $currentRunId = null;
    private ?string $currentWorkflowId = null;
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $events = [];
    /**
     * @var null|callable
     */
    private $eventCallback = null;

    public function __construct(
        ControlUrlService $controlUrlService,
        private readonly NotificationService $notificationService,
        private readonly WorkflowRunStateStore $workflowRunStateStore,
        private readonly WorkflowRunDataHelper $workflowRunDataHelper,
        private readonly WorkflowRunHttpHelper $workflowRunHttpHelper,
        private readonly WorkflowStatusEventService $workflowStatusEventService
    ) {
        $this->controlUrlService = $controlUrlService;
    }

    public function setRunId(?string $runId): void
    {
        $this->currentRunId = $runId;
    }

    public function setEventCallback(?callable $callback): void
    {
        $this->eventCallback = $callback;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Workflow $workflow, ?User $actor = null): array
    {
        $this->events = [];
        $this->currentWorkflowId = (string) $workflow->id;

        $nodes = [];

        try {
            if ($workflow->status !== 'approved') {
                throw new \RuntimeException('Only approved workflows can be run.');
            }

            $this->recordEvent('workflow_start', ['workflow_id' => $workflow->id]);

            $definition = $workflow->control_definition;
            if (! is_array($definition) || empty($definition['nodes'])) {
                throw new \RuntimeException('Workflow definition is empty.');
            }

            $nodes = $definition['nodes'] ?? [];
            $edges = $definition['edges'] ?? [];

            $deviceStatus = $this->workflowRunHttpHelper->fetchDeviceStatus();

            $this->recordEvent('device_status_fetched', [
                'count' => is_array($deviceStatus) ? count($deviceStatus) : 0,
            ]);

            $this->assertWorkflowDevicesReady($nodes, $deviceStatus);

            $result = $this->executeFlow($nodes, $edges);
            $this->recordEvent('workflow_completed', [
                'workflow_id' => $workflow->id,
            ]);

            SystemLogHelper::log(
                'workflow.run.completed',
                'Workflow run completed',
                ['workflow_id' => $workflow->id]
            );
            $actor = $actor ?? Auth::user();
            if ($actor) {
                $this->notificationService->notifyWorkflowAction(
                    $actor,
                    $workflow,
                    'workflow.run.completed',
                    ['workflow_id' => $workflow->id]
                );
            }
            return [
                'workflow_id' => $workflow->id,
                'status' => 'completed',
                'result' => $result,
                'events' => $this->events,
            ];
        } catch (\Throwable $e) {
            $this->recordEvent('workflow_failed', [
                'workflow_id' => $workflow->id,
                'error' => $e->getMessage(),
            ], 'error');
            SystemLogHelper::log(
                'workflow.run.failed',
                'Workflow run failed',
                ['workflow_id' => $workflow->id, 'error' => $e->getMessage()],
                ['level' => 'error']
            );
            $actor = $actor ?? Auth::user();
            if ($actor) {
                $this->notificationService->notifyWorkflowAction(
                    $actor,
                    $workflow,
                    'workflow.run.failed',
                    ['workflow_id' => $workflow->id]
                );
            }
            throw $e;
        } finally {
            $this->currentWorkflowId = null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(Workflow $workflow): array
    {
        $this->events = [];
        $this->currentWorkflowId = (string) $workflow->id;
        $this->recordEvent('workflow_stop_requested', [
            'workflow_id' => $workflow->id,
        ]);

        $definition = $workflow->control_definition ?? $workflow->definition ?? null;
        if (! is_array($definition) || empty($definition['nodes'])) {
            $this->recordEvent('workflow_stop_skipped', [
                'workflow_id' => $workflow->id,
                'reason' => 'empty_definition',
            ]);
            return [
                'workflow_id' => $workflow->id,
                'status' => 'skipped',
                'events' => $this->events,
            ];
        }

        try {
            $this->recordEvent('workflow_stop_completed', [
                'workflow_id' => $workflow->id,
            ]);
            $this->recordEvent('workflow_stopped', [
                'workflow_id' => $workflow->id,
            ]);
            return [
                'workflow_id' => $workflow->id,
                'status' => 'stopped',
                'events' => $this->events,
            ];
        } catch (\Throwable $e) {
            $this->recordEvent('workflow_stop_failed', [
                'workflow_id' => $workflow->id,
                'error' => $e->getMessage(),
            ], 'error');
            throw $e;
        } finally {
            $this->currentWorkflowId = null;
        }
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, mixed> $deviceStatus
     */
    private function assertWorkflowDevicesReady(array $nodes, array $deviceStatus): void
    {
        $hasOnlineGateway = false;
        foreach ($deviceStatus as $gateway) {
            if (! is_array($gateway)) {
                continue;
            }
            $gatewayId = $gateway['id'] ?? $gateway['gateway_id'] ?? null;
            $gatewayStatus = strtolower((string) ($gateway['status'] ?? ''));
            if ($gatewayId && $gatewayStatus === 'online') {
                $hasOnlineGateway = true;
                break;
            }
        }

        if (! $hasOnlineGateway) {
            $this->recordEvent('device_status_unavailable', [
                'reason' => 'no_online_gateway_in_status',
            ], 'error');
            throw new \RuntimeException('Workflow cannot run because device status has no online gateway.');
        }

        $requiredTargets = $this->collectRequiredTargets($nodes);
        if (empty($requiredTargets)) {
            $this->recordEvent('devices_check_skipped', [
                'reason' => 'no_action_nodes',
            ]);
            return;
        }

        $this->recordEvent('devices_check_started', [
            'required_count' => count($requiredTargets),
        ]);
        $onlineNodes = $this->workflowRunDataHelper->indexOnlineNodes($deviceStatus);

        foreach ($requiredTargets as $key => $target) {
            $label = (string) ($target['label'] ?? $key);
            if (! isset($onlineNodes[$key])) {
                $this->recordEvent('device_offline', [
                    'device' => $label,
                ], 'error');
                throw new \RuntimeException("Device is offline or missing: {$label}");
            }

            $gatewayExternalId = (string) ($target['gateway_external_id'] ?? '');
            $nodeExternalId = (string) ($target['node_external_id'] ?? '');
            $controllerId = isset($target['controller_id'])
                ? trim((string) $target['controller_id'])
                : '';
            if ($gatewayExternalId !== '' && $nodeExternalId !== '' && $controllerId !== '') {
                $this->assertControllerExistsInDeviceStatus(
                    $deviceStatus,
                    $gatewayExternalId,
                    $nodeExternalId,
                    $controllerId,
                    $label
                );
            }
        }

        $this->recordEvent('devices_check_passed', [
            'online_count' => count($onlineNodes),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function collectRequiredTargets(array $nodes): array
    {
        $required = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) !== 'action') {
                continue;
            }
            $controlUrlId = $node['control_url_id'] ?? null;
            if (! $controlUrlId) {
                continue;
            }
            $controlUrl = ControlUrl::with('node.gateway')->find($controlUrlId);
            if (! $controlUrl) {
                continue;
            }
            $gatewayExternalId = $controlUrl->node?->gateway?->external_id;
            $nodeExternalId = $controlUrl->node?->external_id;
            if (! $gatewayExternalId || ! $nodeExternalId) {
                continue;
            }
            $key = $gatewayExternalId . '::' . $nodeExternalId;
            $required[$key] = [
                'label' => "{$gatewayExternalId} / {$nodeExternalId}",
                'gateway_external_id' => $gatewayExternalId,
                'node_external_id' => $nodeExternalId,
                'controller_id' => $controlUrl->controller_id,
            ];
        }

        return $required;
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, mixed> $edges
     * @return array<string, mixed>
     */
    private function executeFlow(array $nodes, array $edges): array
    {
        $nodeMap = $this->workflowRunDataHelper->indexNodes($nodes);
        $edgeMap = $this->workflowRunDataHelper->indexEdges($edges);
        $startId = $this->workflowRunDataHelper->findNodeIdByType($nodes, 'start');
        $endId = $this->workflowRunDataHelper->findNodeIdByType($nodes, 'end');

        if (! $startId || ! $endId) {
            throw new \RuntimeException('Workflow must contain start and end nodes.');
        }

        $currentId = $startId;
        $visited = [];
        $steps = 0;
        $maxSteps = max(count($nodes) * 5, 20);

        while ($currentId) {
            if ($steps > $maxSteps) {
                throw new \RuntimeException('Workflow exceeded maximum steps.');
            }
            $steps++;

            if ($currentId === $endId) {
                $this->recordEvent('workflow_end_reached', [
                    'node_id' => $currentId,
                    'steps' => $steps,
                ]);
                return [
                    'visited' => $visited,
                    'steps' => $steps,
                ];
            }

            $node = $nodeMap[$currentId] ?? null;
            if (! $node) {
                throw new \RuntimeException("Node not found: {$currentId}");
            }
            $visited[] = $currentId;

            $type = $node['type'] ?? null;
            $this->recordEvent('node_enter', [
                'node_id' => $currentId,
                'type' => $type,
            ]);
            if ($type === 'action') {
                $this->runActionNode($node);
                $currentId = $this->workflowRunDataHelper->resolveNextNodeId($currentId, $edgeMap, null);
                continue;
            }

            if ($type === 'condition') {
                $result = $this->evaluateConditionNode($node);
                $branch = $result ? 'true' : 'false';
                $currentId = $this->workflowRunDataHelper->resolveNextNodeId($currentId, $edgeMap, $branch);
                continue;
            }

            $currentId = $this->workflowRunDataHelper->resolveNextNodeId($currentId, $edgeMap, null);
        }

        throw new \RuntimeException('Workflow ended unexpectedly.');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function runActionNode(array $node): void
    {
        $controlUrlId = $node['control_url_id'] ?? null;
        if (! $controlUrlId) {
            throw new \RuntimeException('Action node missing control_url_id.');
        }
        $duration = (int) ($node['duration_seconds'] ?? 0);
        $actionType = $this->resolveControlUrlInputType($controlUrlId) ?? 'relay_control';
        $actionValue = $node['action_value'] ?? null;
        $jsonCommandPayload = [];
        $jsonCommandId = trim((string) ($node['json_command_id'] ?? ''));
        $jsonCommandName = trim((string) ($node['json_command_name'] ?? ''));
        if ($jsonCommandId !== '') {
            $jsonCommandPayload['json_command_id'] = $jsonCommandId;
        }
        if ($jsonCommandName !== '') {
            $jsonCommandPayload['json_command_name'] = $jsonCommandName;
        }

        $this->assertActionDeviceOnline($node);

        if ($actionValue !== null && is_numeric($actionValue)) {
            $value = (float) $actionValue;
            try {
                $this->recordEvent('action_on', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                    'value' => $value,
                ]);
                $this->controlUrlService->execute(
                    $controlUrlId,
                    $this->workflowRunHttpHelper->withControlResponseWait(
                        $this->withWorkflowCommandContext(array_merge(
                            ['action_type' => $actionType],
                            $jsonCommandPayload,
                            ['value' => $value]
                        ))
                    )
                );
            } catch (\Throwable $e) {
                $this->recordEvent('action_on_failed', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                    'error' => $e->getMessage(),
                ], 'error');
                throw $e;
            }
            return;
        }

        if ($actionValue !== null) {
            $state = strtolower((string) $actionValue);
            $isDigitalState = $state === 'on' || $state === 'off';
            if ($isDigitalState) {
            try {
                $this->recordEvent($state === 'on' ? 'action_on' : 'action_off', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                ]);
                $this->controlUrlService->execute(
                    $controlUrlId,
                    $this->workflowRunHttpHelper->withControlResponseWait(
                        $this->withWorkflowCommandContext(array_merge(
                            ['action_type' => $actionType],
                            $jsonCommandPayload,
                            ['state' => $state]
                        ))
                    )
                );
            } catch (\Throwable $e) {
                $this->recordEvent($state === 'on' ? 'action_on_failed' : 'action_off_failed', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                    'error' => $e->getMessage(),
                ], 'error');
                throw $e;
            }

            if ($state === 'on' && $duration > 0) {
                $this->waitActionDuration($duration, $controlUrlId, $node);
                $this->sendActionOff($controlUrlId, $actionType, $node, $jsonCommandPayload);
            }
            return;
            }
        }

        try {
            $this->recordEvent('action_on', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
            ]);
            $this->controlUrlService->execute(
                $controlUrlId,
                $this->workflowRunHttpHelper->withControlResponseWait(
                    $this->withWorkflowCommandContext(array_merge(
                        ['action_type' => $actionType],
                        $jsonCommandPayload,
                        ['state' => 'on']
                    ))
                )
            );
        } catch (\Throwable $e) {
            $this->recordEvent('action_on_failed', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
                'error' => $e->getMessage(),
            ], 'error');
            throw $e;
        }

        if ($duration > 0) {
            $this->waitActionDuration($duration, $controlUrlId, $node);
            $this->sendActionOff($controlUrlId, $actionType, $node, $jsonCommandPayload);
            return;
        }

        $this->sendActionOff($controlUrlId, $actionType, $node, $jsonCommandPayload);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function waitActionDuration(
        int $duration,
        string $controlUrlId,
        array $node
    ): void {
        $delay = max(0, $duration);
        if ($delay <= 0) {
            return;
        }

        $this->recordEvent('action_duration_wait_started', [
            'control_url_id' => $controlUrlId,
            'node_id' => $node['id'] ?? null,
            'delay_seconds' => $delay,
        ]);

        sleep($delay);

        $this->recordEvent('action_duration_wait_completed', [
            'control_url_id' => $controlUrlId,
            'node_id' => $node['id'] ?? null,
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function sendActionOff(
        string $controlUrlId,
        string $actionType,
        array $node,
        array $jsonCommandPayload = []
    ): void {
        try {
            $payload = array_merge(
                ['action_type' => $actionType],
                $jsonCommandPayload,
                ['state' => 'off']
            );
            $this->recordEvent('action_off', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
            ]);
            $this->controlUrlService->execute(
                $controlUrlId,
                $this->workflowRunHttpHelper->withControlResponseWait(
                    $this->withWorkflowCommandContext($payload)
                )
            );
        } catch (\Throwable $e) {
            $this->recordEvent('action_off_failed', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
                'error' => $e->getMessage(),
            ], 'error');
            throw $e;
        }
    }

    private function resolveControlUrlInputType(string $controlUrlId): ?string
    {
        $controlUrl = ControlUrl::find($controlUrlId);
        if (! $controlUrl) {
            return null;
        }
        $inputType = trim((string) ($controlUrl->input_type ?? ''));
        return $inputType !== '' ? $inputType : null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function evaluateConditionNode(array $node): bool
    {
        $metricKey = $node['metric_key'] ?? null;
        $operator = $node['operator'] ?? '>';
        $value = $node['value'] ?? null;
        if (! $metricKey || $value === null) {
            throw new \RuntimeException('Condition node missing metric data.');
        }

        $latest = $this->workflowRunHttpHelper->fetchLatestMetricValue((string) $metricKey, $this->workflowRunDataHelper);
        if ($latest === null) {
            throw new \RuntimeException('Failed to evaluate condition.');
        }

        $threshold = (float) $value;
        $current = (float) $latest;

        $result = match ($operator) {
            '>' => $current > $threshold,
            '<' => $current < $threshold,
            '>=' => $current >= $threshold,
            '<=' => $current <= $threshold,
            '==' => $current == $threshold,
            '!=' => $current != $threshold,
            default => $current > $threshold,
        };

        $this->recordEvent('condition_evaluated', [
            'metric_key' => $metricKey,
            'operator' => $operator,
            'value' => $threshold,
            'current' => $current,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function assertActionDeviceOnline(array $node): void
    {
        $controlUrlId = $node['control_url_id'] ?? null;
        if (! $controlUrlId) {
            return;
        }

        $controlUrl = ControlUrl::with('node.gateway')->find($controlUrlId);
        if (! $controlUrl) {
            $this->recordEvent('action_device_check_failed', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
                'error' => 'Control url not found',
            ], 'error');
            throw new \RuntimeException('Control url not found.');
        }

        $gatewayExternalId = $controlUrl->node?->gateway?->external_id;
        $nodeExternalId = $controlUrl->node?->external_id;
        if (! $gatewayExternalId || ! $nodeExternalId) {
            $this->recordEvent('action_device_check_failed', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
                'error' => 'Missing gateway/node external id',
            ], 'error');
            throw new \RuntimeException('Device reference is missing gateway/node id.');
        }

        $this->recordEvent('action_device_check_started', [
            'control_url_id' => $controlUrlId,
            'node_id' => $node['id'] ?? null,
            'gateway_id' => $gatewayExternalId,
            'device_id' => $nodeExternalId,
        ]);

        $deviceStatus = $this->workflowRunHttpHelper->fetchDeviceStatus();
        $onlineNodes = $this->workflowRunDataHelper->indexOnlineNodes($deviceStatus);
        $key = $gatewayExternalId . '::' . $nodeExternalId;
        if (! isset($onlineNodes[$key])) {
            $this->recordEvent('action_device_offline', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
                'gateway_id' => $gatewayExternalId,
                'device_id' => $nodeExternalId,
            ], 'error');
            throw new \RuntimeException("Device is offline or missing: {$gatewayExternalId} / {$nodeExternalId}");
        }

        $controllerId = trim((string) ($controlUrl->controller_id ?? ''));
        if ($controllerId !== '') {
            $this->assertControllerExistsInDeviceStatus(
                $deviceStatus,
                (string) $gatewayExternalId,
                (string) $nodeExternalId,
                $controllerId,
                "{$gatewayExternalId} / {$nodeExternalId}"
            );
        }

        $this->recordEvent('action_device_check_passed', [
            'control_url_id' => $controlUrlId,
            'node_id' => $node['id'] ?? null,
        ]);
    }

    /**
     * @param array<int, mixed> $deviceStatus
     */
    private function assertControllerExistsInDeviceStatus(
        array $deviceStatus,
        string $gatewayExternalId,
        string $nodeExternalId,
        string $controllerId,
        string $label
    ): void {
        $controllerKey = strtolower(trim($controllerId));
        if ($controllerKey === '') {
            return;
        }

        foreach ($deviceStatus as $gateway) {
            if (! is_array($gateway)) {
                continue;
            }
            $gatewayId = (string) ($gateway['id'] ?? $gateway['gateway_id'] ?? '');
            if ($gatewayId !== $gatewayExternalId) {
                continue;
            }

            $nodes = $gateway['nodes'] ?? [];
            if (! is_array($nodes)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $nodeId = (string) ($node['id'] ?? $node['node_id'] ?? '');
                if ($nodeId !== $nodeExternalId) {
                    continue;
                }

                $devices = $node['devices'] ?? [];
                if (! is_array($devices) || empty($devices)) {
                    break;
                }

                foreach ($devices as $device) {
                    if ($this->matchesControllerDevice($device, $controllerKey, $nodeExternalId)) {
                        return;
                    }
                }

                break;
            }
        }

        $this->recordEvent('action_device_not_found_in_status', [
            'device' => $label,
            'controller_id' => $controllerId,
        ], 'error');
        throw new \RuntimeException(
            "Controller device is missing in device status: {$label} / {$controllerId}"
        );
    }

    /**
     * @param mixed $device
     */
    private function matchesControllerDevice(mixed $device, string $controllerKey, string $nodeExternalId): bool
    {
        if (! is_array($device)) {
            return false;
        }

        $name = strtolower(trim((string) ($device['name'] ?? '')));
        if ($name !== '' && $name === $controllerKey) {
            return true;
        }

        $id = strtolower(trim((string) ($device['id'] ?? '')));
        if ($id !== '' && $id === $controllerKey) {
            return true;
        }

        $nodePrefix = strtolower($nodeExternalId) . '-';
        if ($id !== '' && str_starts_with($id, $nodePrefix)) {
            $suffix = substr($id, strlen($nodePrefix));
            if ($suffix === $controllerKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withWorkflowCommandContext(array $payload, ?string $workflowId = null): array
    {
        $resolvedWorkflowId = $workflowId ?? $this->currentWorkflowId;
        if ($resolvedWorkflowId) {
            $payload['workflow_id'] = $resolvedWorkflowId;
        }
        if ($this->currentRunId) {
            $payload['run_id'] = $this->currentRunId;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function emitWorkflowStatusEvent(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $status = match ($type) {
            'workflow_start' => 'workflow_started',
            'workflow_completed' => 'workflow_completed',
            'workflow_failed' => 'workflow_failed',
            'workflow_stopped' => 'workflow_stopped',
            default => null,
        };

        if (! $status) {
            return;
        }

        $this->workflowStatusEventService->emit([
            'type' => 'workflow_status',
            'status' => $status,
            'run_id' => $this->currentRunId,
            'workflow_id' => $event['workflow_id'] ?? $this->currentWorkflowId,
            'ts' => $event['timestamp'] ?? now()->toISOString(),
            'source' => 'backend',
            'error' => $event['error'] ?? null,
            'meta' => [
                'event_type' => $type,
                'level' => $event['level'] ?? 'info',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordEvent(string $type, array $context = [], string $level = 'info'): void
    {
        $event = array_merge([
            'timestamp' => now()->toISOString(),
            'type' => $type,
            'level' => $level,
        ], $context);
        $this->events[] = $event;
        $this->emitWorkflowStatusEvent($event);
        if ($this->eventCallback) {
            ($this->eventCallback)($event);
        }
    }

}
