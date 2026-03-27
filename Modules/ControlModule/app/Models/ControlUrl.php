<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ControlModule\Models\ControlAnalogSignal;
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
    ];

    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    public function analogSignal()
    {
        return $this->hasOne(ControlAnalogSignal::class);
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

            $controlUrl->analogSignal()
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        });
    }
}
