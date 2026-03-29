<?php

namespace Modules\ControlModule\Services;

use Illuminate\Validation\ValidationException;
use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\ControlUrl;
use Throwable;

class ControlAnalogSignalService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrUpdate(array $payload): array
    {
        $minValue = $payload['min_value'] ?? 0;
        $maxValue = $payload['max_value'];

        if ($minValue > $maxValue) {
            throw ValidationException::withMessages([
                'min_value' => ['Min value must be less than or equal to max value.'],
            ]);
        }

        try {
            $controlUrl = ControlUrl::findOrFail((string) $payload['control_url_id']);
            $controlUrl->update([
                'min_value' => $minValue,
                'max_value' => $maxValue,
                'unit' => $payload['unit'],
                'signal_type' => $payload['signal_type'],
                'resolution_bits' => $payload['resolution_bits'],
            ]);
            $controlUrl->refresh();

            SystemLogHelper::log('control_analog_signal.upserted', 'Control analog signal saved', [
                'control_analog_signal_id' => $controlUrl->id,
                'control_url_id' => $controlUrl->id,
            ]);

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
        } catch (Throwable $e) {
            SystemLogHelper::log('control_analog_signal.upsert_failed', 'Failed to save control analog signal', [
                'control_url_id' => $payload['control_url_id'] ?? null,
                'error' => $e->getMessage(),
            ], ['level' => 'error']);

            throw $e;
        }
    }
}
