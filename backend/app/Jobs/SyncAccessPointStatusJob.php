<?php

namespace App\Jobs;

use App\Models\AccessPoint;
use App\Models\Tenant;
use App\Services\AccessPointManager;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAccessPointStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('payment-checks'); // Low priority
    }

    /**
     * Execute the job.
     */
    public function handle(AccessPointManager $apManager): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched access point sync jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() use ($apManager) {
            Log::info('SyncAccessPointStatusJob: Starting', ['tenant_id' => $this->tenantId]);

            try {
                // Get all access points (tenant-scoped by schema)
                $accessPoints = AccessPoint::all();

                Log::info('SyncAccessPointStatusJob: Found access points', [
                    'tenant_id' => $this->tenantId,
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
                    'tenant_id' => $this->tenantId,
                    'total' => $accessPoints->count(),
                    'synced' => $synced,
                    'failed' => $failed,
                ]);

            } catch (\Exception $e) {
                Log::error('SyncAccessPointStatusJob: Job failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('SyncAccessPointStatusJob: Job failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
