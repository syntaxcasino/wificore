<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckRoutersJob;
use App\Jobs\FetchRouterLiveData;
use App\Jobs\RotateLogs;
use App\Jobs\UpdateDashboardStatsJob;
use App\Jobs\CheckExpiredSessionsJob;
use App\Jobs\SyncRadiusAccountingJob;
use App\Jobs\UpdateVpnStatusJob;
use App\Models\Router;
// NEW: Automated Service Management Jobs
use App\Jobs\CheckExpiredSubscriptionsJob;
use App\Jobs\SendPaymentRemindersJob;
use App\Jobs\ProcessGracePeriodJob;
use App\Jobs\SyncAccessPointStatusJob;
use App\Jobs\ProcessScheduledPackages;
// Security Jobs
use App\Jobs\UnsuspendExpiredAccountsJob;

// =============================================================================
// PARTITION MAINTENANCE - Automated partition management without pg_cron
// =============================================================================

// Run partition maintenance daily at 2 AM (creates new partitions, drops old ones)
Schedule::command('partitions:maintain')
    ->dailyAt('02:00')
    ->name('partition-maintenance')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::job(new CheckRoutersJob)->everyMinute();

// Fetch live router data every 30 seconds for real-time updates
Schedule::call(function () {
    $routers = Router::whereIn('status', ['online', 'active'])->pluck('id')->toArray();
    if (!empty($routers)) {
        // Dispatch in chunks for better performance
        $chunks = array_chunk($routers, 10);
        foreach ($chunks as $chunk) {
            FetchRouterLiveData::dispatch($chunk)->onQueue('router-data');
        }
    }
})->everyThirtySeconds()->name('fetch-router-live-data')->withoutOverlapping();

// Update dashboard statistics every 5 seconds for near real-time data (per-tenant)
Schedule::call(function () {
    // Get all active tenants
    $tenants = \App\Models\Tenant::whereNull('deleted_at')->pluck('id');
    
    foreach ($tenants as $tenantId) {
        UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');
    }
})->everyFiveSeconds()->name('update-dashboard-stats')->withoutOverlapping();

// Schedule the RotateLogs job to run daily at 1:00 AM
//Schedule::job(new RotateLogs)->dailyAt('01:00');
Schedule::job(new RotateLogs)->everyMinute();

// =============================================================================
// HOTSPOT BILLING SYSTEM SCHEDULED JOBS
// =============================================================================

// Check for expired hotspot sessions every minute
Schedule::job(new CheckExpiredSessionsJob)
    ->everyMinute()
    ->onOneServer();

// Sync RADIUS accounting data every 5 minutes
Schedule::job(new SyncRadiusAccountingJob)
    ->everyFiveMinutes()
    ->onOneServer();

// Update VPN connection statuses every 2 minutes
Schedule::job(new UpdateVpnStatusJob)
    ->everyTwoMinutes()
    ->onOneServer();

// =============================================================================
// SECURITY - ACCOUNT MANAGEMENT
// =============================================================================

// Unsuspend accounts where suspension period has expired (every 5 minutes)
Schedule::job(new UnsuspendExpiredAccountsJob)
    ->everyFiveMinutes()
    ->name('unsuspend-expired-accounts')
    ->withoutOverlapping()
    ->onOneServer();

// =============================================================================
// AUTOMATED SERVICE MANAGEMENT - PAYMENT-BASED CONTROL
// =============================================================================

// Check for expired subscriptions every 5 minutes
Schedule::job(new CheckExpiredSubscriptionsJob)
    ->everyFiveMinutes()
    ->name('check-expired-subscriptions')
    ->withoutOverlapping()
    ->onOneServer();

// Send payment reminders daily at 9:00 AM
Schedule::job(new SendPaymentRemindersJob)
    ->dailyAt('09:00')
    ->name('send-payment-reminders')
    ->onOneServer();

// Process grace periods every 30 minutes
Schedule::job(new ProcessGracePeriodJob)
    ->everyThirtyMinutes()
    ->name('process-grace-periods')
    ->withoutOverlapping()
    ->onOneServer();

// Sync access point status every 5 minutes
Schedule::job(new SyncAccessPointStatusJob)
    ->everyFiveMinutes()
    ->name('sync-access-point-status')
    ->onOneServer();

// =============================================================================
// PACKAGE ACTIVATION/DEACTIVATION MANAGEMENT
// =============================================================================

// Process scheduled package activations/deactivations every minute
Schedule::job(new ProcessScheduledPackages)
    ->everyMinute()
    ->name('process-scheduled-packages')
    ->withoutOverlapping()
    ->onOneServer();

// =============================================================================
// CACHE MANAGEMENT
// =============================================================================

// Warm up cache every hour to maintain optimal performance
Schedule::command('cache:warmup')
    ->hourly()
    ->name('cache-warmup')
    ->onOneServer();

// =============================================================================
// PERFORMANCE METRICS
// =============================================================================

// Collect and persist system metrics every minute
Schedule::job(new \App\Jobs\CollectSystemMetricsJob)
    ->everyMinute()
    ->name('collect-system-metrics')
    ->withoutOverlapping()
    ->onOneServer();

// Reset TPS counter every minute for accurate metrics
Schedule::call(function () {
    \App\Services\MetricsService::resetTPSCounter();
})->everyMinute()->name('reset-tps-counter');

// Store performance metrics to database every 5 minutes
Schedule::call(function () {
    \App\Services\MetricsService::storeMetrics();
})->everyFiveMinutes()->name('store-performance-metrics');

// Cleanup old metrics (keep last 30 days) - runs daily at 2 AM
Schedule::call(function () {
    \App\Services\MetricsService::cleanupOldMetrics();
})->dailyAt('02:00')->name('cleanup-old-metrics');