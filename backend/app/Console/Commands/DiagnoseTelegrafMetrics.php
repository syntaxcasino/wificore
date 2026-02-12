<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnoseTelegrafMetrics extends Command
{
    protected $signature = 'telegraf:diagnose
                            {--router-id= : Specific router ID to diagnose}
                            {--tenant-id= : Specific tenant ID to diagnose}
                            {--test-snmp : Test SNMP connectivity from container}';

    protected $description = 'Diagnose Telegraf metrics collection issues';

    public function handle(): int
    {
        $this->info('🔍 Telegraf Metrics Diagnostic Tool');
        $this->newLine();

        // 1. Check environment variables
        $this->checkEnvironmentVariables();

        // 2. Check database configuration
        $this->checkDatabaseConfiguration();

        // 3. Check Telegraf config files
        $this->checkTelegrafConfigFiles();

        // 4. Check VictoriaMetrics
        $this->checkVictoriaMetrics();

        // 5. Test specific router if provided
        if ($this->option('router-id')) {
            $this->testSpecificRouter($this->option('router-id'));
        }

        // 6. Test SNMP connectivity if requested
        if ($this->option('test-snmp')) {
            $this->testSnmpConnectivity();
        }

        $this->newLine();
        $this->info('✅ Diagnostic complete');

        return Command::SUCCESS;
    }

    private function checkEnvironmentVariables(): void
    {
        $this->info('📋 Environment Variables:');
        
        $vars = [
            'ROUTER_POLLING_MODE' => config('app.router_polling_mode', env('ROUTER_POLLING_MODE', 'NOT SET')),
            'TELEGRAF_SHARD_INDEX' => config('telegraf.shard_index', 'NOT SET'),
            'TELEGRAF_SHARD_COUNT' => config('telegraf.shard_count', 'NOT SET'),
            'TELEGRAF_FAST_INTERVAL' => config('telegraf.fast_interval', 'NOT SET'),
            'TELEGRAF_SLOW_INTERVAL' => config('telegraf.slow_interval', 'NOT SET'),
            'TELEGRAF_SNMP_COMMUNITY' => config('telegraf.snmp_community', 'NOT SET'),
            'TELEGRAF_SNMPV3_USER' => config('telegraf.snmpv3_user', 'NOT SET'),
            'TELEGRAF_SNMPV3_AUTH_PASSWORD' => config('telegraf.snmpv3_auth_password') ? '***SET***' : 'NOT SET',
            'TELEGRAF_SNMPV3_PRIV_PASSWORD' => config('telegraf.snmpv3_priv_password') ? '***SET***' : 'NOT SET',
            'VICTORIA_METRICS_WRITE_URL' => config('victoriametrics.write_url', 'NOT SET'),
        ];

        foreach ($vars as $key => $value) {
            $status = ($value === 'NOT SET') ? '❌' : '✅';
            $this->line("  {$status} {$key}: {$value}");
        }

        $this->newLine();
    }

    private function checkDatabaseConfiguration(): void
    {
        $this->info('🗄️  Database Configuration:');

        $tenants = Tenant::where('is_active', true)->get(['id', 'name', 'schema_name']);
        $this->line("  Total active tenants: " . $tenants->count());

        $totalRouters = 0;
        $snmpEnabledRouters = 0;
        $snmpDisabledRouters = 0;

        foreach ($tenants as $tenant) {
            if (!$tenant->schema_name) {
                continue;
            }

            try {
                $tenantContext = app(TenantContext::class);
                $tenantContext->setTenant($tenant);
                DB::statement("SET search_path TO {$tenant->schema_name}, public");

                $routers = Router::all(['id', 'name', 'vpn_ip', 'ip_address', 'snmp_enabled', 'snmp_version']);
                $totalRouters += $routers->count();

                foreach ($routers as $router) {
                    if ($router->snmp_enabled) {
                        $snmpEnabledRouters++;
                    } else {
                        $snmpDisabledRouters++;
                        $this->warn("    ⚠️  Router '{$router->name}' (Tenant: {$tenant->name}) has SNMP disabled");
                    }
                }
            } catch (\Throwable $e) {
                $this->error("    ❌ Error checking tenant {$tenant->name}: " . $e->getMessage());
            } finally {
                DB::statement('SET search_path TO public');
            }
        }

        $this->line("  Total routers: {$totalRouters}");
        $this->line("  ✅ SNMP enabled: {$snmpEnabledRouters}");
        $this->line("  ❌ SNMP disabled: {$snmpDisabledRouters}");

        $this->newLine();
    }

    private function checkTelegrafConfigFiles(): void
    {
        $this->info('📁 Telegraf Configuration Files:');

        $shardIndex = (int) config('telegraf.shard_index', 0);
        $configPath = storage_path("app/telegraf/shards/{$shardIndex}.conf");

        if (file_exists($configPath)) {
            $this->line("  ✅ Config file exists: {$configPath}");
            $size = filesize($configPath);
            $this->line("  📊 File size: " . number_format($size) . " bytes");
            
            $content = file_get_contents($configPath);
            $inputCount = substr_count($content, '[[inputs.snmp]]');
            $this->line("  📊 SNMP input blocks: {$inputCount}");

            // Show first few lines
            $lines = explode("\n", $content);
            $this->line("  📄 First 10 lines:");
            foreach (array_slice($lines, 0, 10) as $line) {
                $this->line("    " . $line);
            }
        } else {
            $this->error("  ❌ Config file not found: {$configPath}");
            $this->warn("  💡 Run: php artisan telegraf:generate-config");
        }

        $this->newLine();
    }

    private function checkVictoriaMetrics(): void
    {
        $this->info('📊 VictoriaMetrics Status:');

        $vmBaseUrl = rtrim((string) config('victoriametrics.write_url', 'http://wificore-victoriametrics:8428'), '/');
        $vmQueryUrl = $vmBaseUrl . '/api/v1/query';

        try {
            // Try to query recent metrics
            $response = Http::timeout(5)->get($vmQueryUrl, [
                'query' => 'router_health_cpu_load',
                'time' => time(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $resultCount = count($data['data']['result'] ?? []);
                $this->line("  ✅ VictoriaMetrics is accessible");
                $this->line("  📊 Recent CPU metrics: {$resultCount} series");
            } else {
                $this->warn("  ⚠️  VictoriaMetrics responded with status: " . $response->status());
            }
        } catch (\Exception $e) {
            $this->error("  ❌ Cannot reach VictoriaMetrics: " . $e->getMessage());
        }

        $this->newLine();
    }

    private function testSpecificRouter(string $routerId): void
    {
        $this->info("🔍 Testing Router: {$routerId}");

        try {
            // Find router across all tenants
            $tenants = Tenant::where('is_active', true)->get(['id', 'name', 'schema_name']);
            $router = null;
            $tenant = null;

            foreach ($tenants as $t) {
                if (!$t->schema_name) continue;

                try {
                    $tenantContext = app(TenantContext::class);
                    $tenantContext->setTenant($t);
                    DB::statement("SET search_path TO {$t->schema_name}, public");

                    $r = Router::find($routerId);
                    if ($r) {
                        $router = $r;
                        $tenant = $t;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                } finally {
                    DB::statement('SET search_path TO public');
                }
            }

            if (!$router) {
                $this->error("  ❌ Router not found: {$routerId}");
                return;
            }

            $this->line("  ✅ Router found: {$router->name} (Tenant: {$tenant->name})");
            $this->line("  📍 VPN IP: " . ($router->vpn_ip ?: 'NOT SET'));
            $this->line("  📍 Public IP: " . ($router->ip_address ?: 'NOT SET'));
            $this->line("  🔧 SNMP Enabled: " . ($router->snmp_enabled ? 'YES' : 'NO'));
            $this->line("  🔧 SNMP Version: " . ($router->snmp_version ?: 'NOT SET'));
            $this->line("  🔧 SNMP User: " . ($router->snmp_v3_user ?: 'NOT SET'));
            $this->line("  🔧 Auth Protocol: " . ($router->snmp_v3_auth_protocol ?: 'NOT SET'));
            $this->line("  🔧 Priv Protocol: " . ($router->snmp_v3_priv_protocol ?: 'NOT SET'));

        } catch (\Exception $e) {
            $this->error("  ❌ Error: " . $e->getMessage());
        }

        $this->newLine();
    }

    private function testSnmpConnectivity(): void
    {
        $this->info('🔌 SNMP Connectivity Test:');
        $this->warn('  ⚠️  This requires snmpwalk to be installed in the container');
        $this->line('  💡 Run this manually from the Telegraf container:');
        $this->line('');
        $this->line('  docker exec -it wificore-telegraf sh');
        $this->line('  apk add net-snmp net-snmp-tools');
        $this->line('');
        $this->line('  # SNMPv2c test (default):');
        $this->line('  snmpwalk -v2c -c public ROUTER_IP:161 1.3.6.1.2.1.1.5.0');
        $this->line('');
        $this->line('  # SNMPv3 test (if configured):');
        $this->line('  snmpwalk -v3 -l authPriv -u snmpmonitor -a SHA -A "AUTH_PASSWORD" -x AES -X "PRIV_PASSWORD" ROUTER_IP:161');
        $this->newLine();
    }
}
