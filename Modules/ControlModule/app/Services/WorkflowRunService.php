<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\Http;
use Modules\ControlModule\Models\ControlUrl;
use Modules\ControlModule\Models\Workflow;
use Modules\ControlModule\Services\ControlUrlService;

class WorkflowRunService
{
    private ControlUrlService $controlUrlService;
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $events = [];
    /**
     * @var null|callable
     */
    private $eventCallback = null;

    public function __construct(ControlUrlService $controlUrlService)
    {
        $this->controlUrlService = $controlUrlService;
    }

    public function setEventCallback(?callable $callback): void
    {
        $this->eventCallback = $callback;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Workflow $workflow): array
    {
        $this->events = [];
        $this->recordEvent('workflow_start', [
            'workflow_id' => $workflow->id,
        ]);

        $definition = $workflow->control_definition ?? $workflow->definition ?? null;
        if (! is_array($definition) || empty($definition['nodes'])) {
            throw new \RuntimeException('Workflow definition is empty.');
        }

        $nodes = $definition['nodes'] ?? [];
        $edges = $definition['edges'] ?? [];

        $deviceStatus = $this->fetchDeviceStatus();
        $this->recordEvent('device_status_fetched', [
            'count' => is_array($deviceStatus) ? count($deviceStatus) : 0,
        ]);
        $this->assertDevicesOnline($nodes, $deviceStatus);
        $this->ensureWorkflowDevicesOff($nodes);
        $this->recordEvent('workflow_devices_ensured_off');

        try {
            $result = $this->executeFlow($nodes, $edges);
            $this->recordEvent('workflow_completed', [
                'workflow_id' => $workflow->id,
            ]);
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
            $this->abortWorkflowDevices($nodes);
            throw $e;
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
    private function fetchDeviceStatus(): array
    {
        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        $response = Http::timeout(10)->get($baseUrl . '/v1/device-status');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch device status.');
        }

        $payload = $response->json();
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        if (is_array($payload)) {
            return $payload;
        }

        return [];
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, mixed> $deviceStatus
     */
    private function assertDevicesOnline(array $nodes, array $deviceStatus): void
    {
        $requiredNodes = $this->collectRequiredNodes($nodes);
        if (empty($requiredNodes)) {
            $this->recordEvent('devices_check_skipped', [
                'reason' => 'no_action_nodes',
            ]);
            return;
        }

        $this->recordEvent('devices_check_started', [
            'required_count' => count($requiredNodes),
        ]);
        $onlineNodes = $this->indexOnlineNodes($deviceStatus);

        foreach ($requiredNodes as $key => $label) {
            if (! isset($onlineNodes[$key])) {
                $this->recordEvent('device_offline', [
                    'device' => $label,
                ], 'error');
                throw new \RuntimeException("Device is offline or missing: {$label}");
            }
        }

        $this->recordEvent('devices_check_passed', [
            'online_count' => count($onlineNodes),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function collectRequiredNodes(array $nodes): array
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
            $required[$key] = "{$gatewayExternalId} / {$nodeExternalId}";
        }

        return $required;
    }

    /**
     * @param array<int, mixed> $deviceStatus
     * @return array<string, bool>
     */
    private function indexOnlineNodes(array $deviceStatus): array
    {
        $online = [];
        foreach ($deviceStatus as $gateway) {
            if (! is_array($gateway)) {
                continue;
            }
            $gatewayId = $gateway['id'] ?? $gateway['gateway_id'] ?? null;
            if (! $gatewayId) {
                continue;
            }
            $gatewayStatus = strtolower((string) ($gateway['status'] ?? ''));
            if ($gatewayStatus !== 'online') {
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
                $nodeId = $node['id'] ?? $node['node_id'] ?? null;
                if (! $nodeId) {
                    continue;
                }
                $nodeStatus = strtolower((string) ($node['status'] ?? ''));
                if ($nodeStatus !== 'online') {
                    continue;
                }
                $key = $gatewayId . '::' . $nodeId;
                $online[$key] = true;
            }
        }

        return $online;
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function ensureWorkflowDevicesOff(array $nodes): void
    {
        $controlUrlIds = $this->collectActionControlUrls($nodes);
        if (empty($controlUrlIds)) {
            $this->recordEvent('workflow_devices_off_skipped', [
                'reason' => 'no_action_nodes',
            ]);
            return;
        }

        $this->recordEvent('workflow_devices_off_started', [
            'count' => count($controlUrlIds),
        ]);

        foreach ($controlUrlIds as $controlUrlId) {
            try {
                $this->recordEvent('workflow_device_off', [
                    'control_url_id' => $controlUrlId,
                ]);
                $actionType = $this->resolveControlUrlInputType($controlUrlId) ?? 'relay_control';
                $normalizedType = $this->normalizeControlInputType($actionType);
                $payload = [
                    'action_type' => $actionType,
                ];
                if ($normalizedType === 'analog') {
                    $payload['value'] = 0;
                } else {
                    $payload['state'] = 'off';
                }
                $this->controlUrlService->execute($controlUrlId, $payload);
            } catch (\Throwable $e) {
                $this->recordEvent('workflow_device_off_failed', [
                    'control_url_id' => $controlUrlId,
                    'error' => $e->getMessage(),
                ], 'error');
                throw new \RuntimeException('Failed to turn off workflow devices.');
            }
        }
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, mixed> $edges
     * @return array<string, mixed>
     */
    private function executeFlow(array $nodes, array $edges): array
    {
        $nodeMap = $this->indexNodes($nodes);
        $edgeMap = $this->indexEdges($edges);
        $startId = $this->findNodeIdByType($nodes, 'start');
        $endId = $this->findNodeIdByType($nodes, 'end');

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
                $currentId = $this->resolveNextNodeId($currentId, $edgeMap, null);
                continue;
            }

            if ($type === 'condition') {
                $result = $this->evaluateConditionNode($node);
                $branch = $result ? 'true' : 'false';
                $currentId = $this->resolveNextNodeId($currentId, $edgeMap, $branch);
                continue;
            }

            $currentId = $this->resolveNextNodeId($currentId, $edgeMap, null);
        }

        throw new \RuntimeException('Workflow ended unexpectedly.');
    }

    /**
     * @param array<int, mixed> $nodes
     * @return array<string, mixed>
     */
    private function indexNodes(array $nodes): array
    {
        $map = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $node['id'] ?? null;
            if (! $id) {
                continue;
            }
            $map[$id] = $node;
        }
        return $map;
    }

    /**
     * @param array<int, mixed> $edges
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function indexEdges(array $edges): array
    {
        $map = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = $edge['source'] ?? null;
            if (! $source) {
                continue;
            }
            $map[$source][] = $edge;
        }
        return $map;
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function findNodeIdByType(array $nodes, string $type): ?string
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === $type) {
                return $node['id'] ?? null;
            }
        }
        return null;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $edgeMap
     */
    private function resolveNextNodeId(string $currentId, array $edgeMap, ?string $branch): ?string
    {
        $edges = $edgeMap[$currentId] ?? [];
        if (empty($edges)) {
            return null;
        }
        if (! $branch) {
            $edge = $edges[0] ?? null;
            return $edge['target'] ?? null;
        }
        foreach ($edges as $edge) {
            $edgeBranch = $edge['branch'] ?? null;
            if ($edgeBranch === $branch) {
                return $edge['target'] ?? null;
            }
        }
        return null;
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
        $normalizedType = $this->normalizeControlInputType($actionType);
        $actionValue = $node['action_value'] ?? null;

        $this->assertActionDeviceOnline($node);

        if ($actionValue !== null && $normalizedType === 'analog') {
            if (! is_numeric($actionValue)) {
                throw new \RuntimeException('Analog action value must be numeric.');
            }
            $value = (float) $actionValue;
            try {
                $this->recordEvent('action_on', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                    'value' => $value,
                ]);
                $this->controlUrlService->execute($controlUrlId, [
                    'action_type' => $actionType,
                    'value' => $value,
                ]);
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

        if ($actionValue !== null && $normalizedType === 'digital') {
            $state = strtolower((string) $actionValue);
            if ($state !== 'on' && $state !== 'off') {
                throw new \RuntimeException('Digital action value must be "on" or "off".');
            }
            try {
                $this->recordEvent($state === 'on' ? 'action_on' : 'action_off', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                ]);
                $this->controlUrlService->execute($controlUrlId, [
                    // Ensure action_type is always present for IoT firmware routing.
                    'action_type' => $actionType,
                    'state' => $state,
                ]);
            } catch (\Throwable $e) {
                $this->recordEvent($state === 'on' ? 'action_on_failed' : 'action_off_failed', [
                    'control_url_id' => $controlUrlId,
                    'node_id' => $node['id'] ?? null,
                    'error' => $e->getMessage(),
                ], 'error');
                throw $e;
            }

            if ($state === 'on' && $duration > 0) {
                sleep($duration);
                try {
                    $this->recordEvent('action_off', [
                        'control_url_id' => $controlUrlId,
                        'node_id' => $node['id'] ?? null,
                    ]);
                    $this->controlUrlService->execute($controlUrlId, [
                        // Ensure action_type is always present for IoT firmware routing.
                        'action_type' => $actionType,
                        'state' => 'off',
                    ]);
                } catch (\Throwable $e) {
                    $this->recordEvent('action_off_failed', [
                        'control_url_id' => $controlUrlId,
                        'node_id' => $node['id'] ?? null,
                        'error' => $e->getMessage(),
                    ], 'error');
                    throw $e;
                }
            }
            return;
        }

        try {
            $this->recordEvent('action_on', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
            ]);
            $this->controlUrlService->execute($controlUrlId, [
                // Ensure action_type is always present for IoT firmware routing.
                'action_type' => $actionType,
                'state' => 'on',
            ]);
        } catch (\Throwable $e) {
            $this->recordEvent('action_on_failed', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
                'error' => $e->getMessage(),
            ], 'error');
            throw $e;
        }

        if ($duration > 0) {
            sleep($duration);
        }

        try {
            $this->recordEvent('action_off', [
                'control_url_id' => $controlUrlId,
                'node_id' => $node['id'] ?? null,
            ]);
            $this->controlUrlService->execute($controlUrlId, [
                // Ensure action_type is always present for IoT firmware routing.
                'action_type' => $actionType,
                'state' => 'off',
            ]);
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

    private function normalizeControlInputType(?string $inputType): ?string
    {
        $normalized = strtolower(trim((string) ($inputType ?? '')));
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'analog')) {
            return 'analog';
        }
        if (str_contains($normalized, 'digital') || str_contains($normalized, 'relay')) {
            return 'digital';
        }
        return $normalized;
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

        $latest = $this->fetchLatestMetricValue((string) $metricKey);
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

    private function fetchLatestMetricValue(string $metricKey): ?float
    {
        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        $mapped = $this->mapMetricKey($metricKey);
        $query = http_build_query([
            'sensor_type' => $mapped,
            'limit' => 1,
            'page' => 1,
        ]);

        $response = Http::timeout(10)->get($baseUrl . '/v1/sensors/query?' . $query);
        if ($response->failed()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || empty($payload[0])) {
            return null;
        }

        $row = $payload[0];
        $value = $row['value'] ?? ($row['_id']['value'] ?? null);
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function mapMetricKey(string $metricKey): string
    {
        return match ($metricKey) {
            'soilMoisture' => 'soil',
            'soil_moisture' => 'soil',
            'airHumidity' => 'humidity',
            'air_humidity' => 'humidity',
            default => $metricKey,
        };
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function abortWorkflowDevices(array $nodes): void
    {
        try {
            $this->ensureWorkflowDevicesOff($nodes);
            $this->recordEvent('workflow_devices_forced_off');
        } catch (\Throwable $e) {
            $this->recordEvent('workflow_devices_force_off_failed', [
                'error' => $e->getMessage(),
            ], 'error');
            // ignore abort errors
        }
    }

    /**
     * @param array<int, mixed> $nodes
     * @return array<int, string>
     */
    private function collectActionControlUrls(array $nodes): array
    {
        $controlUrlIds = [];
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
            $controlUrlIds[(string) $controlUrlId] = true;
        }

        return array_keys($controlUrlIds);
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

        $deviceStatus = $this->fetchDeviceStatus();
        $onlineNodes = $this->indexOnlineNodes($deviceStatus);
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

        $this->recordEvent('action_device_check_passed', [
            'control_url_id' => $controlUrlId,
            'node_id' => $node['id'] ?? null,
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
        if ($this->eventCallback) {
            ($this->eventCallback)($event);
        }
    }
}
