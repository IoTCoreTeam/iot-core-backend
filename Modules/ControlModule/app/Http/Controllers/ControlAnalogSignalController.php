<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreControlAnalogSignalRequest;
use Modules\ControlModule\Models\ControlAnalogSignal;
use Modules\ControlModule\Services\ControlAnalogSignalService;

class ControlAnalogSignalController extends Controller
{
    public function __construct(private readonly ControlAnalogSignalService $controlAnalogSignalService) {}
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $signals = ControlAnalogSignal::query()
            ->when($request->filled('control_url_id'), function ($query) use ($request) {
                $query->where('control_url_id', (string) $request->query('control_url_id'));
            })
            ->with(['controlUrl'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 10));

        return ApiResponse::success($signals);
    }

    public function createOrUpdate(StoreControlAnalogSignalRequest $request)
    {
        $payload = $request->validated();
        $signal = $this->controlAnalogSignalService->createOrUpdate($payload);

        return ApiResponse::success($signal, 'Control analog signal saved successfully');
    }
}
