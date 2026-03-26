<?php

namespace Modules\ControlModule\Services;

use App\Helpers\SystemLogHelper;
use Illuminate\Support\Facades\Http;

class WorkflowStatusEventService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function emit(array $payload): void
    {
        $baseUrl = rtrim((string) config('services.node_server.base_url'), '/');
        if ($baseUrl === '') {
            return;
        }

        try {
            Http::withHeaders($this->serviceAuthHeaders())
                ->timeout(5)
                ->post($baseUrl . '/v1/workflow-events/status', $payload);
        } catch (\Throwable $e) {
            SystemLogHelper::log(
                'workflow.status_event.emit_failed',
                'Failed to emit workflow status event to node server',
                [
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                ],
                ['level' => 'warning']
            );
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
