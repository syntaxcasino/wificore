<?php

namespace App\Jobs;

use App\Models\AccessPoint;
use App\Services\AccessPointManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAccessPointStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('payment-checks'); // Low priority
    }

    /**
     * Execute the job.
     */
    public function handle(AccessPointManager $apManager): void
    {
        Log::info('SyncAccessPointStatusJob: Starting');

        try {
            // Get all access points
            $accessPoints = AccessPoint::all();

            Log::info('SyncAccessPointStatusJob: Found access points', [
                'count' => $accessPoints->count(),
            ]);

            $synced = 0;
            $failed = 0;

            foreach ($accessPoints as $ap) {
                try {
                    // Sync AP status
                    $apManager->syncAccessPointStatus($ap);

                    $synced++;

                    Log::debug('SyncAccessPointStatusJob: AP synced', [
                        'ap_id' => $ap->id,
                        'name' => $ap->name,
                        'status' => $ap->status,
                        'active_users' => $ap->active_users,
                    ]);

                } catch (\Exception $e) {
                    $failed++;

                    Log::error('SyncAccessPointStatusJob: Failed to sync AP', [
                        'ap_id' => $ap->id,
                        'name' => $ap->name,
                        'error' => $e->getMessage(),
                    ]);

                    // Mark AP as error status
                    $ap->update(['status' => AccessPoint::STATUS_ERROR]);
                }
            }

            Log::info('SyncAccessPointStatusJob: Completed', [
                'total' => $accessPoints->count(),
                'synced' => $synced,
                'failed' => $failed,
            ]);

        } catch (\Exception $e) {
            Log::error('SyncAccessPointStatusJob: Job failed', [
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
        Log::critical('SyncAccessPointStatusJob: Job failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
