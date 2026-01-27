<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache Invalidation Service
 * Handles automatic cache invalidation to prevent stale data issues
 */
class CacheInvalidationService
{
    /**
     * Invalidate router-related caches when router data changes
     */
    public static function invalidateRouterCache(string $tenantId, string $routerId): void
    {
        try {
            // Invalidate specific router cache
            CacheService::invalidateRouter($tenantId, $routerId);
            
            // Invalidate router list cache for the tenant
            $listKey = CacheService::PREFIX_ROUTER . $tenantId . ':list';
            Cache::forget($listKey);

            Cache::forget("tenant_{$tenantId}_dashboard_stats");
            
            // Invalidate any live data cache (if exists)
            $liveKey = CacheService::PREFIX_ROUTER . $tenantId . ':' . $routerId . ':live';
            Cache::forget($liveKey);

            Cache::forget("router_live_data_{$routerId}");
            Cache::forget("router_live_fetch_{$routerId}");
            
            Log::debug('Router cache invalidated', [
                'tenant_id' => $tenantId,
                'router_id' => $routerId
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate router cache', [
                'tenant_id' => $tenantId,
                'router_id' => $routerId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Invalidate dashboard stats cache
     */
    public static function invalidateDashboardCache(string $tenantId): void
    {
        try {
            $key = CacheService::PREFIX_DASHBOARD . $tenantId . ':stats';
            Cache::forget($key);
            
            Log::debug('Dashboard cache invalidated', ['tenant_id' => $tenantId]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate dashboard cache', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Invalidate all tenant-related caches
     */
    public static function invalidateTenantCache(string $tenantId): void
    {
        try {
            // Invalidate all router caches for tenant
            CacheService::invalidateTenantRouters($tenantId);
            
            // Invalidate dashboard cache
            self::invalidateDashboardCache($tenantId);
            
            // Invalidate any user session caches
            $sessionPattern = CacheService::PREFIX_SESSION . $tenantId . ':*';
            CacheService::flushPattern($sessionPattern);
            
            Log::info('All tenant caches invalidated', ['tenant_id' => $tenantId]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate tenant cache', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Invalidate queue metrics cache to force fresh data
     */
    public static function invalidateQueueMetrics(): void
    {
        try {
            Cache::forget('metrics:queue:latest');
            Cache::forget('queue:completed:last_hour');
            
            Log::debug('Queue metrics cache invalidated');
        } catch (\Exception $e) {
            Log::error('Failed to invalidate queue metrics cache', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
