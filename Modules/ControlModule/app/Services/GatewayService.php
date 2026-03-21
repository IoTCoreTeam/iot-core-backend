<?php

namespace Modules\ControlModule\Services;

use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\Gateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewayService
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     gateway: Gateway,
     *     message: string,
     *     status: int
     * }
     */
    public function register($payload): array
    {
        $gateway = null;
        if (! empty($payload['mac_address'])) {
            $gateway = Gateway::withTrashed()
                ->where('mac_address', $payload['mac_address'])
                ->first();
        }

        if (! $gateway) {
            $gateway = Gateway::withTrashed()
                ->where('external_id', $payload['external_id'])
                ->first();
        }

        $created = false;
        if (!$gateway) {
            $gateway = Gateway::create($payload);
            $created = true;
        } else {
            if ($gateway->trashed()) {
                $gateway->restore();
            }
            $gateway->update($payload);
        }

        SystemLogHelper::log(
            'gateway.registered',
            'Gateway registered successfully',
            ['gateway_id' => $gateway->id]
        );

        return [
            'gateway' => $gateway->refresh(),
            'message' => $created ? 'Gateway registered successfully' : 'Gateway registration reactivated successfully',
            'status' => $created ? 201 : 200,
        ];
    }

    /**
     * @return array{gateway: Gateway, message: string}
     */
    public function deactivate(string $externalId): array
    {
        $gateway = Gateway::where('external_id', $externalId)->firstOrFail();

        DB::transaction(function () use ($gateway) {
            $gateway->nodeControllers()->delete();
            $gateway->nodeSensors()->delete();
            $gateway->nodes()->delete();
            $gateway->delete();
        });

        SystemLogHelper::log('gateway.deactivated', 'Gateway deactivated successfully', ['gateway_id' => $gateway->id]);

        return [
            'gateway' => $gateway,
            'message' => 'Gateway deactivated successfully',
        ];
    }

    public function deleteByExternalId(string $externalId): void
    {
        $gateway = Gateway::where('external_id', $externalId)->firstOrFail();
        $gateway->delete();

        SystemLogHelper::log('gateway.deleted', 'Gateway soft deleted successfully', [
            'gateway_id' => $gateway->id,
            'external_id' => $gateway->external_id,
        ]);
    }
}
