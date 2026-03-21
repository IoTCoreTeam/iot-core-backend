<?php

namespace Modules\ControlModule\Services;

use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\QueryBuilders\NodeQueryBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class NodeManagementService
{
    public function sendAvailableNode(): JsonResponse
    {
        $payload = $this->collectAvailableResources();

        $endpoint = $this->buildWhitelistEndpoint();

        Log::info('[NodeManagementService] sending whitelist payload', [
            'url' => $endpoint,
            'payload' => $payload,
        ]);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withHeaders($this->serviceAuthHeaders())->post($endpoint, $payload);

        if ($response->failed()) {
            return ApiResponse::error(
                'Failed to push whitelist to the Node server.',
                $response->status(),
                $response->json()
            );
        }

        return ApiResponse::success($response->json(), 'Whitelist synced with the Node server');
    }

    public function collectAvailableResources(): array
    {
        return NodeQueryBuilder::getWhitelistPayload();
    }

    private function buildWhitelistEndpoint(): string
    {
        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        return "{$baseUrl}/v1/whitelist";
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
