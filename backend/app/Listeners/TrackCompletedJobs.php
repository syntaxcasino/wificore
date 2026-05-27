<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TrackCompletedJobs
{
    /**
     * Handle the event.
     */
    public function handle(JobProcessed $event): void
    {
        try {
            // Increment completed jobs counter (30 seconds max to prevent stale data)
            $key = 'queue:completed:last_hour';
            $count = Cache::get($key, 0);
            Cache::put($key, $count + 1, 30);

            // Also track total completed jobs (30 seconds max)
            $totalKey = 'queue:completed:total';
            $total = Cache::get($totalKey, 0);
            Cache::put($totalKey, $total + 1, 30);
        } catch (\Throwable $e) {
            Log::warning('TrackCompletedJobs: cache unavailable, skipping queue metrics update', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
