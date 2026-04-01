<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ControlModule\Models\ControlJsonCommand;

class ControlUrl extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'controller_id',
        'node_id',
        'name',
        'url',
        'input_type',
        'min_value',
        'max_value',
        'unit',
        'signal_type',
        'resolution_bits',
    ];

    protected $casts = [
        'min_value' => 'decimal:4',
        'max_value' => 'decimal:4',
        'resolution_bits' => 'integer',
    ];

    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    public function jsonCommand()
    {
        return $this->hasOne(ControlJsonCommand::class);
    }

    public function jsonCommands()
    {
        return $this->hasMany(ControlJsonCommand::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (ControlUrl $controlUrl): void {
            if ($controlUrl->isForceDeleting()) {
                return;
            }

            $timestamp = $controlUrl->freshTimestampString();

            $controlUrl->jsonCommands()
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        });
    }

    /**
     * Legacy API shape compatibility for clients expecting nested analog_signal.
     *
     * @return array<string, mixed>|null
     */
    public function getAnalogSignalAttribute(): ?array
    {
        return $this->toAnalogSignalPayload();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toAnalogSignalPayload(): ?array
    {
        if ($this->min_value === null && $this->max_value === null && $this->unit === null && $this->signal_type === null && $this->resolution_bits === null) {
            return null;
        }

        return [
            'id' => (string) $this->id,
            'control_url_id' => (string) $this->id,
            'min_value' => $this->min_value,
            'max_value' => $this->max_value,
            'unit' => $this->unit,
            'signal_type' => $this->signal_type,
            'resolution_bits' => $this->resolution_bits,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
