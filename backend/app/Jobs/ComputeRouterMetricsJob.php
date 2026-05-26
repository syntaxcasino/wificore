<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ComputeRouterMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $deleteWhenMissingModels = true;

    public ?array $routerIds;
    public array $timeRanges;

    /**
     * Create a new job instance.
     *
     * @param string|null $tenantId - If null, processes all active tenants.
     * @param array|null $routerIds - If null, processes all routers for the tenant.
     * @param array $timeRanges - Time ranges to compute.
     */
    public function __construct(
        ?string $tenantId = null,
        ?array $routerIds = null,
        array $timeRanges = ['15m', '1h', '6h', '24h']
    ) {
        $this->tenantId = $tenantId;
        $this->routerIds = $routerIds;
        $this->timeRanges = $timeRanges;
        $this->onQueue('metrics');
    }

    /**
     * Execute the job.
     */
    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        if (! $this->tenantId) {
            $tenants = Tenant::query()->where('is_active', true)->get();
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id, null, $this->timeRanges);
            }

            Log::info('Dispatched router metrics computation for all tenants', [
                'tenant_count' => $tenants->count(),
            ]);

            return;
        }

        $this->executeInTenantContext(function () use ($provisioningClient): void {
            try {
                $routerQuery = Router::query()->whereNotIn('status', ['pending', 'deploying', 'provisioning']);
                if ($this->routerIds) {
                    $routerQuery->whereIn('id', $this->routerIds);
                }

                $routers = $routerQuery->get(['id']);
                if ($routers->isEmpty()) {
                    Log::debug('No routers to compute metrics for', [
                        'tenant_id' => $this->tenantId,
                    ]);
                    return;
                }

                $routerPayload = $routers->map(static fn (Router $router) => [
                    'router_id' => (string) $router->id,
                ])->values()->all();

                $timeRanges = $this->sanitizeTimeRanges($this->timeRanges);
                if ($timeRanges === []) {
                    Log::warning('No valid time ranges supplied for router metrics computation', [
                        'tenant_id' => $this->tenantId,
                        'requested_ranges' => $this->timeRanges,
                    ]);
                    return;
                }

                $response = $provisioningClient->submitRouterMetricsCommand($this->tenantId, [
                    'monitoring' => [
                        'routers' => $routerPayload,
                    ],
                    'metrics' => [
                        'time_ranges' => $timeRanges,
                    ],
                ]);

                Log::info('Submitted router metrics computation command', [
                    'tenant_id' => $this->tenantId,
                    'router_count' => count($routerPayload),
                    'time_ranges' => $timeRanges,
                    'message' => $response['message'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('Router metrics submission failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }

    private function sanitizeTimeRanges(array $timeRanges): array
    {
        $allowed = '/^\d+[mhd]$/';
        $seen = [];
        $ranges = [];

        foreach ($timeRanges as $timeRange) {
            $normalized = strtolower(trim((string) $timeRange));
            if ($normalized === '' || ! preg_match($allowed, $normalized) || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $ranges[] = $normalized;
        }

        return $ranges;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ComputeRouterMetricsJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
