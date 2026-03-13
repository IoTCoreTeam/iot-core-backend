<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    use HasFactory, HasUuids;


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
}
