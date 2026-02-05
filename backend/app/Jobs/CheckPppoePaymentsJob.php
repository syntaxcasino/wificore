<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\TenantPaybillService;
use App\Services\TenantContext;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Check PPPoE Payments Job
 * 
 * Scheduled job that checks for payments and disconnects overdue users.
 * Runs per-tenant with proper schema isolation.
 */
class CheckPppoePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 1;
    public $timeout = 300; // 5 minutes max

    protected ?string $specificTenantId;

    /**
     * @param string|null $tenantId If null, runs for all tenants
     */
    public function __construct(?string $tenantId = null)
    {
        $this->specificTenantId = $tenantId;
        if ($tenantId) {
            $this->setTenantContext($tenantId);
        }
        $this->onQueue('billing');
    }

    public function handle(TenantContext $tenantContext): void
    {
        Log::info('CheckPppoePaymentsJob: Starting', [
            'specific_tenant_id' => $this->specificTenantId,
        ]);

        if ($this->specificTenantId) {
            $this->processForTenant($this->specificTenantId, $tenantContext);
        } else {
            $this->processAllTenants($tenantContext);
        }
    }

    /**
     * Process all active tenants
     */
    protected function processAllTenants(TenantContext $tenantContext): void
    {
        $tenants = Tenant::where('is_active', true)
            ->where('schema_created', true)
            ->get();

        Log::info('CheckPppoePaymentsJob: Processing all tenants', [
            'tenant_count' => $tenants->count(),
        ]);

        foreach ($tenants as $tenant) {
            try {
                $this->processForTenant($tenant->id, $tenantContext);
            } catch (\Exception $e) {
                Log::error('CheckPppoePaymentsJob: Tenant processing failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with next tenant
            }
        }
    }

    /**
     * Process payments for a specific tenant
     */
    protected function processForTenant(string $tenantId, TenantContext $tenantContext): void
    {
        Log::info('CheckPppoePaymentsJob: Processing tenant', [
            'tenant_id' => $tenantId,
        ]);

        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->is_active || !$tenant->schema_created) {
            Log::warning('CheckPppoePaymentsJob: Invalid tenant', [
                'tenant_id' => $tenantId,
            ]);
            return;
        }

        // Set tenant context
        $tenantContext->setTenant($tenant);

        try {
            $service = app(TenantPaybillService::class);
            $service->setTenantId($tenantId);
            $service->initialize();

            // 1. Match any unmatched transactions
            $matchResults = $service->matchUnmatchedTransactions();
            
            Log::info('CheckPppoePaymentsJob: Transaction matching complete', [
                'tenant_id' => $tenantId,
                'found' => $matchResults['transactions_found'],
                'matched' => $matchResults['transactions_matched'],
            ]);

            // 2. Check and disconnect overdue users
            $disconnectResults = $service->checkAndDisconnectOverdueUsers();
            
            Log::info('CheckPppoePaymentsJob: Overdue check complete', [
                'tenant_id' => $tenantId,
                'checked' => $disconnectResults['checked'],
                'grace_period' => $disconnectResults['grace_period'],
                'disconnected' => $disconnectResults['disconnected'],
            ]);

        } catch (\Exception $e) {
            Log::error('CheckPppoePaymentsJob: Tenant processing error', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Reset to public schema
            $tenantContext->reset();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('CheckPppoePaymentsJob: Job failed', [
            'specific_tenant_id' => $this->specificTenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
