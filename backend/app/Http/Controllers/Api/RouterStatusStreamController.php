<?php

namespace App\Http\Controllers\Api;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RouterStatusStreamController extends Controller
{
    private const STREAM_TIMEOUT = 300; // 5 minutes max connection
    private const HEARTBEAT_INTERVAL = 30; // Send heartbeat every 30s

    /**
     * Server-Sent Events stream for real-time router status updates.
     * Strictly tenant-isolated - users can only see their own tenant's routers.
     * Requires authentication.
     */
    public function stream(Request $request): StreamedResponse
    {
        $user = Auth::user();
        
        // Authentication required
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }
        
        // Get tenant from authenticated user - NEVER from request params
        $tenantId = $user->tenant_id;
        
        if (!$tenantId) {
            return $this->errorResponse('No tenant assigned to user', 403);
        }
        
        // Verify tenant exists and is active
        $tenant = Tenant::where('id', $tenantId)
            ->where('is_active', true)
            ->first();
        
        if (!$tenant) {
            return $this->errorResponse('Tenant not found or inactive', 403);
        }
        
        // Authorization check - ensure user belongs to this tenant
        if (!$this->authorizeTenantAccess($user, $tenant)) {
            return $this->errorResponse('Access denied to this tenant', 403);
        }

        $lastEventId = $request->header('Last-Event-Id', 0);
        $startTime = time();
        $lastHeartbeat = time();

        return response()->stream(function () use ($tenant, $tenantId, $lastEventId, $startTime, $lastHeartbeat) {
            // Set tenant context - scoped strictly to this tenant
            $tenantContext = app(TenantContext::class);
            $tenantContext->setTenant($tenant);
            DB::statement('SET search_path TO ?, public', [$tenant->schema_name]);

            $eventId = (int) $lastEventId;
            $lastKnownStatuses = [];
            
            // Initial full state push - strictly filtered by tenant
            $this->sendFullState($eventId++, $tenant, $tenantId);

            // Stream loop
            while (true) {
                // Check max connection duration
                if (time() - $startTime > self::STREAM_TIMEOUT) {
                    $this->sendEvent('complete', $eventId++, ['message' => 'Stream timeout']);
                    break;
                }

                // Send heartbeat to keep connection alive
                if (time() - $lastHeartbeat > self::HEARTBEAT_INTERVAL) {
                    $this->sendEvent('heartbeat', $eventId++, ['time' => now()->toIso8601String()]);
                    $lastHeartbeat = time();
                }

                // Check for status changes - tenant-scoped only
                $changes = $this->getStatusChanges($tenant, $tenantId, $lastKnownStatuses);
                
                if (!empty($changes)) {
                    foreach ($changes as $change) {
                        $this->sendEvent('router.status.updated', $eventId++, $change);
                    }
                    
                    // Update last known statuses
                    foreach ($changes as $change) {
                        $lastKnownStatuses[$change['id']] = $change['status'];
                    }
                }

                // Small sleep to prevent CPU spinning (check every 1 second)
                sleep(1);
            }

            DB::statement('SET search_path TO public');
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Authorize user access to tenant data
     */
    private function authorizeTenantAccess($user, Tenant $tenant): bool
    {
        // User must belong to this tenant
        if ($user->tenant_id !== $tenant->id) {
            Log::warning('SSE unauthorized access attempt', [
                'user_id' => $user->id,
                'user_tenant' => $user->tenant_id,
                'requested_tenant' => $tenant->id,
            ]);
            return false;
        }
        
        // User must be active
        if (!$user->is_active) {
            return false;
        }
        
        return true;
    }

    /**
     * Send error response (non-streaming)
     */
    private function errorResponse(string $message, int $status): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => $message]) . "\n\n";
            ob_flush();
            flush();
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Send SSE event
     */
    private function sendEvent(string $event, int $id, array $data): void
    {
        echo "event: {$event}\n";
        echo "id: {$id}\n";
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    /**
     * Send full current state - STRICTLY filtered by tenant
     */
    private function sendFullState(int &$eventId, Tenant $tenant, string $tenantId): void
    {
        // CRITICAL: Explicit tenant filter as defense-in-depth
        // Primary isolation is PostgreSQL schema (search_path), but we double-check
        $routers = Router::select('id', 'name', 'status', 'vpn_status', 'last_seen', 'last_checked', 'ip_address')
            ->where('tenant_id', $tenantId) // EXPLICIT tenant filter
            ->whereNotIn('status', ['pending', 'deploying', 'provisioning', 'verifying'])
            ->get();

        $payload = [
            'event' => 'initial',
            'tenant_id' => $tenantId, // Include for client verification
            'routers' => $routers->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'status' => $r->status,
                'vpn_status' => $r->vpn_status,
                'last_seen' => $r->last_seen?->toIso8601String(),
                'last_checked' => $r->last_checked?->toIso8601String(),
                'ip_address' => $r->ip_address,
            ])->toArray(),
        ];

        $this->sendEvent('initial', $eventId++, $payload);
        
        Log::debug('SSE initial state sent', [
            'tenant_id' => $tenantId,
            'router_count' => count($payload['routers']),
        ]);
    }

    /**
     * Check for status changes - STRICTLY scoped to tenant
     */
    private function getStatusChanges(Tenant $tenant, string $tenantId, array $lastKnownStatuses): array
    {
        // Use tenant-scoped cache key
        $cacheKey = "router:status:changes:{$tenantId}";
        $changes = Cache::get($cacheKey, []);
        
        if (!empty($changes)) {
            // Clear the cache after reading
            Cache::forget($cacheKey);
            
            // Verify all changes belong to this tenant
            return array_filter($changes, fn($change) => $this->verifyRouterOwnership($change['id'], $tenant, $tenantId));
        }

        // Fallback: check for recent updates - with EXPLICIT tenant filter
        $recentUpdates = Router::select('id', 'name', 'status', 'vpn_status', 'last_seen', 'last_checked', 'ip_address')
            ->where('tenant_id', $tenantId) // EXPLICIT tenant filter
            ->where('updated_at', '>', now()->subSeconds(5))
            ->get();

        $actualChanges = [];
        foreach ($recentUpdates as $router) {
            $previousStatus = $lastKnownStatuses[$router->id] ?? null;
            if ($previousStatus !== $router->status) {
                $actualChanges[] = [
                    'id' => $router->id,
                    'name' => $router->name,
                    'status' => $router->status,
                    'vpn_status' => $router->vpn_status,
                    'last_seen' => $router->last_seen?->toIso8601String(),
                    'last_checked' => $router->last_checked?->toIso8601String(),
                    'ip_address' => $router->ip_address,
                    'previous_status' => $previousStatus,
                    'tenant_id' => $tenantId, // Include for verification
                ];
            }
        }

        return $actualChanges;
    }

    /**
     * Verify router belongs to current tenant with explicit check
     */
    private function verifyRouterOwnership(string $routerId, Tenant $tenant, string $tenantId): bool
    {
        // Defense in depth: Explicitly check tenant_id matches
        return Router::where('id', $routerId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }
}
