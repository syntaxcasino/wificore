<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Centralized Cache Service
 * Provides optimized caching strategies and utilities
 * 
 * IMPORTANT: Do NOT cache real-time data such as:
 * - Queue job statistics (pending, failed, processing)
 * - Active user sessions
 * - Real-time router status
 * - Payment processing states
 * 
 * These should be fetched directly from the database for real-time accuracy.
 */
class CacheService
{
    /**
     * Cache TTL constants (in seconds)
     */
    const TTL_SHORT = 60;           // 1 minute
    const TTL_MEDIUM = 300;         // 5 minutes
    const TTL_LONG = 900;           // 15 minutes
    const TTL_HOUR = 3600;          // 1 hour
    const TTL_DAY = 86400;          // 24 hours
    const TTL_WEEK = 604800;        // 7 days

    /**
     * Cache key prefixes for organization
     */
    const PREFIX_ROUTER = 'router:';
    const PREFIX_USER = 'user:';
    const PREFIX_PACKAGE = 'package:';
    const PREFIX_PAYMENT = 'payment:';
    const PREFIX_SESSION = 'session:';
    const PREFIX_DASHBOARD = 'dashboard:';
    const PREFIX_STATS = 'stats:';

    /**
     * Remember a value in cache with automatic key generation
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Get a cached value or return default
     */
    public static function get(string $key, $default = null)
    {
        return Cache::get($key, $default);
    }

    /**
     * Store a value in cache
     */
    public static function put(string $key, $value, int $ttl): bool
    {
        return Cache::put($key, $value, $ttl);
    }

    /**
     * Forget a cached value
     */
    public static function forget(string $key): bool
    {
        return Cache::forget($key);
    }

    /**
     * Flush all cache entries matching a pattern
     */
    public static function flushPattern(string $pattern): int
    {
        try {
            $redis = Redis::connection('cache');
            $keys = $redis->keys($pattern);
            
            if (empty($keys)) {
                return 0;
            }

            return $redis->del($keys);
        } catch (\Exception $e) {
            Log::error('Failed to flush cache pattern', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        try {
            $redis = Redis::connection('cache');
            $info = $redis->info();
            
            return [
                'keys' => $redis->dbsize(),
                'memory_used' => $info['used_memory_human'] ?? 'N/A',
                'memory_peak' => $info['used_memory_peak_human'] ?? 'N/A',
                'hit_rate' => self::calculateHitRate($info),
                'uptime' => $info['uptime_in_seconds'] ?? 0,
                'connected_clients' => $info['connected_clients'] ?? 0,
                'ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get cache stats', ['error' => $e->getMessage()]);
            return [
                'keys' => 0,
                'memory_used' => 'N/A',
                'memory_peak' => 'N/A',
                'hit_rate' => 0,
                'uptime' => 0,
                'connected_clients' => 0,
                'ops_per_sec' => 0,
            ];
        }
    }

    /**
     * Calculate cache hit rate
     */
    private static function calculateHitRate(array $info): float
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 0;
        }

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Cache router data
     */
    public static function cacheRouter(int $routerId, array $data, int $ttl = self::TTL_MEDIUM): bool
    {
        return self::put(self::PREFIX_ROUTER . $routerId, $data, $ttl);
    }

    /**
     * Get cached router data
     */
    public static function getRouter(int $routerId)
    {
        return self::get(self::PREFIX_ROUTER . $routerId);
    }

    /**
     * Invalidate router cache
     */
    public static function invalidateRouter(int $routerId): bool
    {
        return self::forget(self::PREFIX_ROUTER . $routerId);
    }

    /**
     * Cache package list
     */
    public static function cachePackages(array $packages, int $ttl = self::TTL_HOUR): bool
    {
        return self::put(self::PREFIX_PACKAGE . 'list', $packages, $ttl);
    }

    /**
     * Get cached packages
     */
    public static function getPackages()
    {
        return self::get(self::PREFIX_PACKAGE . 'list');
    }

    /**
     * Invalidate package cache
     */
    public static function invalidatePackages(): bool
    {
        return self::forget(self::PREFIX_PACKAGE . 'list');
    }

    /**
     * Cache dashboard stats
     */
    public static function cacheDashboardStats(array $stats, int $ttl = self::TTL_SHORT): bool
    {
        return self::put(self::PREFIX_DASHBOARD . 'stats', $stats, $ttl);
    }

    /**
     * Get cached dashboard stats
     */
    public static function getDashboardStats()
    {
        return self::get(self::PREFIX_DASHBOARD . 'stats');
    }

    /**
     * Cache user session data
     */
    public static function cacheUserSession(string $sessionId, array $data, int $ttl = self::TTL_MEDIUM): bool
    {
        return self::put(self::PREFIX_SESSION . $sessionId, $data, $ttl);
    }

    /**
     * Get cached user session
     */
    public static function getUserSession(string $sessionId)
    {
        return self::get(self::PREFIX_SESSION . $sessionId);
    }

    /**
     * Warm up cache with frequently accessed data
     */
    public static function warmUp(): array
    {
        $warmed = [];

        try {
            // Warm up packages
            if (!self::getPackages()) {
                $packages = \App\Models\Package::all()->toArray();
                self::cachePackages($packages, self::TTL_HOUR);
                $warmed[] = 'packages';
            }

            // Warm up router list
            $routers = \App\Models\Router::select('id', 'name', 'ip_address', 'status')
                ->get()
                ->toArray();
            
            foreach ($routers as $router) {
                self::cacheRouter($router['id'], $router, self::TTL_MEDIUM);
            }
            $warmed[] = 'routers';

            Log::info('Cache warmed up successfully', ['items' => $warmed]);
        } catch (\Exception $e) {
            Log::error('Failed to warm up cache', ['error' => $e->getMessage()]);
        }

        return $warmed;
    }

    /**
     * Clear all application cache
     */
    public static function clearAll(): bool
    {
        try {
            Cache::flush();
            Log::info('All cache cleared successfully');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to clear cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get cache health status
     */
    public static function getHealth(): array
    {
        $stats = self::getStats();
        
        return [
            'status' => $stats['keys'] > 0 ? 'healthy' : 'warning',
            'message' => $stats['keys'] > 0 
                ? 'Cache is operational with ' . $stats['keys'] . ' keys'
                : 'Cache has no keys - may need warming up',
            'stats' => $stats,
        ];
    }
}
