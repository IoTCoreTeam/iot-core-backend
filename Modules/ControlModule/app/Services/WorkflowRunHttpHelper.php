<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\Http;

class WorkflowRunHttpHelper
{
    /**
     * @return array<string, mixed>
     */
    public function fetchDeviceStatus(): array
    {
        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        $response = Http::withHeaders($this->serviceAuthHeaders())
            ->timeout(10)
            ->get($baseUrl . '/v1/device-status');

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

    public function fetchLatestMetricValue(string $metricKey, WorkflowRunDataHelper $dataHelper): ?float
    {
        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        $mapped = $dataHelper->mapMetricKey($metricKey);
        $query = http_build_query([
            'sensor_type' => $mapped,
            'limit' => 1,
            'page' => 1,
        ]);

        $response = Http::withHeaders($this->serviceAuthHeaders())
            ->timeout(10)
            ->get($baseUrl . '/v1/sensors/query?' . $query);
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function withControlResponseWait(array $payload): array
    {
        $timeoutMs = (int) config('services.node_server.control_response_timeout_ms', 15000);
        if ($timeoutMs < 1000) {
            $timeoutMs = 1000;
        }

        if (! array_key_exists('wait_for_response', $payload)) {
            $payload['wait_for_response'] = true;
        }
        if (! array_key_exists('response_timeout_ms', $payload)) {
            $payload['response_timeout_ms'] = $timeoutMs;
        }

        return $payload;
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

