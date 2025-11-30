<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class QueueMetric extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'recorded_at',
        'pending_jobs',
        'processing_jobs',
        'failed_jobs',
        'completed_jobs',
        'active_workers',
        'workers_by_queue',
        'pending_by_queue',
        'failed_by_queue',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'workers_by_queue' => 'array',
        'pending_by_queue' => 'array',
        'failed_by_queue' => 'array',
    ];

    /**
     * Get metrics for a specific time range
     */
    public static function getHistoricalData($startDate, $endDate)
    {
        return self::whereBetween('recorded_at', [$startDate, $endDate])
            ->orderBy('recorded_at', 'asc')
            ->get();
    }

    /**
     * Get latest metric
     */
    public static function getLatest()
    {
        return self::orderBy('recorded_at', 'desc')->first();
    }
}
