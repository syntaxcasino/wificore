<?php

namespace App\Jobs;

use App\Events\RouterStatusUpdated;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\WireguardPeer;
use App\Services\CacheInvalidationService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Router Handshake Monitor Job - Event-Based Status Detection
 *
 * This job runs every 5-10 seconds to provide near-realtime router status updates.
 * It detects handshake changes immediately and broadcasts events.
 *
 * Unlike CheckRoutersJob which runs every minute, this job:
 * - Uses Redis for fast last-handshake tracking
 * - Detects status changes instantly (within 5 seconds)
 * - Only broadcasts when status actually changes
 * - Uses handshake-only detection (no SSH/TCP probes)
 */
class RouterHandshakeMonitorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 2;
    public $timeout = 30;

    /**
     * Redis key prefix for last handshake tracking
     */
    private const HANDSHAKE_CACHE_PREFIX = 'router:last_handshake:';
    private const STATUS_CACHE_PREFIX = 'router:status:';

    public function __construct(?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('router-monitoring');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->tenantId) {
            // Dispatch a job for each active tenant
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            return;
        }

        $this->executeInTenantContext(function() {
            $context = [
                'job' => 'RouterHandshakeMonitorJob',
                'tenant_id' => $this->tenantId,
            ];

            try {
                // Get routers that are online or offline (process all non-provisioning)
                // Metrics check will determine if offline routers should come online
                $routers = Router::whereNotIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])
                    ->select('id', 'status', 'vpn_status', 'vpn_last_handshake', 'name', 'ip_address', 'vpn_ip')
                    ->get();

                if ($routers->isEmpty()) {
                    return;
                }

                $updatedStatuses = [];

                foreach ($routers as $router) {
                    $this->checkRouterHandshake($router, $updatedStatuses, $context);
                }

                // Broadcast batch updates if any
                if (!empty($updatedStatuses)) {
                    try {
                        broadcast(new RouterStatusUpdated($updatedStatuses, (string) $this->tenantId))->toOthers();
                        
                        // Also publish to Redis for any other listeners
                        Redis::publish('router:status:changed', json_encode([
                            'tenant_id' => $this->tenantId,
                            'routers' => $updatedStatuses,
                            'timestamp' => now()->toIso8601String(),
                        ]));
                    } catch (\Exception $e) {
                        Log::withContext($context)->warning('Failed to broadcast status update', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

            } catch (Throwable $e) {
                Log::withContext($context)->error('Handshake monitor failed', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Check a single router's handshake status
     */
    private function checkRouterHandshake(Router $router, array &$updatedStatuses, array $context): void
    {
        $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 180);
        
        // Get latest handshake from WireGuard peers
        $peer = WireguardPeer::where('router_id', $router->id)
            ->orderBy('last_handshake', 'desc')
            ->select('last_handshake', 'public_key')
            ->first();

        $latestHandshake = $peer?->last_handshake;

        // Get cached last handshake for comparison
        $cacheKey = self::HANDSHAKE_CACHE_PREFIX . $router->id;
        $cachedHandshake = Cache::get($cacheKey);
        $cachedStatus = Cache::get(self::STATUS_CACHE_PREFIX . $router->id);

        // Determine current status based on handshake
        if (!$latestHandshake) {
            $newStatus = 'offline';
            $newVpnStatus = 'inactive';
            $handshakeAge = null;
        } else {
            $handshakeAge = abs(now()->diffInSeconds($latestHandshake, false));
            $isActive = $handshakeAge <= $inactiveThreshold;
            $newStatus = $isActive ? 'online' : 'offline';
            $newVpnStatus = $isActive ? 'active' : 'inactive';
        }

        // METRICS-BASED OVERRIDE: If router has recent metrics in VictoriaMetrics, preserve online status
        // even if VPN handshake is stale. Metrics prove the router is responding via SNMP/Telegraf.
        $hasRecentMetrics = $this->checkRecentMetricsInVM($router->id, $this->tenantId);
        
        Log::withContext($context)->debug('Router metrics check', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'has_recent_metrics' => $hasRecentMetrics,
            'new_status_before_override' => $newStatus,
        ]);
        
        if ($newStatus === 'offline' && $hasRecentMetrics) {
            // Router has recent metrics but stale handshake - trust metrics
            Log::withContext($context)->info('Router has recent VM metrics, preserving online status despite stale handshake', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'handshake_age' => $handshakeAge,
                'current_db_status' => $router->status,
            ]);
            
            // Preserve online status but update VPN status to reflect reality
            $newStatus = 'online';
            // Keep newVpnStatus as determined by handshake (likely 'inactive')
        }

        // Check if status changed
        $handshakeChanged = $cachedHandshake !== ($latestHandshake?->getTimestamp() ?? null);
        $statusChanged = $cachedStatus !== $newStatus;
        
        // Update cache (30 seconds max to prevent stale data)
        Cache::put($cacheKey, $latestHandshake?->getTimestamp(), 30);
        Cache::put(self::STATUS_CACHE_PREFIX . $router->id, $newStatus, 30);

        // Only update and broadcast if something changed
        if ($statusChanged || $handshakeChanged) {
            $updateData = [
                'status' => $newStatus,
                'vpn_status' => $newVpnStatus,
                'last_checked' => now(),
            ];

            if ($newStatus === 'online') {
                $updateData['last_seen'] = now();
            }

            if ($handshakeChanged) {
                $updateData['vpn_last_handshake'] = $latestHandshake;
            }

            $router->update($updateData);

            // Invalidate cache
            CacheInvalidationService::invalidateRouterCache((string) $this->tenantId, (string) $router->id);

            $payload = [
                'id' => $router->id,
                'name' => $router->name,
                'ip_address' => $router->ip_address,
                'vpn_ip' => $router->vpn_ip,
                'status' => $newStatus,
                'previous_status' => $cachedStatus ?? $router->getOriginal('status'),
                'vpn_status' => $newVpnStatus,
                'last_checked' => now(),
                'last_seen' => $newStatus === 'online' ? now() : $router->last_seen,
                'handshake_age_seconds' => $handshakeAge,
                'handshake_changed' => $handshakeChanged,
                'tenant_id' => (string) $this->tenantId,
            ];

            $updatedStatuses[] = $payload;

            Log::withContext($context)->info('Router status changed', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'old_status' => $cachedStatus ?? 'unknown',
                'new_status' => $newStatus,
                'handshake_age' => $handshakeAge,
            ]);
        }
    }

    /**
     * Check if router has recent metrics in VictoriaMetrics (within last 1 minute)
     */
    private function checkRecentMetricsInVM(string $routerId, string $tenantId): bool
    {
        try {
            $vmEndpoint = config('services.victoriametrics.endpoint', 'http://wificore-victoriametrics:8428');
            
            // Use query_range to get metrics from last 1 minute
            $endTime = time();
            $startTime = $endTime - 60;
            $query = "router_health_uptime_ticks{tenant_id=\"{$tenantId}\",router_id=\"{$routerId}\"}";
            $url = "{$vmEndpoint}/api/v1/query_range?query=" . urlencode($query) . "&start={$startTime}&end={$endTime}&step=15s";
            
            $response = Http::timeout(5)->get($url);
            
            if (!$response->successful()) {
                return false;
            }
            
            $data = $response->json();
            $results = $data['data']['result'] ?? [];
            
            // If we have any values in the time range, metrics are recent
            foreach ($results as $result) {
                $values = $result['values'] ?? [];
                if (!empty($values)) {
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            // On error, assume no metrics (fail safe)
            return false;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Router handshake monitor failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
