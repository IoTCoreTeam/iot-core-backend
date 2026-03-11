<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\ControlAnalogSignal;
use App\Http\Requests\StoreControlAnalogSignalRequest;
use App\Http\Requests\UpdateControlAnalogSignalRequest;
use App\Services\ControlAnalogSignalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        try {
            $signal = ControlAnalogSignal::query()
                ->where('control_url_id', $controlUrlId)
                ->first();

            return ApiResponse::success($signal);
        } catch (Throwable $e) {
            return ApiResponse::error('Failed to fetch control analog signal', 500, $e->getMessage());
        }
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
        try {
            $payload = $request->validated();
            $signal = $this->controlAnalogSignalService->createOrUpdate($payload);
            return ApiResponse::success($signal, 'Control analog signal saved successfully');
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return ApiResponse::error('Failed to save control analog signal', 500, $e->getMessage());
        }
    }
}
