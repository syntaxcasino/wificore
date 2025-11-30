<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;

class TrackCompletedJobs
{
    /**
     * Handle the event.
     */
    public function handle(JobProcessed $event): void
    {
        // Increment completed jobs counter
        $key = 'queue:completed:last_hour';
        $count = Cache::get($key, 0);
        Cache::put($key, $count + 1, now()->addHour());
        
        // Also track total completed jobs (never expires)
        $totalKey = 'queue:completed:total';
        $total = Cache::get($totalKey, 0);
        Cache::forever($totalKey, $total + 1);
    }
}
