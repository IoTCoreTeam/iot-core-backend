<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Node extends Model
{
    use HasFactory, HasUuids, SoftDeletes;


    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'gateway_id',
        'external_id',
        'name',
        'mac_address',
        'ip_address',
        'type',
    ];

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function controlUrls()
    {
        return $this->hasMany(ControlUrl::class);
    }

    public function managedAreas()
    {
        return $this->belongsToMany(\Modules\MapModule\Models\ManagedArea::class, 'managed_area_node');
    }

    public function scopeSearch($query, ?string $keyword)
    {
        if (! $keyword) {
            return $query;
        }

        $keyword = trim($keyword);

        return $query->where(function ($nodeQuery) use ($keyword) {
            $nodeQuery->where('name', 'like', "%{$keyword}%")
                ->orWhere('external_id', 'like', "%{$keyword}%");
        });
    }

    protected static function booted(): void
    {
        static::deleting(function (Node $node): void {
            if ($node->isForceDeleting()) {
                return;
            }

            $timestamp = $node->freshTimestampString();
            $controlUrlIds = $node->controlUrls()->pluck('id');

            if ($controlUrlIds->isEmpty()) {
                return;
            }

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
        });
    }
}
