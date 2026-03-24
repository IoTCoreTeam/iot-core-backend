<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\ControlUrl;

class ControlCommandExecutionService
{
    /**
     * Execute control command through Node server.
     *
     * Response layers:
     * 1) HTTP response: transport result from Node server.
     * 2) Business response: command execution result from device, expected at:
     *    response.data.control_response.command_result
     *
     * Only layer (2) confirms the device actually applied the command.
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, response: mixed}
     */
    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, response: mixed}
     */
    public function execute(ControlUrl $controlUrl, array $payload): array
    {
        $endpoint = $this->resolveEndpoint($controlUrl, $payload);
        $commandPayload = $this->buildCommandPayload($controlUrl, $payload);
        $timeoutSeconds = $this->resolveRequestTimeoutSeconds($commandPayload);

        SystemLogHelper::log('control_url.execute_started', 'Executing control url', [
            'control_url_id' => $controlUrl->id,
            'endpoint' => $endpoint,
            'payload' => $commandPayload,
        ]);

        $response = Http::withHeaders($this->serviceAuthHeaders())
            ->timeout($timeoutSeconds)
            ->post($endpoint, $commandPayload);

        if ($response->failed()) {
            $failedResponsePayload = $response->json() ?? $response->body();
            SystemLogHelper::log('control_url.execute_failed', 'Control url execution failed', [
                'control_url_id' => $controlUrl->id,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $failedResponsePayload,
            ], ['level' => 'error']);

            $message = is_array($failedResponsePayload)
                ? (string) ($failedResponsePayload['message'] ?? 'Failed to execute control url')
                : 'Failed to execute control url';

            throw new \RuntimeException($message);
        }

        $responsePayload = $response->json();
        if (is_array($responsePayload) && array_key_exists('success', $responsePayload) && $responsePayload['success'] === false) {
            SystemLogHelper::log('control_url.execute_failed', 'Control url execution returned failed payload', [
                'control_url_id' => $controlUrl->id,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $responsePayload,
            ], ['level' => 'error']);
            $message = (string) ($responsePayload['message'] ?? 'Control command failed.');
            throw new \RuntimeException($message);
        }

        $this->assertControlResponse($controlUrl, $commandPayload, $responsePayload);

        SystemLogHelper::log('control_url.executed', 'Control url executed successfully', [
            'control_url_id' => $controlUrl->id,
            'endpoint' => $endpoint,
            'status' => $response->status(),
        ]);

        return [
            'status' => $response->status(),
            'response' => $responsePayload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveEndpoint(ControlUrl $controlUrl, array $payload): string
    {
        $url = (string) ($payload['url'] ?? $controlUrl->url);
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        $relativeUrl = '/' . ltrim($url, '/');
        return $baseUrl . '/v1/control' . $relativeUrl;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildCommandPayload(ControlUrl $controlUrl, array $payload): array
    {
        $node = $controlUrl->node;
        $gatewayExternalId = $node?->gateway?->external_id;
        $nodeExternalId = $node?->external_id;

        $commandPayload = $payload;
        unset($commandPayload['url']);

        if (! empty($gatewayExternalId) && empty($commandPayload['gateway_id'])) {
            $commandPayload['gateway_id'] = $gatewayExternalId;
        }

        if (! empty($nodeExternalId) && empty($commandPayload['node_id'])) {
            $commandPayload['node_id'] = $nodeExternalId;
        }

        // Always prefer canonical external IDs from Control Module relations.
    // This prevents clients from accidentally sending internal UUIDs that
        // break gateway topic routing and status-event waiter matching.
        if (! empty($gatewayExternalId)) {
            $commandPayload['gateway_id'] = $gatewayExternalId;
        }

        if (! empty($nodeExternalId)) {
            $commandPayload['node_id'] = $nodeExternalId;
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
    private function resolveRequestTimeoutSeconds(array $commandPayload): int
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
     * @param mixed $responsePayload
     */
    private function assertControlResponse(ControlUrl $controlUrl, array $commandPayload, mixed $responsePayload): void
    {
        // For async fire-and-forget use-cases we skip device-level response checks.
        $waitForResponse = (bool) ($commandPayload['wait_for_response'] ?? false);
        if (! $waitForResponse) {
            return;
        }

        // At this stage we require a JSON object from Node server.
        if (! is_array($responsePayload)) {
            throw new \RuntimeException('Invalid control response payload.');
        }

        // Node server wraps business payload in "data".
        $data = $responsePayload['data'] ?? null;
        if (! is_array($data)) {
            throw new \RuntimeException('Control response data is missing.');
        }

        // Device execution result is mapped from MQTT status-event to HTTP:
        // data.control_response.{command_result, command_seq, ...}
        $controlResponse = $data['control_response'] ?? null;
        if (! is_array($controlResponse)) {
            throw new \RuntimeException('Control response event was not received from gateway.');
        }

        // Accepted "success" result from firmware.
        // Any other explicit result (invalid_state, unknown_device, ...) is treated as failure.
        $result = strtolower((string) ($controlResponse['command_result'] ?? ''));
        if ($result !== '' && $result !== 'applied') {
            $message = sprintf(
                'Control command rejected: gateway=%s node=%s device=%s state=%s result=%s',
                (string) ($commandPayload['gateway_id'] ?? ''),
                (string) ($commandPayload['node_id'] ?? ''),
                (string) ($commandPayload['device'] ?? ''),
                (string) ($commandPayload['state'] ?? ''),
                $result
            );

            SystemLogHelper::log('control_url.execute_result_failed', 'Control command returned non-applied result', [
                'control_url_id' => $controlUrl->id,
                'result' => $controlResponse,
                'payload' => $commandPayload,
            ], ['level' => 'error']);

            throw new \RuntimeException($message);
        }
    }

    /**
     * @return array<string, string>
     */
    private function serviceAuthHeaders(): array
    {
        $serviceToken = trim((string) config('services.node_server.service_token', ''));
        if ($serviceToken === '') {
            return [];
        }

        return [
            'Authorization' => 'Bearer ' . $serviceToken,
            'X-Service-Token' => $serviceToken,
        ];
    }
}
