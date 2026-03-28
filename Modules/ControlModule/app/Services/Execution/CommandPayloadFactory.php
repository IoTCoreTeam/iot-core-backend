<?php

namespace Modules\ControlModule\Services\Execution;

use Modules\ControlModule\Models\ControlJsonCommand;
use Modules\ControlModule\Models\ControlUrl;

class CommandPayloadFactory
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function build(ControlUrl $controlUrl, array $payload): array
    {
        $node = $controlUrl->node;
        $gatewayExternalId = $node?->gateway?->external_id;
        $nodeExternalId = $node?->external_id;

        $commandPayload = $payload;
        unset($commandPayload['url']);

        if (! empty($gatewayExternalId)) {
            $commandPayload['gateway_id'] = $gatewayExternalId;
        }

        if (! empty($nodeExternalId)) {
            $commandPayload['node_id'] = $nodeExternalId;
        }

        $inputType = strtolower(trim((string) ($controlUrl->input_type ?? '')));
        if ($inputType === 'json_command') {
            $this->applyJsonCommandPayload($controlUrl, $commandPayload);
        } else {
            unset(
                $commandPayload['save_command_payload'],
                $commandPayload['json_command_id'],
                $commandPayload['json_command_name']
            );
        }

        $commandPayload['requested_at'] = $commandPayload['requested_at'] ?? now()->toISOString();
        $commandPayload['requested_at_ms'] = $commandPayload['requested_at_ms'] ?? now()->getTimestampMs();
        if (! array_key_exists('wait_for_response', $commandPayload)) {
            $commandPayload['wait_for_response'] = true;
        }
        if (! array_key_exists('response_timeout_ms', $commandPayload)) {
            $commandPayload['response_timeout_ms'] = (int) config('services.node_server.control_response_timeout_ms', 15000);
        }
        if (! array_key_exists('response_deadline_at', $commandPayload)) {
            $commandPayload['response_deadline_at'] = now()
                ->addMilliseconds((int) $commandPayload['response_timeout_ms'])
                ->toISOString();
        }

        return $commandPayload;
    }

    /**
     * @param array<string, mixed> $commandPayload
     */
    public function resolveRequestTimeoutSeconds(array $commandPayload): int
    {
        $timeoutMs = (int) ($commandPayload['response_timeout_ms'] ?? config('services.node_server.control_response_timeout_ms', 15000));
        if ($timeoutMs < 1000) {
            $timeoutMs = 1000;
        }
        $seconds = (int) ceil(($timeoutMs + 5000) / 1000);
        return max(10, $seconds);
    }

    /**
     * @param array<string, mixed> $commandPayload
     */
    private function applyJsonCommandPayload(ControlUrl $controlUrl, array &$commandPayload): void
    {
        if (! array_key_exists('command_payload', $commandPayload) && array_key_exists('command', $commandPayload)) {
            $commandPayload['command_payload'] = $commandPayload['command'];
        }

        if (! array_key_exists('command_payload', $commandPayload)) {
            $selected = $this->resolveJsonCommandModel($controlUrl, $commandPayload);
            if ($selected) {
                $commandPayload['command_payload'] = $selected->command;
                $commandPayload['json_command_id'] = (string) $selected->id;
                $commandPayload['json_command_name'] = (string) $selected->name;
            }
        }

        if (! array_key_exists('command_payload', $commandPayload)) {
            throw new \RuntimeException('JSON command payload is required for json_command control url.');
        }

        $resolvedCommandPayload = $this->ensureJsonObject($commandPayload['command_payload'], 'command_payload');

        if (array_key_exists('command_overrides', $commandPayload)) {
            $overrides = $this->ensureJsonObject($commandPayload['command_overrides'], 'command_overrides');
            $resolvedCommandPayload = $this->mergeCommandPayload($resolvedCommandPayload, $overrides);
        }

        $commandPayload['command_payload'] = $resolvedCommandPayload;

        if (! array_key_exists('action_type', $commandPayload) || trim((string) $commandPayload['action_type']) === '') {
            $commandPayload['action_type'] = 'relay_control';
        }

        $this->saveJsonCommandPayloadIfRequested($controlUrl, $commandPayload, $resolvedCommandPayload);
        unset($commandPayload['command']);
        unset($commandPayload['command_overrides']);
    }

    /**
     * @param array<string, mixed> $commandPayload
     */
    private function resolveJsonCommandModel(ControlUrl $controlUrl, array $commandPayload): ?ControlJsonCommand
    {
        $query = ControlJsonCommand::query()
            ->where('control_url_id', $controlUrl->id)
            ->whereNull('deleted_at');

        $jsonCommandId = trim((string) ($commandPayload['json_command_id'] ?? ''));
        if ($jsonCommandId !== '') {
            return (clone $query)->where('id', $jsonCommandId)->first();
        }

        $jsonCommandName = trim((string) ($commandPayload['json_command_name'] ?? ''));
        if ($jsonCommandName !== '') {
            return (clone $query)->where('name', $jsonCommandName)->first();
        }

        return $query->orderBy('created_at')->first();
    }

    private function ensureJsonObject(mixed $value, string $fieldName): array
    {
        if (! is_array($value)) {
            throw new \RuntimeException(sprintf('%s must be a JSON object.', $fieldName));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeCommandPayload(array $base, array $overrides): array
    {
        return array_replace_recursive($base, $overrides);
    }

    /**
     * @param array<string, mixed> $commandPayload
     * @param array<string, mixed> $resolvedCommandPayload
     */
    private function saveJsonCommandPayloadIfRequested(
        ControlUrl $controlUrl,
        array &$commandPayload,
        array $resolvedCommandPayload
    ): void {
        $saveRequested = filter_var($commandPayload['save_command_payload'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $saveRequested) {
            return;
        }

        $name = trim((string) ($commandPayload['json_command_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($controlUrl->name ?? ''));
        }
        if ($name === '') {
            $name = 'default';
        }

        $model = ControlJsonCommand::updateOrCreate(
            [
                'control_url_id' => $controlUrl->id,
                'name' => $name,
            ],
            [
                'command' => $resolvedCommandPayload,
                'deleted_at' => null,
            ]
        );

        $commandPayload['json_command_id'] = (string) $model->id;
        $commandPayload['json_command_name'] = (string) $model->name;
    }
}
