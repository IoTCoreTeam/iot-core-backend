<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedArea extends Model
{
    /** @use HasFactory<\Database\Factories\ManagedAreaFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'geom_type',
        'geometry',
        'bbox',
    ];

    protected $casts = [
        'geometry' => 'array',
        'bbox' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
