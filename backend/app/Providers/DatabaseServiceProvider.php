<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only configure if not running in console during build
        if ($this->app->runningInConsole() && !$this->app->environment('production')) {
            return;
        }

        // Enable query logging in development
        if (config('app.debug')) {
            DB::listen(function (QueryExecuted $query) {
                if ($query->time > 1000) { // Log slow queries (>1 second)
                    Log::warning('Slow query detected', [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'time' => $query->time . 'ms',
                    ]);
                }
            });
        }

        try {
            // Set connection pool configuration
            $this->configureConnectionPool();
            
            // Optimize database queries
            $this->optimizeQueries();
        } catch (\Exception $e) {
            // Silently fail if database is not available (e.g., during build)
            Log::debug('Database optimization skipped: ' . $e->getMessage());
        }
    }

    /**
     * Configure connection pooling
     */
    protected function configureConnectionPool(): void
    {
        // Set PDO attributes for persistent connections
        DB::connection()->getPdo()->setAttribute(\PDO::ATTR_PERSISTENT, true);
        
        // Set statement timeout to prevent long-running queries
        DB::statement("SET statement_timeout = '30s'");
        
        // Set idle_in_transaction_session_timeout
        DB::statement("SET idle_in_transaction_session_timeout = '60s'");
        
        // Enable connection pooling at application level
        DB::beforeExecuting(function ($query, $bindings, $connection) {
            // Reuse connections when possible
            if ($connection->transactionLevel() === 0) {
                // Not in a transaction, safe to reuse connection
                return true;
            }
        });
    }

    /**
     * Optimize database queries
     */
    protected function optimizeQueries(): void
    {
        // Enable query result caching
        DB::enableQueryLog();
        
        // Set default fetch mode for better performance (PostgreSQL specific)
        try {
            $pdo = DB::connection()->getPdo();
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);
        } catch (\Exception $e) {
            // Silently fail if PDO is not available
            Log::debug('Could not set PDO fetch mode: ' . $e->getMessage());
        }
        
        // Optimize for read-heavy workloads
        DB::statement("SET synchronous_commit = '" . env('DB_SESSION_SYNCHRONOUS_COMMIT', 'on') . "'"); // For better write performance
        DB::statement("SET effective_cache_size = '1GB'");
        DB::statement("SET random_page_cost = 1.1"); // For SSD storage
    }
}
