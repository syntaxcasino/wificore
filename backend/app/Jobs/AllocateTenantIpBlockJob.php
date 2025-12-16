<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\IpBlockAllocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AllocateTenantIpBlockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    protected $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('tenant-management');
    }

    public function handle(IpBlockAllocationService $ipBlockService): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant) {
            Log::error('Tenant not found for IP block allocation', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        // Check if IP block already allocated
        if (isset($tenant->settings['ip_block'])) {
            Log::info('Tenant already has IP block allocated', [
                'tenant_id' => $this->tenantId,
                'ip_block' => $tenant->settings['ip_block'],
            ]);
            return;
        }

        try {
            $ipBlock = $ipBlockService->allocateTenantIpBlock($tenant);

            Log::info('IP block allocated to tenant via job', [
                'tenant_id' => $this->tenantId,
                'tenant_slug' => $tenant->slug,
                'ip_block' => $ipBlock,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to allocate IP block to tenant', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AllocateTenantIpBlockJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
