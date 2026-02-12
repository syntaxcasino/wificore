<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateTelegrafConfig extends Command
{
    protected $signature = 'telegraf:generate-config
                            {--shard-index= : Shard index to generate (defaults to TELEGRAF_SHARD_INDEX; if omitted, generates all shards)}
                            {--shard-count= : Total number of shards to generate (defaults to TELEGRAF_SHARD_COUNT or 1)}
                            {--output-dir= : Output directory (defaults to storage/app/telegraf/shards)}';

    protected $description = 'Generate Telegraf SNMP polling configuration files (sharded) from tenant router inventory';

    public function handle(): int
    {
        $shardIndexOption = $this->option('shard-index');
        $shardIndex = null;
        if ($shardIndexOption !== null && $shardIndexOption !== '') {
            $shardIndex = (int) $shardIndexOption;
        } elseif (config('telegraf.shard_index') !== null && config('telegraf.shard_index') !== '') {
            $shardIndex = (int) config('telegraf.shard_index');
        }

        $shardCount = (int) ($this->option('shard-count') ?: config('telegraf.shard_count', 1));
        if ($shardCount < 1) {
            $shardCount = 1;
        }

        if ($shardIndex !== null) {
            if ($shardIndex < 0 || $shardIndex >= $shardCount) {
                $this->error("Invalid shard-index {$shardIndex} for shard-count {$shardCount}");
                return Command::FAILURE;
            }
        }

        $outputDir = (string) ($this->option('output-dir') ?: storage_path('app/telegraf/shards'));
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        $tenants = Tenant::where('is_active', true)->get(['id', 'schema_name', 'name']);

        $written = 0;
        $routerCountByShard = array_fill(0, $shardCount, 0);

        $startShard = $shardIndex !== null ? $shardIndex : 0;
        $endShard = $shardIndex !== null ? ($shardIndex + 1) : $shardCount;

        for ($currentShardIndex = $startShard; $currentShardIndex < $endShard; $currentShardIndex++) {
            $lines = [];

            $fastInterval = (string) config('telegraf.fast_interval', '3s');
            $slowInterval = (string) config('telegraf.slow_interval', '30s');
            
            // Global SNMPv2c community from config (default for all routers)
            $snmpCommunity = (string) config('telegraf.snmp_community', 'public');

            // SNMPv3 credentials from config (fallback if router has no per-router v3 creds)
            $snmpV3User = (string) config('telegraf.snmpv3_user', 'snmpmonitor');
            $snmpV3AuthPassword = (string) config('telegraf.snmpv3_auth_password', '');
            $snmpV3PrivPassword = (string) config('telegraf.snmpv3_priv_password', '');

            // Write directly to VictoriaMetrics — bypass Nginx to avoid circular dependency
            // Note: Telegraf's InfluxDB output plugin automatically appends /write to this URL
            $vmWriteUrl = (string) config('victoriametrics.write_url', 'http://wificore-victoriametrics:8428');

            $lines[] = '[agent]';
            $lines[] = "interval = \"{$fastInterval}\"";
            $lines[] = 'round_interval = true';
            $lines[] = 'metric_batch_size = 1000';
            $lines[] = 'metric_buffer_limit = 50000';
            $lines[] = 'flush_interval = "1s"';
            $lines[] = 'flush_jitter = "0s"';
            $lines[] = 'collection_jitter = "0s"';
            $lines[] = 'precision = ""';
            $lines[] = 'hostname = ""';
            $lines[] = 'omit_hostname = true';
            $lines[] = '';

            $lines[] = '[[inputs.internal]]';
            $lines[] = "interval = \"{$slowInterval}\"";
            $lines[] = '';

            foreach ($tenants as $tenant) {
                if (!$tenant->schema_name) {
                    continue;
                }

                try {
                    /** @var TenantContext $tenantContext */
                    $tenantContext = app(TenantContext::class);
                    $tenantContext->setTenant($tenant);

                    DB::statement("SET search_path TO {$tenant->schema_name}, public");

                    $routers = Router::query()
                        ->where('snmp_enabled', true)
                        ->get([
                            'id',
                            'vpn_ip',
                            'ip_address',
                            'device_type',
                            'snmp_enabled',
                            'snmp_version',
                            'snmp_community',
                            'snmp_v3_user',
                            'snmp_v3_auth_protocol',
                            'snmp_v3_auth_password',
                            'snmp_v3_priv_protocol',
                            'snmp_v3_priv_password',
                        ]);

                    foreach ($routers as $router) {
                        $routerId = (string) ($router->id ?? '');
                        if ($routerId === '') {
                            continue;
                        }

                        $hash = (int) sprintf('%u', crc32($routerId));
                        if (($hash % $shardCount) !== $currentShardIndex) {
                            continue;
                        }

                        $ipRaw = (string) ($router->vpn_ip ?: $router->ip_address);
                        $ip = trim(explode('/', $ipRaw, 2)[0]);
                        if ($ip === '') {
                            continue;
                        }

                        $routerCountByShard[$currentShardIndex]++;

                        $deviceType = (string) ($router->device_type ?? 'router');

                        // Always use SNMPv2c
                        $version = 2;
                        
                        // Per-router community string with global fallback
                        $routerCommunity = (string) ($router->snmp_community ?? '');
                        $community = $routerCommunity !== '' ? $routerCommunity : $snmpCommunity;

                        // === Block 1: Router Health (slow interval) ===
                        $this->addSnmpBlock($lines, [
                            'interval' => $slowInterval,
                            'ip' => $ip,
                            'version' => $version,
                            'timeout' => '3s',
                            'retries' => 1,
                            'max_repetitions' => 10,
                            'name' => 'router_health',
                            'community' => $community,
                            'tenant_id' => $tenant->id,
                            'router_id' => $routerId,
                            'device_type' => $deviceType,
                        ]);

                        // Scalar fields for router health
                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "identity"';
                        $lines[] = 'oid = "1.3.6.1.2.1.1.5.0"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "uptime_ticks"';
                        $lines[] = 'oid = "1.3.6.1.2.1.1.3.0"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "cpu_load"';
                        $lines[] = 'oid = "1.3.6.1.4.1.14988.1.1.3.10.0"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "total_memory"';
                        $lines[] = 'oid = "1.3.6.1.4.1.14988.1.1.3.7.0"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "free_memory"';
                        $lines[] = 'oid = "1.3.6.1.4.1.14988.1.1.3.8.0"';
                        $lines[] = '';

                        // Temperature (MikroTik specific)
                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "temperature"';
                        $lines[] = 'oid = "1.3.6.1.4.1.14988.1.1.3.11.0"';
                        $lines[] = '';

                        // Active PPPoE sessions count (MikroTik PPP active count)
                        $lines[] = '[[inputs.snmp.field]]';
                        $lines[] = 'name = "pppoe_sessions"';
                        $lines[] = 'oid = "1.3.6.1.4.1.14988.1.1.5.4.0"';
                        $lines[] = '';

                        // === Block 2: Storage / Disk (HOST-RESOURCES-MIB hrStorage table) ===
                        $this->addSnmpBlock($lines, [
                            'interval' => $slowInterval,
                            'ip' => $ip,
                            'version' => $version,
                            'timeout' => '3s',
                            'retries' => 1,
                            'max_repetitions' => 10,
                            'name' => 'router_storage',
                            'community' => $community,
                            'tenant_id' => $tenant->id,
                            'router_id' => $routerId,
                            'device_type' => $deviceType,
                        ]);

                        // hrStorage table walk
                        $lines[] = '[[inputs.snmp.table]]';
                        $lines[] = 'name = "storage"';
                        $lines[] = 'inherit_tags = ["tenant_id", "router_id", "device_type"]';
                        $lines[] = '';

                        // hrStorageIndex
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageIndex"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.1"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        // hrStorageType (OID value — used to filter for fixed disk)
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageType"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.2"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        // hrStorageDescr
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageDescr"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.3"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        // hrStorageAllocationUnits (bytes per unit)
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageAllocationUnits"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.4"';
                        $lines[] = '';

                        // hrStorageSize (total units)
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageSize"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.5"';
                        $lines[] = '';

                        // hrStorageUsed (used units)
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageUsed"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.6"';
                        $lines[] = '';

                        // === Block 3: Interface traffic counters (fast interval) ===
                        $this->addSnmpBlock($lines, [
                            'interval' => $fastInterval,
                            'ip' => $ip,
                            'version' => $version,
                            'timeout' => '3s',
                            'retries' => 1,
                            'max_repetitions' => 25,
                            'name' => 'interface_counters',
                            'community' => $community,
                            'tenant_id' => $tenant->id,
                            'router_id' => $routerId,
                            'device_type' => $deviceType,
                        ]);

                        // Interface table
                        $lines[] = '[[inputs.snmp.table]]';
                        $lines[] = 'name = "interface"';
                        $lines[] = 'inherit_tags = ["tenant_id", "router_id", "device_type"]';
                        $lines[] = '';

                        // ifDescr — interface name/description
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "ifDescr"';
                        $lines[] = 'oid = "1.3.6.1.2.1.2.2.1.2"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        // ifOperStatus — 1=up, 2=down
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "ifOperStatus"';
                        $lines[] = 'oid = "1.3.6.1.2.1.2.2.1.8"';
                        $lines[] = '';

                        // ifHCInOctets — 64-bit inbound byte counter
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "ifHCInOctets"';
                        $lines[] = 'oid = "1.3.6.1.2.1.31.1.1.1.6"';
                        $lines[] = '';

                        // ifHCOutOctets — 64-bit outbound byte counter
                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "ifHCOutOctets"';
                        $lines[] = 'oid = "1.3.6.1.2.1.31.1.1.1.10"';
                        $lines[] = '';
                    }
                } catch (\Throwable $e) {
                    Log::warning('Telegraf config generation skipped tenant due to error', [
                        'tenant_id' => $tenant->id ?? null,
                        'schema_name' => $tenant->schema_name ?? null,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    try {
                        DB::statement('SET search_path TO public');
                    } catch (\Throwable $e) {
                    }
                }
            }

            // Output: write to VictoriaMetrics via Influx line protocol
            $lines[] = '[[outputs.influxdb]]';
            $lines[] = "urls = [\"{$this->esc($vmWriteUrl)}\"]";
            $lines[] = 'database = "telegraf"';
            $lines[] = 'skip_database_creation = true';
            $lines[] = 'timeout = "5s"';
            $lines[] = '';

            $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $currentShardIndex . '.conf';
            $ok = @file_put_contents($path, implode("\n", $lines) . "\n");
            if ($ok !== false) {
                $written++;
            }
        }

        $this->info("Generated {$written} Telegraf config file(s) in {$outputDir}");
        for ($i = 0; $i < $shardCount; $i++) {
            $this->line("Shard {$i}: {$routerCountByShard[$i]} router(s)");
        }

        return Command::SUCCESS;
    }

    /**
     * Add an [[inputs.snmp]] block header with SNMPv2c auth and tags.
     */
    private function addSnmpBlock(array &$lines, array $opts): void
    {
        $lines[] = '[[inputs.snmp]]';
        $lines[] = "interval = \"{$opts['interval']}\"";
        $lines[] = "agents = [\"udp://{$opts['ip']}:161\"]";
        $lines[] = "version = {$opts['version']}";
        $lines[] = "timeout = \"{$opts['timeout']}\"";
        $lines[] = "retries = {$opts['retries']}";
        $lines[] = "max_repetitions = {$opts['max_repetitions']}";
        $lines[] = "name = \"{$opts['name']}\"";
        $lines[] = "community = \"{$this->esc($opts['community'])}\"";

        $lines[] = '[inputs.snmp.tags]';
        $lines[] = "tenant_id = \"{$opts['tenant_id']}\"";
        $lines[] = "router_id = \"{$opts['router_id']}\"";
        $lines[] = "device_type = \"{$opts['device_type']}\"";
        $lines[] = '';
    }

    private function esc(string $value): string
    {
        $value = str_replace("\\", "\\\\", $value);
        $value = str_replace("\"", "\\\"", $value);
        return $value;
    }
}
