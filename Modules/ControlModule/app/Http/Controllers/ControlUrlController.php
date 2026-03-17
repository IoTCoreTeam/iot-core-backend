<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreControlUrlRequest;
use Modules\ControlModule\Http\Requests\UpdateControlUrlRequest;
use Modules\ControlModule\QueryBuilders\ControlUrlQueryBuilder;
use Modules\ControlModule\Services\ControlUrlService;

class ControlUrlController extends Controller
{
    public function __construct(private readonly ControlUrlService $controlUrlService) {}

    public function index(Request $request)
    {
        return ControlUrlQueryBuilder::fromRequest($request);
    }

    public function store(StoreControlUrlRequest $request)
    {
        $payload = $request->validated();
        $result = $this->controlUrlService->create($payload);

        return ApiResponse::success($result['control_url'], $result['message'], $result['status']);
    }

    public function update(UpdateControlUrlRequest $request, string $id)
    {
        $payload = $request->validated();
        $result = $this->controlUrlService->update($id, $payload);

        return ApiResponse::success($result['control_url'], $result['message']);
    }

    public function delete(string $id)
    {
        $this->controlUrlService->delete($id);

        return ApiResponse::success(null, 'Control url deleted successfully');
    }

    public function executeControlUrl(Request $request, string $id)
    {
        $payload = $request->all();
        $result = $this->controlUrlService->execute($id, $payload);

        return ApiResponse::success($result['control_url'], $result['message'], $result['status']);
    }
}
