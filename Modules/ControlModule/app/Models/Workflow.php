<?php

namespace Modules\ControlModule\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    /** @use HasFactory<\Modules\ControlModule\Database\Factories\WorkflowFactory> */
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'definition',
        'control_definition',
        'status',
    ];

    protected $casts = [
        'definition' => 'array',
        'control_definition' => 'array',
    ];
}
