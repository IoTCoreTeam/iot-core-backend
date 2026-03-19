<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreControlAnalogSignalRequest;
use Modules\ControlModule\Http\Requests\UpdateControlAnalogSignalRequest;
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
        if($request->has('control_url_id')) {
            $signal = ControlAnalogSignal::query()->where('control_url_id', $request->query('control_url_id'))->first();
        }
        return ApiResponse::success($signal);
    }

    public function createOrUpdate(StoreControlAnalogSignalRequest $request)
    {
        $payload = $request->validated();
        $signal = $this->controlAnalogSignalService->createOrUpdate($payload);

        return ApiResponse::success($signal, 'Control analog signal saved successfully');
    }
}
