<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Query Optimization Service
 *
 * Provides caching and optimization helpers for database queries
 * to reduce load and improve response times.
 */
class QueryOptimizationService
{
    /**
     * Cache key prefix for query results
     */
    private const CACHE_PREFIX = 'query_opt:';

    /**
     * Default cache TTL in seconds
     */
    private const DEFAULT_TTL = 60;

    /**
     * Execute a query with caching
     *
     * @param string $cacheKey Unique cache key
     * @param callable $queryCallback Callback that returns the query result
     * @param int $ttl Cache TTL in seconds
     * @param bool $useTags Whether to use cache tags (requires Redis)
     * @return mixed
     */
    public static function remember(string $cacheKey, callable $queryCallback, int $ttl = self::DEFAULT_TTL, bool $useTags = false): mixed
    {
        $fullKey = self::CACHE_PREFIX . $cacheKey;

        // Try to get from cache
        if ($useTags && config('cache.default') === 'redis') {
            $result = Cache::tags(['queries', 'query_' . $cacheKey])->remember($fullKey, $ttl, $queryCallback);
        } else {
            $result = Cache::remember($fullKey, $ttl, $queryCallback);
        }

        return $result;
    }

    /**
     * Forget a cached query result
     *
     * @param string $cacheKey Cache key to forget
     */
    public static function forget(string $cacheKey): void
    {
        $fullKey = self::CACHE_PREFIX . $cacheKey;
        Cache::forget($fullKey);
    }

    /**
     * Flush all query optimization cache
     */
    public static function flush(): void
    {
        if (config('cache.default') === 'redis') {
            Cache::tags(['queries'])->flush();
        } else {
            // For non-Redis caches, we can't easily flush by prefix
            Log::info('Query optimization cache flush requested but not supported for non-Redis cache');
        }
    }

    /**
     * Optimize a query builder with common eager loads
     *
     * @param Builder $query The query builder
     * @param array $relations Relations to eager load
     * @param array $counts Relations to count
     * @return Builder
     */
    public static function withOptimizedRelations(Builder $query, array $relations = [], array $counts = []): Builder
    {
        // Eager load relations to prevent N+1
        if (!empty($relations)) {
            $query->with($relations);
        }

        // Eager load counts
        if (!empty($counts)) {
            $query->withCount($counts);
        }

        return $query;
    }

    /**
     * Get cache statistics for monitoring
     *
     * @return array
     */
    public static function getStats(): array
    {
        return [
            'cache_driver' => config('cache.default'),
            'default_ttl' => self::DEFAULT_TTL,
            'prefix' => self::CACHE_PREFIX,
        ];
    }

    /**
     * Generate a cache key from query parameters
     *
     * @param string $baseKey Base key name
     * @param array $params Parameters to include in key
     * @return string
     */
    public static function generateCacheKey(string $baseKey, array $params = []): string
    {
        if (empty($params)) {
            return $baseKey;
        }

        // Sort params to ensure consistent keys
        ksort($params);
        $paramString = md5(serialize($params));

        return "{$baseKey}:{$paramString}";
    }
}
