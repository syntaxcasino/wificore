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
            
            // SNMPv3 credentials from config
            $snmpV3User = (string) config('telegraf.snmpv3_user', 'snmpmonitor');
            $snmpV3AuthPassword = (string) config('telegraf.snmpv3_auth_password', '');
            $snmpV3PrivPassword = (string) config('telegraf.snmpv3_priv_password', '');
            $snmpCommunity = (string) config('telegraf.snmp_community', 'public');

            $vmWriteUrl = (string) config('victoriametrics.write_url', 'http://wificore-nginx/internal/vm/api/v1/write');

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
            $lines[] = "interval = \"{$fastInterval}\"";
            $lines[] = '';

            $lines[] = '[[inputs.snmp_trap]]';
            $lines[] = 'service_address = "udp://:162"';
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
                        $versionRaw = strtolower((string) ($router->snmp_version ?? '2c'));
                        $requestedVersion = ($versionRaw === '3' || $versionRaw === 'v3' || $versionRaw === 'snmpv3') ? 3 : (($versionRaw === '1' || $versionRaw === 'v1') ? 1 : 2);

                        // Per-router community string with env fallback
                        $routerCommunity = (string) ($router->snmp_community ?? '');
                        $effectiveCommunity = $routerCommunity !== '' ? $routerCommunity : $snmpCommunity;

                        // Check if SNMPv3 credentials are actually available
                        $v3User = (string) ($router->snmp_v3_user ?? $snmpV3User);
                        $authPass = (string) ($router->snmp_v3_auth_password ?? $snmpV3AuthPassword);
                        $privPass = (string) ($router->snmp_v3_priv_password ?? $snmpV3PrivPassword);
                        $hasV3Credentials = ($v3User !== '' && $authPass !== '' && $privPass !== '');

                        // If SNMPv3 is requested but credentials are missing, fall back to SNMPv2c
                        if ($requestedVersion === 3 && !$hasV3Credentials) {
                            $version = 2;
                            $community = $effectiveCommunity;
                        } elseif ($requestedVersion === 3 && $hasV3Credentials) {
                            $version = 3;
                            $community = '';
                        } else {
                            $version = $requestedVersion;
                            $community = $effectiveCommunity;
                        }

                        $lines[] = '[[inputs.snmp]]';
                        $lines[] = "interval = \"{$slowInterval}\"";
                        $lines[] = "agents = [\"udp://{$ip}:161\"]";
                        $lines[] = "version = {$version}";
                        $lines[] = 'timeout = "2s"';
                        $lines[] = 'retries = 1';
                        $lines[] = 'max_repetitions = 10';
                        $lines[] = 'name = "router_health"';
                        
                        // Add community string for SNMPv1/v2c
                        if ($version !== 3) {
                            $lines[] = "community = \"{$this->escapeTelegrafString($community)}\"";
                        }

                        // Resolve SNMPv3 protocol settings (used by both health and interface blocks)
                        $authProto = (string) ($router->snmp_v3_auth_protocol ?? 'SHA');
                        $privProto = (string) ($router->snmp_v3_priv_protocol ?? 'AES');

                        // SNMPv3 credentials must come before [tags] section in TOML
                        if ($version === 3 && $hasV3Credentials) {
                            $lines[] = "sec_name = \"{$this->escapeTelegrafString($v3User)}\"";
                            $lines[] = "sec_level = \"authPriv\"";
                            $lines[] = "auth_protocol = \"{$this->escapeTelegrafString($authProto)}\"";
                            $lines[] = "auth_password = \"{$this->escapeTelegrafString($authPass)}\"";
                            $lines[] = "priv_protocol = \"{$this->escapeTelegrafString($privProto)}\"";
                            $lines[] = "priv_password = \"{$this->escapeTelegrafString($privPass)}\"";
                        }

                        $lines[] = "[inputs.snmp.tags]";
                        $lines[] = "tenant_id = \"{$tenant->id}\"";
                        $lines[] = "router_id = \"{$routerId}\"";
                        $lines[] = "device_type = \"{$deviceType}\"";
                        $lines[] = '';

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

                        // Interface traffic counters (IF-MIB, numeric OIDs — no MIB files needed)
                        // Uses SNMPv2c table walk for 64-bit high-capacity counters
                        $lines[] = '[[inputs.snmp]]';
                        $lines[] = "interval = \"{$fastInterval}\"";
                        $lines[] = "agents = [\"udp://{$ip}:161\"]";
                        $lines[] = "version = {$version}";
                        $lines[] = 'timeout = "3s"';
                        $lines[] = 'retries = 1';
                        $lines[] = 'max_repetitions = 25';
                        $lines[] = 'name = "interface_counters"';

                        if ($version !== 3) {
                            $lines[] = "community = \"{$this->escapeTelegrafString($community)}\"";
                        }

                        // SNMPv3 credentials must come before [tags] section in TOML
                        if ($version === 3 && $hasV3Credentials) {
                            $lines[] = "sec_name = \"{$this->escapeTelegrafString($v3User)}\"";
                            $lines[] = "sec_level = \"authPriv\"";
                            $lines[] = "auth_protocol = \"{$this->escapeTelegrafString($authProto)}\"";
                            $lines[] = "auth_password = \"{$this->escapeTelegrafString($authPass)}\"";
                            $lines[] = "priv_protocol = \"{$this->escapeTelegrafString($privProto)}\"";
                            $lines[] = "priv_password = \"{$this->escapeTelegrafString($privPass)}\"";
                        }

                        $lines[] = "[inputs.snmp.tags]";
                        $lines[] = "tenant_id = \"{$tenant->id}\"";
                        $lines[] = "router_id = \"{$routerId}\"";
                        $lines[] = "device_type = \"{$deviceType}\"";
                        $lines[] = '';

                        // Interface table: name, status, speed, traffic counters
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

            $lines[] = '[[outputs.http]]';
            $lines[] = "url = \"{$this->escapeTelegrafString($vmWriteUrl)}\"";
            $lines[] = 'timeout = "5s"';
            $lines[] = 'method = "POST"';
            $lines[] = 'data_format = "prometheusremotewrite"';
            $lines[] = '';
            $lines[] = '[outputs.http.headers]';
            $lines[] = 'Content-Type = "application/x-protobuf"';
            $lines[] = 'Content-Encoding = "snappy"';
            $lines[] = 'X-Prometheus-Remote-Write-Version = "0.1.0"';
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

    private function escapeTelegrafString(string $value): string
    {
        $value = str_replace("\\", "\\\\", $value);
        $value = str_replace("\"", "\\\"", $value);
        return $value;
    }
}
