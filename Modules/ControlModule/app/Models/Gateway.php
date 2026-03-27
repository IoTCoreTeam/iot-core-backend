<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gateway extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'external_id',
        'mac_address',
        'ip_address',
    ];

    public function nodes()
    {
        return $this->hasMany(Node::class);
    }

    public function nodeControllers()
    {
        return $this->nodes()->where('type', 'controller');
    }

    public function nodeSensors()
    {
        return $this->nodes()->where('type', 'sensor');
    }

    protected static function booted(): void
    {
        static::deleting(function (Gateway $gateway): void {
            if ($gateway->isForceDeleting()) {
                return;
            }

            $timestamp = $gateway->freshTimestampString();
            $nodeIds = $gateway->nodes()->pluck('id');

            if ($nodeIds->isEmpty()) {
                return;
            }

            $controlUrlIds = ControlUrl::whereIn('node_id', $nodeIds)->pluck('id');

            if ($controlUrlIds->isNotEmpty()) {
                ControlJsonCommand::whereIn('control_url_id', $controlUrlIds)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                ControlAnalogSignal::whereIn('control_url_id', $controlUrlIds)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                ControlUrl::whereIn('id', $controlUrlIds)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
            }

            Node::whereIn('id', $nodeIds)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        });
    }
}
