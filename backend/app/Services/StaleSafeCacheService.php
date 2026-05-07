<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;

/**
 * Stale-Safe Cache Service
 * Prevents serving stale data through versioning and event-driven invalidation
 * 
 * Strategy:
 * 1. Version-based cache keys - increment version on data change
 * 2. Event-driven invalidation - clear related caches on model events
 * 3. Short TTL for real-time data - balance performance vs freshness
 * 4. Cache tags for grouped invalidation (Redis only)
 */
class StaleSafeCacheService
{
    /**
     * Cache version key suffix
     */
    private const VERSION_SUFFIX = ':version';
    private const TIMESTAMP_SUFFIX = ':timestamp';

    /**
     * Cache TTLs based on data volatility
     */
    const TTL_REALTIME = 15;      // Real-time data (active sessions, live stats)
    const TTL_FREQUENT = 60;      // Frequently changing data (user lists)
    const TTL_STANDARD = 300;     // Standard data (packages, settings)
    const TTL_STABLE = 3600;      // Stable data (reference tables)

    /**
     * Get a value from cache with version check
     * Returns null if cache is stale (version mismatch)
     */
    public static function get(string $baseKey, ?string $expectedVersion = null): mixed
    {
        $version = self::getVersion($baseKey);
        
        // If version specified and doesn't match, data is stale
        if ($expectedVersion !== null && $version !== $expectedVersion) {
            return null;
        }

        $cacheKey = self::buildVersionedKey($baseKey, $version);
        $data = Cache::get($cacheKey);

        // Store version with data for client-side freshness checks
        if (is_array($data)) {
            $data['_cache_meta'] = [
                'version' => $version,
                'timestamp' => self::getTimestamp($baseKey),
                'key' => $baseKey,
            ];
        }

        return $data;
    }

    /**
     * Store a value in cache with versioning
     */
    public static function put(string $baseKey, mixed $value, int $ttl = self::TTL_STANDARD): bool
    {
        // Increment version before storing
        $version = self::incrementVersion($baseKey);
        $cacheKey = self::buildVersionedKey($baseKey, $version);
        
        // Store timestamp for freshness tracking
        self::setTimestamp($baseKey);

        return Cache::put($cacheKey, $value, $ttl);
    }

    /**
     * Remember a value with automatic versioning
     */
    public static function remember(string $baseKey, int $ttl, callable $callback): array
    {
        $version = self::getVersion($baseKey);
        $cacheKey = self::buildVersionedKey($baseKey, $version);

        $data = Cache::get($cacheKey);

        if ($data === null) {
            // Data not cached or expired, regenerate
            $data = $callback();
            
            // Increment version and store
            $version = self::incrementVersion($baseKey);
            $cacheKey = self::buildVersionedKey($baseKey, $version);
            self::setTimestamp($baseKey);
            Cache::put($cacheKey, $data, $ttl);
        }

        // Return data with metadata for freshness checks
        return [
            'data' => $data,
            '_cache_meta' => [
                'version' => $version,
                'timestamp' => self::getTimestamp($baseKey),
                'key' => $baseKey,
            ],
        ];
    }

    /**
     * Invalidate cache by incrementing version
     * This marks all existing cached data as stale without immediate deletion
     */
    public static function invalidate(string $baseKey): string
    {
        $newVersion = self::incrementVersion($baseKey);
        
        Log::debug('Cache invalidated', [
            'key' => $baseKey,
            'new_version' => $newVersion,
        ]);

        return $newVersion;
    }

    /**
     * Invalidate multiple related caches by pattern
     */
    public static function invalidatePattern(string $pattern): int
    {
        try {
            $redis = Redis::connection('cache');
            $keys = $redis->keys('cache:' . $pattern);
            
            if (empty($keys)) {
                return 0;
            }

            // Extract base keys and increment their versions
            $baseKeys = [];
            foreach ($keys as $key) {
                // Remove 'cache:' prefix and extract base key
                $key = str_replace('cache:', '', $key);
                // Remove version from key to get base
                $parts = explode(':', $key);
                if (count($parts) > 1 && strlen(end($parts)) === 16) {
                    // Last part looks like a version (16 char hex)
                    array_pop($parts);
                }
                $baseKey = implode(':', $parts);
                $baseKeys[$baseKey] = true;
            }

            // Increment version for each unique base key
            foreach (array_keys($baseKeys) as $baseKey) {
                self::invalidate($baseKey);
            }

            return count($baseKeys);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate cache pattern', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Check if cached data is fresh based on version/timestamp
     */
    public static function isFresh(string $baseKey, string $clientVersion, ?string $clientTimestamp = null): bool
    {
        $currentVersion = self::getVersion($baseKey);
        
        if ($currentVersion !== $clientVersion) {
            return false;
        }

        if ($clientTimestamp !== null) {
            $serverTimestamp = self::getTimestamp($baseKey);
            return $serverTimestamp === $clientTimestamp;
        }

        return true;
    }

    /**
     * Get cache metadata for client-side freshness checks
     */
    public static function getMetadata(string $baseKey): array
    {
        return [
            'version' => self::getVersion($baseKey),
            'timestamp' => self::getTimestamp($baseKey),
            'key' => $baseKey,
        ];
    }

    /**
     * Get current version for a key
     */
    private static function getVersion(string $baseKey): string
    {
        $versionKey = $baseKey . self::VERSION_SUFFIX;
        $version = Cache::get($versionKey);
        
        if ($version === null) {
            $version = self::generateVersion();
            Cache::put($versionKey, $version, 86400 * 30); // 30 days
        }

        return $version;
    }

    /**
     * Increment version for a key
     */
    private static function incrementVersion(string $baseKey): string
    {
        $versionKey = $baseKey . self::VERSION_SUFFIX;
        $newVersion = self::generateVersion();
        Cache::put($versionKey, $newVersion, 86400 * 30);
        return $newVersion;
    }

    /**
     * Generate a unique version string
     */
    private static function generateVersion(): string
    {
        return bin2hex(random_bytes(8)); // 16 character hex string
    }

    /**
     * Get timestamp for a key
     */
    private static function getTimestamp(string $baseKey): string
    {
        $timestampKey = $baseKey . self::TIMESTAMP_SUFFIX;
        return Cache::get($timestampKey, now()->toIso8601String());
    }

    /**
     * Set timestamp for a key
     */
    private static function setTimestamp(string $baseKey): void
    {
        $timestampKey = $baseKey . self::TIMESTAMP_SUFFIX;
        Cache::put($timestampKey, now()->toIso8601String(), 86400 * 30);
    }

    /**
     * Build versioned cache key
     */
    private static function buildVersionedKey(string $baseKey, string $version): string
    {
        return $baseKey . ':' . $version;
    }

    /**
     * Cache dashboard stats with short TTL for real-time accuracy
     */
    public static function cacheDashboardStats(string $tenantId, array $stats): bool
    {
        $key = "dashboard:stats:{$tenantId}";
        return self::put($key, $stats, self::TTL_REALTIME);
    }

    /**
     * Get cached dashboard stats
     */
    public static function getDashboardStats(string $tenantId): ?array
    {
        $key = "dashboard:stats:{$tenantId}";
        return self::get($key);
    }

    /**
     * Invalidate dashboard stats on data changes
     */
    public static function invalidateDashboard(string $tenantId): void
    {
        $key = "dashboard:stats:{$tenantId}";
        self::invalidate($key);
        
        // Also invalidate related stats
        self::invalidatePattern("tenant:{$tenantId}:stats:*");
    }

    /**
     * Cache paginated list with versioning
     */
    public static function cachePaginatedList(string $type, string $tenantId, array $params, array $data): bool
    {
        // Sort params to ensure consistent keys
        ksort($params);
        $paramHash = md5(json_encode($params));
        $key = "list:{$type}:{$tenantId}:{$paramHash}";
        
        return self::put($key, $data, self::TTL_FREQUENT);
    }

    /**
     * Get cached paginated list
     */
    public static function getPaginatedList(string $type, string $tenantId, array $params): ?array
    {
        ksort($params);
        $paramHash = md5(json_encode($params));
        $key = "list:{$type}:{$tenantId}:{$paramHash}";
        
        return self::get($key);
    }

    /**
     * Invalidate all list caches for a type/tenant
     */
    public static function invalidateLists(string $type, string $tenantId): int
    {
        return self::invalidatePattern("list:{$type}:{$tenantId}:*");
    }
}
