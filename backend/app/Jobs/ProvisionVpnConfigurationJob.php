<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnConfiguration;
use App\Services\VpnService;
use App\Events\VpnConfigurationCreated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionVpnConfigurationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $tenantId,
        public ?int $routerId = null,
        public array $options = []
    ) {
        $this->onQueue('vpn-provisioning');
    }

    /**
     * Execute the job.
     */
    public function handle(VpnService $vpnService): void
    {
        try {
            Log::info('VPN provisioning job started', [
                'tenant_id' => $this->tenantId,
                'router_id' => $this->routerId,
            ]);

            // Load tenant
            $tenant = Tenant::findOrFail($this->tenantId);

            // Load router if specified
            $router = $this->routerId ? Router::findOrFail($this->routerId) : null;

            // Create VPN configuration
            $vpnConfig = $vpnService->createVpnConfiguration(
                $tenant,
                $router,
                $this->options
            );

            // Update router with VPN IP if router exists
            if ($router) {
                $router->update([
                    'vpn_ip' => $vpnConfig->client_ip,
                    'vpn_status' => 'pending',
                ]);
            }

            // Fire event for real-time updates
            event(new VpnConfigurationCreated($vpnConfig));

            Log::info('VPN provisioning job completed', [
                'tenant_id' => $this->tenantId,
                'router_id' => $this->routerId,
                'vpn_config_id' => $vpnConfig->id,
                'client_ip' => $vpnConfig->client_ip,
            ]);

        } catch (\Exception $e) {
            Log::error('VPN provisioning job failed', [
                'tenant_id' => $this->tenantId,
                'router_id' => $this->routerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('VPN provisioning job failed permanently', [
            'tenant_id' => $this->tenantId,
            'router_id' => $this->routerId,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Notify tenant admin of failure
    }
}
