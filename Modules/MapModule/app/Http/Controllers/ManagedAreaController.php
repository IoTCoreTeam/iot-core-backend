<?php

namespace Modules\MapModule\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\SystemLogHelper;
use App\Http\Controllers\Controller;
use Modules\MapModule\Http\Requests\ManagedArea\StoreManagedAreaRequest;
use Modules\MapModule\Http\Requests\ManagedArea\UpdateManagedAreaRequest;
use Modules\MapModule\Models\ManagedArea;
use Illuminate\Http\Request;

class ManagedAreaController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $query = ManagedArea::query()->where('user_id', $user->id)->latest();

            if ($request->has('id')) {
                return ApiResponse::success($query->where('id', $request->query('id'))->firstOrFail());
            }

            $perPage = $request->integer('per_page', 50);

            return ApiResponse::success($query->paginate($perPage));
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to fetch managed areas', 500, $e->getMessage());
        }
    }

    public function store(StoreManagedAreaRequest $request)
    {
        try {

            $managedArea = ManagedArea::create($request->validated(), ['user_id' => $request->user()->id,]);

            SystemLogHelper::log('managed_area.create.success', 'Managed area created', ['managed_area_id' => $managedArea->id]);

            return ApiResponse::success($managedArea, 'Managed area created successfully', 201);
        } catch (\Exception $e) {
            SystemLogHelper::log('managed_area.create.failed', 'Failed to create managed area', [
                'error' => $e->getMessage(),
            ], ['level' => 'error']);
            return ApiResponse::error('Failed to create managed area', 500, $e->getMessage());
        }
    }

    public function update(UpdateManagedAreaRequest $request, $id)
    {
        try {
            $managedArea = ManagedArea::where('id', $id)->where('user_id', $request->user()->id)->first();

            if (!$managedArea) {
                return ApiResponse::error('Managed area not found', 404);
            }

            $managedArea->update($request->validated());

            SystemLogHelper::log(
                'managed_area.update.success',
                'Managed area updated',
                ['managed_area_id' => $managedArea->id]
            );

            return ApiResponse::success($managedArea, 'Managed area updated successfully');
        } catch (\Exception $e) {
            SystemLogHelper::log(
                'managed_area.update.failed',
                'Failed to update managed area',
                [
                    'managed_area_id' => $id,
                    'error' => $e->getMessage(),
                ],
                ['level' => 'error']
            );

            return ApiResponse::error('Failed to update managed area', 500, $e->getMessage());
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $managedArea = ManagedArea::query()
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (! $managedArea) {
                return ApiResponse::error('Managed area not found', 404);
            }

            $managedArea->delete();

            SystemLogHelper::log('managed_area.delete.success', 'Managed area deleted', [
                'managed_area_id' => $id,
            ]);

            return ApiResponse::success(null, 'Managed area deleted successfully');
        } catch (\Exception $e) {
            SystemLogHelper::log('managed_area.delete.failed', 'Failed to delete managed area', [
                'managed_area_id' => $id,
                'error' => $e->getMessage(),
            ], ['level' => 'error']);
            return ApiResponse::error('Failed to delete managed area', 500, $e->getMessage());
        }
    }
}
