<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckRoutersJob;
use App\Jobs\RouterHandshakeMonitorJob;
use App\Jobs\ComputeRouterMetricsJob;
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
// Hotspot Jobs
use App\Jobs\CheckHotspotExpirationsJob;



Schedule::job(new CheckRoutersJob)->everyMinute();

// REMOVED: RouterHandshakeMonitorJob was conflicting with UpdateVpnStatusJob
// UpdateVpnStatusJob (every 30s) already handles WireGuard peer health and sets router status
// Keeping this commented to prevent status flip-flopping between the two jobs

// Regenerate Telegraf config every 5 minutes to pick up new routers
Schedule::command('telegraf:generate-config')
    ->everyFiveMinutes()
    ->name('regenerate-telegraf-config')
    ->withoutOverlapping()
    ->onOneServer();

// NEW: Router metrics pre-computation for event-based graph updates
// This runs every minute to pre-compute and cache graph data
Schedule::job(new ComputeRouterMetricsJob)
    ->everyMinute()
    ->name('compute-router-metrics')
    ->withoutOverlapping()
    ->onOneServer();

// Fetch live router data from VictoriaMetrics and broadcast to frontend.
// Telegraf scrapes frequently; this fanout only hydrates UI broadcasts.
Schedule::job(new \App\Jobs\ScheduleRouterPollingJob)
    ->everyFifteenSeconds()
    ->name('fetch-router-live-data')
    ->withoutOverlapping()
    ->onOneServer();

// Update dashboard statistics every 30 seconds (optimized for low-end device support)
Schedule::call(function () {
    $tenants = \App\Models\Tenant::query()
        ->whereNull('deleted_at')
        ->where('is_active', true)
        ->useWritePdo()
        ->pluck('id');
    
    foreach ($tenants as $tenantId) {
        UpdateDashboardStatsJob::dispatch($tenantId)->onQueue('dashboard');
    }
})->everyThirtySeconds()->name('update-dashboard-stats')->withoutOverlapping()->onOneServer();

// Schedule the RotateLogs job to run daily at 1:00 AM
//Schedule::job(new RotateLogs)->dailyAt('01:00');
Schedule::job(new RotateLogs)->dailyAt('00:00');

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

// Update VPN connection statuses every 30 seconds
Schedule::job(new UpdateVpnStatusJob)
    ->everyThirtySeconds()
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

// Legacy PPPoE payment status job is kept behind a feature flag to avoid overlapping
// enforcement with the authoritative paybill-driven PPPoE billing flow.
if (config('saas.pppoe_billing.legacy_status_job_enabled')) {
    Schedule::job(new \App\Jobs\CheckPppoePaymentStatusJob)
        ->everyFiveMinutes()
        ->name('check-pppoe-payment-status')
        ->withoutOverlapping()
        ->onOneServer();
}

// =============================================================================
// CACHE MANAGEMENT
// =============================================================================

// Warm up cache every hour to maintain optimal performance
Schedule::command('cache:warmup')
    ->hourly()
    ->name('cache-warmup')
    ->onOneServer();

// Cache routers for all tenants every 10 minutes
Schedule::job(new \App\Jobs\CacheRoutersJob)
    ->everyTenMinutes()
    ->name('cache-tenant-routers')
    ->withoutOverlapping()
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

// =============================================================================
// P2 SECURITY - AUTOMATED MAINTENANCE
// =============================================================================

// SSH Key Rotation - daily at 3:00 AM (90-day rotation policy)
Schedule::command('routers:rotate-ssh-keys')
    ->dailyAt('03:00')
    ->name('router-ssh-key-rotation')
    ->onOneServer();

// Database backup - daily at 3:30 AM (full backup) - moved to avoid conflict
Schedule::command('db:backup --type=full')
    ->dailyAt('03:30')
    ->name('database-backup-full')
    ->onOneServer();

// Database backup - schema only at 12:00 PM (for quick recovery testing)
Schedule::command('db:backup --type=schema')
    ->dailyAt('12:00')
    ->name('database-backup-schema')
    ->onOneServer();

// Dependency vulnerability scan - weekly on Mondays at 4:00 AM
Schedule::command('security:scan-dependencies --report')
    ->weeklyOn(1, '04:00')
    ->name('security-dependency-scan')
    ->onOneServer();

// Data cleanup according to retention policies - daily at 2:30 AM
Schedule::command('data:cleanup')
    ->dailyAt('02:30')
    ->name('data-retention-cleanup')
    ->onOneServer();

// =============================================================================
// SAAS TENANT SUBSCRIPTION ENFORCEMENT
// =============================================================================

// Check tenant subscriptions and suspend expired ones - every 5 minutes
Schedule::job(new \App\Jobs\CheckTenantSubscriptionsJob())
    ->everyFiveMinutes()
    ->name('check-tenant-subscriptions')
    ->onOneServer()
    ->withoutOverlapping();

// Send expiry warning notifications to tenants - daily at 8:00 AM
Schedule::job(new \App\Jobs\SendTenantExpiryWarningJob())
    ->dailyAt('08:00')
    ->name('send-tenant-expiry-warnings')
    ->onOneServer()
    ->withoutOverlapping();

// =============================================================================
// PPPOE PAYMENT CHECKING AND ENFORCEMENT
// =============================================================================

// Check PPPoE payments and disconnect overdue users - every 5 minutes
Schedule::job(new \App\Jobs\CheckPppoePaymentsJob())
    ->everyFiveMinutes()
    ->name('check-pppoe-payments')
    ->onOneServer()
    ->withoutOverlapping();

// Send PPPoE payment reminders daily at 8:15 AM
Schedule::call(function () {
    event(new \App\Events\PppoePaymentReminderDue(null, 'scheduler'));
})
    ->dailyAt('08:15')
    ->name('send-pppoe-payment-reminders')
    ->onOneServer()
    ->withoutOverlapping();

// Send PPPoE invoices daily at 8:30 AM
Schedule::call(function () {
    event(new \App\Events\PppoeInvoiceDueSoon(null, 'scheduler'));
})
    ->dailyAt('08:30')
    ->name('send-pppoe-invoices')
    ->onOneServer()
    ->withoutOverlapping();

// Generate PPPoE monthly payment reports on the first day of each month at 1:00 AM
Schedule::call(function () {
    event(new \App\Events\PppoeMonthEndReportRequested(null, now()->subMonthNoOverflow()->format('Y-m'), 'scheduler'));
})
    ->monthlyOn(1, '01:00')
    ->name('generate-pppoe-monthly-payment-report')
    ->onOneServer()
    ->withoutOverlapping();

// =============================================================================
// HOTSPOT SUBSCRIPTION EXPIRATION ENFORCEMENT
// =============================================================================

// Check for expired Hotspot subscriptions and disconnect users - every minute
Schedule::job(new CheckHotspotExpirationsJob())
    ->everyMinute()
    ->name('check-hotspot-expirations')
    ->onOneServer()
    ->withoutOverlapping();
