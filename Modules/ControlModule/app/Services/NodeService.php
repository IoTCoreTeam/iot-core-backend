<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\Log;
use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\Node;

class NodeService
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     node: Node,
     *     message: string,
     *     status: int
     * }
     */
    public function register(array $payload): array
    {
        $input = $payload;
        $payload = [
            'external_id' => $input['external_id'],
            'gateway_id' => $input['gateway_id'] ?? null,
            'name' => $input['name'] ?? null,
            'mac_address' => $input['mac_address'] ?? null,
            'ip_address' => $input['ip_address'] ?? null,
        ];

        if (array_key_exists('type', $input) && $input['type'] !== null) {
            $payload['type'] = $input['type'];
        }

        $node = null;
        if (! empty($payload['mac_address'])) {
            $node = Node::withTrashed()
                ->where('mac_address', $payload['mac_address'])
                ->first();
        }

        if (! $node) {
            $node = Node::withTrashed()
                ->where('external_id', $payload['external_id'])
                ->first();
        }

        $created = false;

        if (! $node) {
            $node = Node::create($payload);
            $created = true;
        } else {
            if ($node->trashed()) {
                $node->restore();
            }
            $node->update($payload);
        }

        SystemLogHelper::log(
            'node.registered',
            'Node registered successfully',
            ['node_id' => $node->id]
        );

        return [
            'node' => $node->refresh(),
            'message' => $created ? 'Node registered successfully' : 'Node registration reactivated successfully',
            'status' => $created ? 201 : 200,
        ];
    }

    /**
     * @return array{node: Node, message: string}
     */
    public function deactivate(string $externalId): array
    {
        $node = Node::where('external_id', $externalId)->firstOrFail();

        $node->delete();

        SystemLogHelper::log('node.deactivated', 'Node deactivated successfully', ['node_id' => $node->id]);

        return [
            'node' => $node,
            'message' => 'Node deactivated successfully',
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{node: Node, message: string}
     */
    public function updateLatestLocation(Node $node, array $data): array
    {
        $lat = $data['latest_lat'] ?? null;
        $lng = $data['latest_lng'] ?? null;

        if ($lat === null || $lng === null) {
            return [
                'node' => $node,
                'message' => 'GPS values are null, skipped',
            ];
        }

        $latNum = is_numeric($lat) ? (float) $lat : null;
        $lngNum = is_numeric($lng) ? (float) $lng : null;

        if ($latNum === null || $lngNum === null) {
            Log::warning("[NodeService] Invalid GPS type for node {$node->external_id}: lat={$lat}, lng={$lng}");

            return [
                'node' => $node,
                'message' => 'Invalid GPS type, skipped',
            ];
        }

        if ($latNum < -90 || $latNum > 90 || $lngNum < -180 || $lngNum > 180) {
            Log::warning("[NodeService] Invalid GPS range for node {$node->external_id}: lat={$latNum}, lng={$lngNum}");

            return [
                'node' => $node,
                'message' => 'GPS validation failed, skipped',
            ];
        }

        $updateData = [
            'latest_lat' => $latNum,
            'latest_lng' => $lngNum,
            'latest_gps_recorded_at' => now(),
        ];

        $headingDeg = $data['latest_heading_deg'] ?? null;
        $headingCardinal = $data['latest_heading_cardinal'] ?? null;

        if ($headingDeg !== null && is_numeric($headingDeg)) {
            $updateData['latest_heading_deg'] = (float) $headingDeg;
        }

        if ($headingCardinal !== null && is_string($headingCardinal)) {
            $updateData['latest_heading_cardinal'] = $headingCardinal;
        }

        $node->update($updateData);

        return [
            'node' => $node->fresh(),
            'message' => 'Latest location updated',
        ];
    }
}
