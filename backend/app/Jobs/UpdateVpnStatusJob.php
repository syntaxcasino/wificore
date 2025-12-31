<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\WireGuardService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-checks');
    }

    /**
     * Execute the job.
     */
    public function handle(WireGuardService $wireGuardService): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched VPN status update jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() use ($wireGuardService) {
            Log::info('Updating VPN connection statuses...', ['tenant_id' => $this->tenantId]);
            
            try {
                $wireGuardService->updateAllPeerStatuses();
                
                Log::info('VPN statuses updated successfully', ['tenant_id' => $this->tenantId]);
                
            } catch (\Exception $e) {
                Log::error('Failed to update VPN statuses', [
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
        Log::error('UpdateVpnStatusJob failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
