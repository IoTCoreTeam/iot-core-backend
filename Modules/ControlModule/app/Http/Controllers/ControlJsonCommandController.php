<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreControlJsonCommandRequest;
use Modules\ControlModule\Http\Requests\UpdateControlJsonCommandRequest;
use Modules\ControlModule\Models\ControlJsonCommand;
use Modules\ControlModule\Services\ControlJsonCommandService;

class ControlJsonCommandController extends Controller
{
    public function __construct(private readonly ControlJsonCommandService $controlJsonCommandService) {}

    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        return ControlJsonCommand::query()
            ->when($request->filled('control_url_id'), function ($query) use ($request) {
                $query->where('control_url_id', (string) $request->query('control_url_id'));
            })
            ->with([
                'controlUrl',
                'controlUrl.node:id,external_id',
                'controlUrl.node.gateway:id,external_id',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function store(StoreControlJsonCommandRequest $request)
    {
        $result = $this->controlJsonCommandService->create($request->validated());

        return ApiResponse::success($result['control_json_command'], $result['message'], $result['status']);
    }

    public function update(UpdateControlJsonCommandRequest $request, string $id)
    {
        $result = $this->controlJsonCommandService->update($id, $request->validated());

        return ApiResponse::success($result['control_json_command'], $result['message']);
    }

    public function delete(string $id)
    {
        $this->controlJsonCommandService->delete($id);

        return ApiResponse::success(null, 'Control json command deleted successfully');
    }
}
