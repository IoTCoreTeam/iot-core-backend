<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class ControlAnalogSignal extends Model
{
    /** @use HasFactory<\Modules\ControlModule\Database\Factories\ControlAnalogSignalFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'control_url_id',
        'min_value',
        'max_value',
        'unit',
        'signal_type',
        'resolution_bits',
    ];

    public function controlUrl()
    {
        return $this->belongsTo(ControlUrl::class);
    }

    public static function createOrUpdate(array $payload): self
    {
        $minValue = $payload['min_value'] ?? 0;

        return self::updateOrCreate(
            ['control_url_id' => $payload['control_url_id']],
            [
                'min_value' => $minValue,
                'max_value' => $payload['max_value'],
                'unit' => $payload['unit'],
                'signal_type' => $payload['signal_type'],
                'resolution_bits' => $payload['resolution_bits'],
            ],
        );
    }
}
