<?php

namespace App\Providers;

use App\Contracts\ProvisioningCommandBus;
use App\Listeners\TrackCompletedJobs;
use App\Services\ProvisioningServiceClient;
use App\Models\PersonalAccessToken;
use App\Services\RadiusService;
use App\Services\RouterDriver\DriverRegistry;
use App\Services\RouterDriver\RouterVendorProfileRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Carbon\CarbonImmutable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RadiusService::class, function ($app) {
            return new RadiusService();
        });

        $this->app->singleton(ProvisioningCommandBus::class, ProvisioningServiceClient::class);
        $this->app->singleton(RouterVendorProfileRegistry::class);
        $this->app->singleton(DriverRegistry::class, function ($app) {
            $registry = new DriverRegistry($app->make(RouterVendorProfileRegistry::class));
            $registry->registerDefaults();
            return $registry;
        });
        
        // TenantContext carries request-scoped mutable state (tenant/search_path).
        // Under Octane, singleton would leak state across requests.
        $this->app->scoped(\App\Services\TenantContext::class);
        
        // Use immutable dates for better performance (no mutation overhead)
        Date::use(CarbonImmutable::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $consoleMemoryLimit = (string) env('CLI_MEMORY_LIMIT', '512M');
            if ($consoleMemoryLimit !== '') {
                @ini_set('memory_limit', $consoleMemoryLimit);
            }
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        
        // Track completed jobs for statistics
        Event::listen(JobProcessed::class, TrackCompletedJobs::class);

        // Register morph map to prevent runtime class resolution
        $this->registerMorphMap();

        $this->configureReadReplicaFallback();
        $this->registerFatalShutdownLogger();
        
        // Disable mass assignment protection for better performance (use API resources instead)
        // Model::unguard(); // Uncomment only if using API resources for validation
    }
    
    /**
     * Register morph map for polymorphic relations
     * Prevents class name resolution on every query
     */
    private function registerFatalShutdownLogger(): void
    {
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if (! is_array($error)) {
                return;
            }

            $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR];
            if (! in_array($error['type'] ?? null, $fatalTypes, true)) {
                return;
            }

            $request = request();
            $context = [
                'type' => $error['type'] ?? null,
                'message' => $error['message'] ?? null,
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
                'memory_limit' => ini_get('memory_limit'),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'sapi' => PHP_SAPI,
                'running_in_console' => app()->runningInConsole(),
            ];

            if ($request) {
                $context['method'] = $request->method();
                $context['path'] = $request->path();
                $context['full_url'] = $request->fullUrl();
            }

            if (app()->runningInConsole()) {
                global $argv;
                $context['argv'] = $argv ?? [];
            }

            Log::critical('Fatal shutdown error', $context);
        });
    }

    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'user' => \App\Models\User::class,
            'tenant' => \App\Models\Tenant::class,
            'router' => \App\Models\Router::class,
            'router_service' => \App\Models\RouterService::class,
            'voucher' => \App\Models\Voucher::class,
            'package' => \App\Models\Package::class,
            'payment' => \App\Models\Payment::class,
            'pppoe_user' => \App\Models\PppoeUser::class,
            'hotspot_user' => \App\Models\HotspotUser::class,
            'access_point' => \App\Models\AccessPoint::class,
            'audit_log' => \App\Models\AuditLog::class,
        ]);
    }

    private function configureReadReplicaFallback(): void
    {
        if ($this->app->runningInConsole() && !$this->app->environment('production')) {
            return;
        }

        // Avoid live connectivity probes during normal HTTP request boot.
        // They add latency to every cold cache interval and can cascade badly
        // when Redis or the pooler is already degraded.
        if (! $this->shouldRunRuntimeDbHealthchecks()) {
            return;
        }

        $pgsql = (array) config('database.connections.pgsql', []);
        $writeHost = data_get($pgsql, 'write.host', data_get($pgsql, 'host', env('DB_HOST', '172.70.0.3')));
        $writePort = (int) data_get($pgsql, 'write.port', data_get($pgsql, 'port', env('DB_PORT', 6432)));
        $readHost = data_get($pgsql, 'read.host', env('DB_READ_HOST', $writeHost));
        $readPort = (int) data_get($pgsql, 'read.port', env('DB_READ_PORT', $writePort));
        $database = (string) data_get($pgsql, 'database', env('DB_DATABASE', 'postgres'));
        $username = (string) data_get($pgsql, 'username', env('DB_USERNAME', 'postgres'));
        $password = (string) data_get($pgsql, 'password', env('DB_PASSWORD', ''));

        if (is_array($writeHost)) {
            $writeHost = (string) ($writeHost[0] ?? '');
        }

        if (is_array($readHost)) {
            $readHost = (string) ($readHost[0] ?? '');
        }

        $timeout = (float) env('DB_READ_HEALTHCHECK_TIMEOUT', 0.5);
        $interval = (int) env('DB_READ_HEALTHCHECK_INTERVAL', 30);

        $check = function (string $host, int $port) use ($database, $username, $password, $timeout): bool {
            if ($host === '') {
                return false;
            }

            $pdoTimeout = max(1, (int) ceil($timeout));
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

            try {
                $pdo = new \PDO($dsn, $username, $password, [
                    \PDO::ATTR_TIMEOUT => $pdoTimeout,
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->query('SELECT 1');
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        };

        try {
            $writeHealthy = Cache::remember(
                'db:write-pooler:healthy',
                now()->addSeconds(max(1, $interval)),
                fn () => $check((string) $writeHost, (int) $writePort)
            );

            $readHealthy = Cache::remember(
                'db:read-pooler:healthy',
                now()->addSeconds(max(1, $interval)),
                fn () => $check((string) $readHost, (int) $readPort)
            );
        } catch (\Throwable $e) {
            Log::debug('Read replica healthcheck skipped: ' . $e->getMessage());
            return;
        }

        if (!$writeHealthy) {
            $directHost = (string) env('DB_DIRECT_HOST', '');
            $directPort = (int) env('DB_DIRECT_PORT', 5432);

            if ($directHost !== '') {
                config([
                    'database.connections.pgsql.host' => $directHost,
                    'database.connections.pgsql.port' => $directPort,
                    'database.connections.pgsql.write.host' => $directHost,
                    'database.connections.pgsql.write.port' => $directPort,
                ]);
                $writeHost = $directHost;
                $writePort = $directPort;
            }
        }

        if (!$readHealthy && $writeHost !== '') {
            config([
                'database.connections.pgsql.read.host' => $writeHost,
                'database.connections.pgsql.read.port' => $writePort,
                'database.connections.pgsql.sticky' => true,
            ]);
        }
    }

    private function shouldRunRuntimeDbHealthchecks(): bool
    {
        return (bool) env('DB_RUNTIME_POOL_HEALTHCHECKS', false);
    }
}
