<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScheduleRouterPollingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    /**
     * Create a new job instance.
     */
    public function __construct(string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-monitoring');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            //Log::debug("Dispatched router polling jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() {
            try {
                $routers = Router::whereIn('status', ['online', 'active'])->pluck('id')->toArray();
                
                if (!empty($routers)) {
                    // Dispatch in chunks for better performance
                    $chunks = array_chunk($routers, 10);
                    foreach ($chunks as $chunk) {
                        FetchRouterLiveData::dispatch($this->tenantId, $chunk);
                    }
                    
                    Log::debug('Scheduled live data fetch', [
                        'tenant_id' => $this->tenantId,
                        'router_count' => count($routers),
                        'chunk_count' => count($chunks)
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to schedule router polling', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage()
                ]);
            }
        });
    }
}
