<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    /** @use HasFactory<\Database\Factories\SystemLogFactory> */
    use HasFactory;

    protected $table = 'system_logs';

    protected $fillable = [
        'action',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}