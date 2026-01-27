<?php

namespace App\Providers;

use App\Listeners\TrackCompletedJobs;
use App\Models\PersonalAccessToken;
use App\Services\RadiusService;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        
        // Register TenantContext as singleton to maintain state across middleware and controllers
        $this->app->singleton(\App\Services\TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        
        // Track completed jobs for statistics
        Event::listen(JobProcessed::class, TrackCompletedJobs::class);

        $this->configureReadReplicaFallback();
    }

    private function configureReadReplicaFallback(): void
    {
        if ($this->app->runningInConsole() && !$this->app->environment('production')) {
            return;
        }

        $pgsql = (array) config('database.connections.pgsql', []);
        $writeHost = data_get($pgsql, 'write.host', data_get($pgsql, 'host', env('DB_HOST', '127.0.0.1')));
        $writePort = (int) data_get($pgsql, 'write.port', data_get($pgsql, 'port', env('DB_PORT', 5432)));
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

        $timeout = (float) env('DB_READ_HEALTHCHECK_TIMEOUT', 0.2);
        $interval = (int) env('DB_READ_HEALTHCHECK_INTERVAL', 5);

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

        if (!$readHealthy) {
            $directReadHost = (string) env('DB_DIRECT_READ_HOST', '');
            $directReadPort = (int) env('DB_DIRECT_READ_PORT', env('DB_DIRECT_PORT', 5432));
            $fallbackReadHost = $directReadHost !== '' ? $directReadHost : (string) env('DB_DIRECT_HOST', (string) $writeHost);
            $fallbackReadPort = $directReadHost !== '' ? $directReadPort : (int) env('DB_DIRECT_PORT', $writePort);

            if ($fallbackReadHost !== '') {
                config([
                    'database.connections.pgsql.read.host' => $fallbackReadHost,
                    'database.connections.pgsql.read.port' => $fallbackReadPort,
                ]);
            }
        }
    }
}
