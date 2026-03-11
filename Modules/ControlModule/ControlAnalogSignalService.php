<?php

namespace App\Services;

use App\Helpers\SystemLogHelper;
use App\Models\ControlAnalogSignal;
use Illuminate\Validation\ValidationException;
use Throwable;

class ControlAnalogSignalService
{
    public function createOrUpdate(array $payload): ControlAnalogSignal
    {
        $minValue = $payload['min_value'] ?? 0;
        $maxValue = $payload['max_value'];

        if ($minValue > $maxValue) {
            throw ValidationException::withMessages([
                'min_value' => ['Min value must be less than or equal to max value.'],
            ]);
        }

        try {
            $signal = ControlAnalogSignal::createOrUpdate([
                'control_url_id' => $payload['control_url_id'],
                'min_value' => $minValue,
                'max_value' => $maxValue,
                'unit' => $payload['unit'],
                'signal_type' => $payload['signal_type'],
                'resolution_bits' => $payload['resolution_bits'],
            ]);

            SystemLogHelper::log('control_analog_signal.upserted', 'Control analog signal saved', [
                'control_analog_signal_id' => $signal->id,
                'control_url_id' => $signal->control_url_id,
            ]);

            return $signal;
        } catch (Throwable $e) {
            SystemLogHelper::log('control_analog_signal.upsert_failed', 'Failed to save control analog signal', [
                'control_url_id' => $payload['control_url_id'] ?? null,
                'error' => $e->getMessage(),
            ], ['level' => 'error']);

            throw $e;
        }
    }
}
