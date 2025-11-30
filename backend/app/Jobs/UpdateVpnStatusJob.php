<?php

namespace App\Jobs;

use App\Services\WireGuardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('router-checks');
    }

    /**
     * Execute the job.
     */
    public function handle(WireGuardService $wireGuardService): void
    {
        Log::info('Updating VPN connection statuses...');
        
        try {
            $wireGuardService->updateAllPeerStatuses();
            
            Log::info('VPN statuses updated successfully');
            
        } catch (\Exception $e) {
            Log::error('Failed to update VPN statuses', [
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateVpnStatusJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
