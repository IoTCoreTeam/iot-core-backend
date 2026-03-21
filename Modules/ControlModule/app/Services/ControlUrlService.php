<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\DB;
use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\ControlUrl;

class ControlUrlService
{
    public function __construct(
        private readonly ControlCommandExecutionService $controlCommandExecutionService
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{control_url: ControlUrl, message: string, status: int}
     */
    public function create(array $payload): array
    {
        $controlUrl = DB::transaction(function () use ($payload) {
            return $this->upsertByControllerId($payload);
        });

        SystemLogHelper::log('control_url.created', 'Control url created successfully', [
            'control_url_id' => $controlUrl->id,
        ]);

        return [
            'control_url' => $controlUrl->refresh(),
            'message' => 'Control url created successfully',
            'status' => 201,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{control_url: ControlUrl, message: string}
     */
    public function update(string $id, array $payload): array
    {
        $controlUrl = DB::transaction(function () use ($id, $payload) {
            if (! empty($payload['controller_id'])) {
                return $this->upsertByControllerId($payload);
            }
            $controlUrl = ControlUrl::findOrFail($id);
            $controlUrl->update($payload);
            return $controlUrl;
        });

        SystemLogHelper::log('control_url.updated', 'Control url updated successfully', [
            'control_url_id' => $controlUrl->id,
        ]);

        return [
            'control_url' => $controlUrl->refresh(),
            'message' => 'Control url updated successfully',
        ];
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $controlUrl = ControlUrl::where('id', $id)
                ->orWhere('controller_id', $id)
                ->firstOrFail();
            $controlUrl->delete();
        });

        SystemLogHelper::log('control_url.deleted', 'Control url deleted successfully', [
            'control_url_id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{control_url: ControlUrl, message: string, status: int, response: mixed}
     */
    public function execute(string $id, array $payload): array
    {
        return DB::transaction(function () use ($id, $payload) {
            $controlUrl = ControlUrl::with('node.gateway')->findOrFail($id);
            $execution = $this->controlCommandExecutionService->execute($controlUrl, $payload);

            return [
                'control_url' => $controlUrl->refresh(),
                'message' => 'Control url executed successfully',
                'status' => $execution['status'],
                'response' => $execution['response'],
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertByControllerId(array $payload): ControlUrl
    {
        $controllerId = isset($payload['controller_id']) ? (string) $payload['controller_id'] : '';
        if ($controllerId === '') {
            return ControlUrl::create($payload);
        }

        $existing = ControlUrl::where('controller_id', $controllerId)->first();
        if ($existing) {
            $existing->update($payload);
            return $existing;
        }

        return ControlUrl::create($payload);
    }
}
