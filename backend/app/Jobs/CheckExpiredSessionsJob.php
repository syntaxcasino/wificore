<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckExpiredSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('hotspot-sessions');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Checking for expired sessions...');
        
        // Get all active sessions that have expired
        $expiredSessions = RadiusSession::where('status', 'active')
            ->where('expected_end', '<=', now())
            ->get();
        
        if ($expiredSessions->isEmpty()) {
            Log::info('No expired sessions found');
            return;
        }
        
        Log::info('Found expired sessions', [
            'count' => $expiredSessions->count()
        ]);
        
        foreach ($expiredSessions as $session) {
            try {
                // Dispatch disconnect job for each expired session
                DisconnectHotspotUserJob::dispatch(
                    $session->id,
                    'Session time expired'
                )->onQueue('hotspot-sessions');
                
                Log::info('Dispatched disconnect job for expired session', [
                    'session_id' => $session->id,
                    'username' => $session->username,
                    'expected_end' => $session->expected_end,
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to dispatch disconnect job', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info('Finished checking expired sessions', [
            'processed' => $expiredSessions->count()
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckExpiredSessionsJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
