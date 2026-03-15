<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\ControlModule\Models\ControlAnalogSignal;

class ControlUrl extends Model
{
    use HasFactory, HasUuids;

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
}
