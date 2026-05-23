<?php

namespace App\Providers;

use App\Database\PostgresConnection;
use Illuminate\Database\Connection;
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
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new PostgresConnection($connection, $database, $prefix, $config);
        });
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

        // Log slow queries without retaining every query in Octane worker memory.
        if ((bool) env('DB_SLOW_QUERY_LOGGING', config('app.debug'))) {
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

            // Keep optional query logging isolated from connection tuning.
            $this->configureQueryLogging();
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
        // Apply session-level settings directly via PDO->exec() rather than
        // DB::statement(). DB::statement() calls recordsHaveBeenModified() which
        // sets the sticky-write flag on the connection — permanently routing ALL
        // subsequent reads to the write PDO for the entire request lifetime,
        // bypassing the read replica even for pure SELECT queries.
        $pdo = DB::connection()->getPdo();
        $pdo->exec("SET statement_timeout = '30s'");
        $pdo->exec("SET idle_in_transaction_session_timeout = '60s'");
    }

    /**
     * Optimize database queries
     */
    protected function configureQueryLogging(): void
    {
        // Do not retain every query in memory in production.
        // This can cause gradual slowdowns and intermittent 500 responses.
        if ((bool) env('DB_QUERY_LOG', false) && !app()->environment('production')) {
            DB::enableQueryLog();
        }
    }
}
