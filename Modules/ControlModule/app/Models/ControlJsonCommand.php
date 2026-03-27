<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ControlModule\Models\ControlAnalogSignal;
use Modules\ControlModule\Models\ControlUrl;

class ControlJsonCommand extends Model
{
    /** @use HasFactory<\Modules\ControlModule\Database\Factories\ControlJsonCommandFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'control_url_id',
        'name',
        'command',
    ];

    protected $casts = [
        'command' => 'array',
    ];

    public function controlUrl()
    {
        return $this->belongsTo(ControlUrl::class);
    }

    public function analogSignal()
    {
        return $this->hasOne(ControlAnalogSignal::class, 'control_url_id', 'control_url_id');
    }
}
