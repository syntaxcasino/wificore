<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class QueryPerformanceServiceProvider extends ServiceProvider
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
        // Only monitor in non-production environments
        if (!app()->environment('production')) {
            DB::listen(function (QueryExecuted $query) {
                // Log slow queries (>100ms)
                if ($query->time > 100) {
                    Log::warning('Slow Query Detected', [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'time' => $query->time . 'ms',
                        'connection' => $query->connectionName,
                    ]);
                }

                // Log voucher/package queries for optimization
                if (str_contains($query->sql, 'packages') || str_contains($query->sql, 'vouchers')) {
                    Log::debug('CRUD Query Performance', [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'time' => $query->time . 'ms',
                        'connection' => $query->connectionName,
                    ]);
                }
            });
        }
    }
}
