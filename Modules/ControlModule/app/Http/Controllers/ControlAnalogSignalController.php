<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreControlAnalogSignalRequest;
use Modules\ControlModule\Models\ControlUrl;
use Modules\ControlModule\Services\ControlAnalogSignalService;

class ControlAnalogSignalController extends Controller
{
    public function __construct(private readonly ControlAnalogSignalService $controlAnalogSignalService) {}
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $signals = ControlUrl::query()
            ->whereRaw("LOWER(COALESCE(input_type, '')) LIKE '%analog%'")
            ->whereNotNull('max_value')
            ->when($request->filled('control_url_id'), function ($query) use ($request) {
                $query->where('id', (string) $request->query('control_url_id'));
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 10))
            ->through(function (ControlUrl $controlUrl): array {
                return $controlUrl->analog_signal ?? [
                    'id' => (string) $controlUrl->id,
                    'control_url_id' => (string) $controlUrl->id,
                    'min_value' => $controlUrl->min_value,
                    'max_value' => $controlUrl->max_value,
                    'unit' => $controlUrl->unit,
                    'signal_type' => $controlUrl->signal_type,
                    'resolution_bits' => $controlUrl->resolution_bits,
                    'created_at' => $controlUrl->created_at,
                    'updated_at' => $controlUrl->updated_at,
                    'deleted_at' => $controlUrl->deleted_at,
                ];
            });

        return ApiResponse::success($signals);
    }

    public function createOrUpdate(StoreControlAnalogSignalRequest $request)
    {
        $payload = $request->validated();
        $signal = $this->controlAnalogSignalService->createOrUpdate($payload);

        return ApiResponse::success($signal, 'Control analog signal saved successfully');
    }
}
