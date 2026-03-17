<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreGatewayRequest;
use Modules\ControlModule\QueryBuilders\GatewayQueryBuilder;
use Modules\ControlModule\Services\GatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GatewayController extends Controller
{
    public function __construct(private readonly GatewayService $gatewayService) {}

    public function registation(StoreGatewayRequest $request)
    {
        $payload = $request->validated();

        $result = DB::transaction(function () use ($payload) {

            $result = $this->gatewayService->register($payload);
            NodeController::sendAvailableNode();

            return $result;
        });

        return ApiResponse::success($result['gateway'], $result['message'], $result['status']);
    }

    public function deactivation(string $externalId)
    {
        $result = DB::transaction(function () use ($externalId) {

            $result = $this->gatewayService->deactivate($externalId);
            NodeController::sendAvailableNode();

            return $result;
        });

        return ApiResponse::success(null, $result['message']);
    }

    public function index(Request $request)
    {
        return GatewayQueryBuilder::fromRequest($request);
    }

    public function delete($external_id)
    {
        DB::transaction(function () use ($external_id) {
            $this->gatewayService->deleteByExternalId($external_id);
            NodeController::sendAvailableNode();
        });

        return ApiResponse::success(null, 'Gateway soft deleted successfully');
    }
}
