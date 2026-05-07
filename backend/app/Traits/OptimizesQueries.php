<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use App\Services\StaleSafeCacheService;

/**
 * OptimizesQueries Trait
 * Provides query optimization methods for models
 * Use in controllers or repositories for consistent query optimization
 */
trait OptimizesQueries
{
    /**
     * Cached query execution with automatic invalidation
     * 
     * @param string $cacheKey Base cache key
     * @param Builder $query Query builder instance
     * @param int $ttl Cache TTL in seconds
     * @param callable $callback Optional callback to transform results
     * @return mixed
     */
    protected function cachedQuery(string $cacheKey, Builder $query, int $ttl = 60, ?callable $callback = null): mixed
    {
        return StaleSafeCacheService::remember($cacheKey, $ttl, function () use ($query, $callback) {
            $results = $query->get();
            return $callback ? $callback($results) : $results;
        });
    }

    /**
     * Execute query with chunking for large datasets
     * Memory-efficient for large result sets
     * 
     * @param Builder $query
     * @param int $chunkSize
     * @param callable $callback
     * @return void
     */
    protected function chunkedQuery(Builder $query, int $chunkSize, callable $callback): void
    {
        $query->chunk($chunkSize, function ($items) use ($callback) {
            foreach ($items as $item) {
                $callback($item);
            }
        });
    }

    /**
     * Stream query results using cursor for memory efficiency
     * Best for: Large exports, batch processing, reports
     * 
     * @param Builder $query
     * @param callable $callback
     * @return void
     */
    protected function streamQuery(Builder $query, callable $callback): void
    {
        foreach ($query->cursor() as $item) {
            $callback($item);
        }
    }

    /**
     * Get paginated results with caching
     * Caches the page metadata separately from results
     * 
     * @param Builder $query
     * @param int $perPage
     * @param string $cachePrefix
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    protected function cachedPaginate(Builder $query, int $perPage = 25, string $cachePrefix = ''): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $page = request()->input('page', 1);
        $cacheKey = "{$cachePrefix}:page:{$page}:per_page:{$perPage}";

        return StaleSafeCacheService::remember($cacheKey, 30, function () use ($query, $perPage) {
            return $query->paginate($perPage);
        })['data'] ?? $query->paginate($perPage);
    }

    /**
     * Optimize query with eager loading and specific column selection
     * 
     * @param Builder $query
     * @param array $relations Relations to eager load
     * @param array $columns Columns to select (null for all)
     * @return Builder
     */
    protected function optimizeQuery(Builder $query, array $relations = [], ?array $columns = null): Builder
    {
        // Eager load relations to prevent N+1
        if (!empty($relations)) {
            $query->with($relations);
        }

        // Select specific columns to reduce memory usage
        if ($columns !== null) {
            $query->select($columns);
        }

        return $query;
    }

    /**
     * Batch update with minimal queries
     * Updates multiple records in a single query
     * 
     * @param string $modelClass
     * @param array $updates Array of [id => [column => value]]
     * @param string $idColumn
     * @return int Number of affected rows
     */
    protected function batchUpdate(string $modelClass, array $updates, string $idColumn = 'id'): int
    {
        $affected = 0;
        
        // Group by same values to minimize queries
        $grouped = [];
        foreach ($updates as $id => $values) {
            $key = serialize($values);
            $grouped[$key]['values'] = $values;
            $grouped[$key]['ids'][] = $id;
        }

        foreach ($grouped as $group) {
            $affected += $modelClass::whereIn($idColumn, $group['ids'])->update($group['values']);
        }

        return $affected;
    }

    /**
     * Execute raw query with result caching
     * Use for complex queries that need SQL optimization
     * 
     * @param string $sql
     * @param array $bindings
     * @param string $cacheKey
     * @param int $ttl
     * @return array
     */
    protected function cachedRawQuery(string $sql, array $bindings = [], string $cacheKey = '', int $ttl = 60): array
    {
        $key = $cacheKey ?: 'raw:' . md5($sql . serialize($bindings));
        
        return StaleSafeCacheService::remember($key, $ttl, function () use ($sql, $bindings) {
            return \DB::select($sql, $bindings);
        })['data'] ?? [];
    }

    /**
     * Clear query cache for a specific prefix
     * 
     * @param string $prefix
     * @return void
     */
    protected function clearQueryCache(string $prefix): void
    {
        StaleSafeCacheService::invalidatePattern($prefix . ':*');
    }
}
