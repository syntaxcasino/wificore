<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthScoreSnapshot extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'health_score_snapshots';

    protected $fillable = [
        'tenant_id',
        'score',
        'grade',
        'factors',
        'signals',
        'source_event',
        'source_reference',
        'calculated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'score' => 'float',
        'factors' => 'array',
        'signals' => 'array',
        'calculated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
