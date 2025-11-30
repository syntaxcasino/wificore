<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class SystemHealthMetric extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'recorded_at',
        'db_connections',
        'db_max_connections',
        'db_response_time',
        'db_slow_queries',
        'redis_hit_rate',
        'redis_memory_used',
        'redis_memory_peak',
        'disk_total',
        'disk_available',
        'disk_used_percentage',
        'uptime_percentage',
        'uptime_duration',
        'last_restart',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'last_restart' => 'datetime',
    ];

    public static function getHistoricalData($startDate, $endDate)
    {
        return self::whereBetween('recorded_at', [$startDate, $endDate])
            ->orderBy('recorded_at', 'asc')
            ->get();
    }

    public static function getLatest()
    {
        return self::orderBy('recorded_at', 'desc')->first();
    }
}
