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
        $controlUrlId = (string) $request->query('control_url_id', '');
        if ($controlUrlId === '') {
            return ApiResponse::error('control_url_id is required', 422);
        }

        $signal = ControlAnalogSignal::query()
            ->where('control_url_id', $controlUrlId)
            ->first();

        return ApiResponse::success($signal);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreControlAnalogSignalRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ControlAnalogSignal $controlAnalogSignal)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ControlAnalogSignal $controlAnalogSignal)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateControlAnalogSignalRequest $request, ControlAnalogSignal $controlAnalogSignal)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ControlAnalogSignal $controlAnalogSignal)
    {
        //
    }

    public function createOrUpdate(StoreControlAnalogSignalRequest $request)
    {
        $payload = $request->validated();
        $signal = $this->controlAnalogSignalService->createOrUpdate($payload);

        return ApiResponse::success($signal, 'Control analog signal saved successfully');
    }
}
