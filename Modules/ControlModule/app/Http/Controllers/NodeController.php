<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreNodeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\ControlModule\QueryBuilders\NodeQueryBuilder;
use Modules\ControlModule\Services\NodeManagementService;
use Modules\ControlModule\Services\NodeService;
use Illuminate\Support\Facades\DB;

class NodeController extends Controller
{
    public function __construct(private readonly NodeService $nodeService) {}

    // device registration
    public function registation(StoreNodeRequest $request)
    {
        $payload = $request->validated();

        $result = DB::transaction(function () use ($payload) {
            $result = $this->nodeService->register($payload);
            self::sendAvailableNode();

            return $result;
        });

        return ApiResponse::success($result['node'], $result['message'], $result['status']);
    }

    public function deactivation(string $externalId)
    {
        $result = DB::transaction(function () use ($externalId) {
            $result = $this->nodeService->deactivate($externalId);
            self::sendAvailableNode();

            return $result;
        });

        return ApiResponse::success(null, $result['message']);
    }

    // node listing with filters
    public function index(Request $request)
    {
        return NodeQueryBuilder::fromRequest($request);
    }

    public function getActiveDevices(): JsonResponse // hàm này để client, server gọi lấy danh sách node đã đăng ký
    {
        return ApiResponse::success(NodeQueryBuilder::getActiveDevicesPayload(), 'Registered node resources fetched successfully');
    }

    public static function sendAvailableNode(): JsonResponse // hàm này để chủ động gửi danh sách node đang active cho server điều khiển+++
    {
        return app(NodeManagementService::class)->sendAvailableNode();
    }

}
