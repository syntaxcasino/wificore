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
use Illuminate\Support\Carbon;
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
                    ->select('id', 'status', 'vpn_status', 'vpn_last_handshake', 'name', 'ip_address', 'vpn_ip', 'last_seen')
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
        $inactiveThreshold = (int) config('vpn.monitoring.inactive_threshold', 190);
        
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

        // Post-deployment grace period: skip offline transition for recently-deployed routers.
        // DeployRouterServiceJob sets last_seen=now() and status=online, but the WireGuard
        // handshake record in the DB may not be synced yet. Give 3 minutes before allowing
        // this monitor to flip the router back to offline.
        $deployGracePeriodSeconds = 180;
        $recentlyDeployed = $router->last_seen
            && abs(now()->diffInSeconds(
                $router->last_seen instanceof Carbon ? $router->last_seen : Carbon::parse($router->last_seen),
                false
            )) <= $deployGracePeriodSeconds;

        // Determine current status based on handshake
        if (!$latestHandshake) {
            // If router was recently set online (e.g. just deployed), keep it online during grace period
            if ($recentlyDeployed && $router->status === 'online') {
                $newStatus = 'online';
                $newVpnStatus = 'active';
            } else {
                $newStatus = 'offline';
                $newVpnStatus = 'inactive';
            }
            $handshakeAge = null;
        } else {
            $handshakeTime = $latestHandshake instanceof Carbon ? $latestHandshake : Carbon::parse($latestHandshake);
            $handshakeAge = abs(now()->diffInSeconds($handshakeTime, false));
            $isActive = $handshakeAge <= $inactiveThreshold;
            $newStatus = $isActive ? 'online' : 'offline';
            $newVpnStatus = $isActive ? 'active' : 'inactive';
        }

        // Check for recent metrics - metrics can only CONFIRM online status, not override VPN failures
        // VPN handshake is the source of truth for router connectivity
        $hasRecentMetrics = $this->checkRecentMetricsInVM($router->id, $this->tenantId);
        
        Log::withContext($context)->debug('Router metrics check', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'has_recent_metrics' => $hasRecentMetrics,
            'new_status' => $newStatus,
            'vpn_status' => $newVpnStatus,
        ]);
        
        // Metrics can bring a router ONLINE if handshake is recent (within grace period)
        // But metrics CANNOT keep a router online if VPN handshake is stale
        // VPN tunnel is the primary connectivity mechanism
        if ($newStatus === 'offline' && !$latestHandshake && $hasRecentMetrics && $router->status === 'online') {
            // No handshake record but metrics exist - router might be using fallback connectivity
            // Allow 2-minute grace period for handshake sync issues
            $lastSeenGrace = $router->last_seen && $router->last_seen->gt(now()->subMinutes(2));
            if ($lastSeenGrace) {
                Log::withContext($context)->info('Router has metrics but no handshake record - grace period active', [
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'last_seen' => $router->last_seen->toIso8601String(),
                ]);
                $newStatus = 'online';
                $newVpnStatus = 'unknown'; // Unknown until handshake confirms
            }
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
