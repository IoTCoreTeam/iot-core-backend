<?php

namespace Modules\MapModule\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\SystemLogHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\ControlModule\Models\Node;
use Modules\MapModule\Models\ManagedArea;
use Throwable;

class ManagedAreaNodeController extends Controller
{
    public function sync(Request $request, string $externalId)
    {
        $payload = $request->validate([
            'managed_area_ids' => ['nullable', 'array'],
            'managed_area_ids.*' => ['integer'],
        ]);

        try {
            $node = Node::query()->where('external_id', $externalId)->first();
            if (! $node) {
                return ApiResponse::error('Node not found', 404);
            }

            $user = $request->user();
            $managedAreaIds = $payload['managed_area_ids'] ?? [];

            $authorizedAreaIds = ManagedArea::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $managedAreaIds)
                ->pluck('id')
                ->all();

            DB::transaction(function () use ($node, $authorizedAreaIds) {
                DB::table('managed_area_node')
                    ->where('node_id', $node->id)
                    ->delete();

                if (empty($authorizedAreaIds)) {
                    return;
                }

                $now = now();
                $rows = array_map(
                    fn ($areaId) => [
                        'managed_area_id' => $areaId,
                        'node_id' => $node->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $authorizedAreaIds
                );

                DB::table('managed_area_node')->insert($rows);
            });

            SystemLogHelper::log('managed_area.node.sync', 'Managed areas synced for node', [
                'external_id' => $externalId,
                'node_id' => $node->id,
                'managed_area_ids' => $authorizedAreaIds,
            ]);

            return ApiResponse::success(
                ['managed_area_ids' => $authorizedAreaIds],
                'Managed areas updated successfully'
            );
        } catch (Throwable $e) {
            report($e);
            SystemLogHelper::log('managed_area.node.sync_failed', 'Failed to sync managed areas', [
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ], ['level' => 'error']);

            return ApiResponse::error('Failed to update managed areas', 500, $e->getMessage());
        }
    }
}
