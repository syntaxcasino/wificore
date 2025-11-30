<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PerformanceMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'recorded_at',
        'tps_current',
        'tps_average',
        'tps_max',
        'tps_min',
        'ops_current',
        'db_active_connections',
        'db_total_queries',
        'db_slow_queries',
        'cache_keys',
        'cache_memory_used',
        'cache_hit_rate',
        'active_sessions',
        'pending_jobs',
        'failed_jobs',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'tps_current' => 'decimal:2',
        'tps_average' => 'decimal:2',
        'tps_max' => 'decimal:2',
        'tps_min' => 'decimal:2',
        'ops_current' => 'decimal:2',
        'cache_hit_rate' => 'decimal:2',
    ];

    /**
     * Scope to get metrics within a time range
     */
    public function scopeWithinRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('recorded_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get recent metrics
     */
    public function scopeRecent($query, $minutes = 60)
    {
        return $query->where('recorded_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Scope to get metrics for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('recorded_at', $date);
    }
}
