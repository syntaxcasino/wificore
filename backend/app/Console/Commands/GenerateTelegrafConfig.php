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
        } elseif (env('TELEGRAF_SHARD_INDEX') !== null && env('TELEGRAF_SHARD_INDEX') !== '') {
            $shardIndex = (int) env('TELEGRAF_SHARD_INDEX');
        }

        $shardCount = (int) ($this->option('shard-count') ?: env('TELEGRAF_SHARD_COUNT', 1));
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

            $fastInterval = (string) env('TELEGRAF_FAST_INTERVAL', '3s');
            $slowInterval = (string) env('TELEGRAF_SLOW_INTERVAL', '30s');
            $snmpCommunity = (string) env('TELEGRAF_SNMP_COMMUNITY', 'public');

            $vmWriteUrl = (string) env('VICTORIA_METRICS_WRITE_URL', 'http://wificore-nginx/internal/vm/api/v1/write');

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
                        $version = ($versionRaw === '3' || $versionRaw === 'v3' || $versionRaw === 'snmpv3') ? 3 : (($versionRaw === '1' || $versionRaw === 'v1') ? 1 : 2);

                        $lines[] = '[[inputs.snmp]]';
                        $lines[] = "interval = \"{$slowInterval}\"";
                        $lines[] = "agents = [\"udp://{$ip}:161\"]";
                        $lines[] = "version = {$version}";

                        if ($version !== 3) {
                            $lines[] = "community = \"{$this->escapeTelegrafString($snmpCommunity)}\"";
                        }

                        $lines[] = 'timeout = "2s"';
                        $lines[] = 'retries = 1';
                        $lines[] = 'max_repetitions = 10';
                        $lines[] = 'name = "router_health"';
                        $lines[] = "[inputs.snmp.tags]";
                        $lines[] = "tenant_id = \"{$tenant->id}\"";
                        $lines[] = "router_id = \"{$routerId}\"";
                        $lines[] = "device_type = \"{$deviceType}\"";
                        $lines[] = '';

                        if ($version === 3) {
                            $v3User = (string) ($router->snmp_v3_user ?? '');
                            $authProto = strtoupper((string) ($router->snmp_v3_auth_protocol ?? ''));
                            $authPass = (string) ($router->snmp_v3_auth_password ?? '');
                            $privProto = strtoupper((string) ($router->snmp_v3_priv_protocol ?? ''));
                            $privPass = (string) ($router->snmp_v3_priv_password ?? '');

                            if ($v3User !== '') {
                                $lines[] = "sec_name = \"{$this->escapeTelegrafString($v3User)}\"";

                                $hasAuth = $authPass !== '';
                                $hasPriv = $privPass !== '';
                                $secLevel = $hasAuth && $hasPriv ? 'authPriv' : ($hasAuth ? 'authNoPriv' : 'noAuthNoPriv');
                                $lines[] = "sec_level = \"{$secLevel}\"";

                                if ($hasAuth) {
                                    $lines[] = "auth_protocol = \"{$this->escapeTelegrafString($authProto !== '' ? $authProto : 'MD5')}\"";
                                    $lines[] = "auth_password = \"{$this->escapeTelegrafString($authPass)}\"";
                                }

                                if ($hasPriv) {
                                    $lines[] = "priv_protocol = \"{$this->escapeTelegrafString($privProto !== '' ? $privProto : 'DES')}\"";
                                    $lines[] = "priv_password = \"{$this->escapeTelegrafString($privPass)}\"";
                                }
                            }
                        }

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

                        $lines[] = '[[inputs.snmp]]';
                        $lines[] = "interval = \"{$slowInterval}\"";
                        $lines[] = "agents = [\"udp://{$ip}:161\"]";
                        $lines[] = "version = {$version}";

                        if ($version !== 3) {
                            $lines[] = "community = \"{$this->escapeTelegrafString($snmpCommunity)}\"";
                        }

                        $lines[] = 'timeout = "2s"';
                        $lines[] = 'retries = 1';
                        $lines[] = 'max_repetitions = 25';
                        $lines[] = 'name = "router_storage"';
                        $lines[] = "[inputs.snmp.tags]";
                        $lines[] = "tenant_id = \"{$tenant->id}\"";
                        $lines[] = "router_id = \"{$routerId}\"";
                        $lines[] = "device_type = \"{$deviceType}\"";
                        $lines[] = '';

                        if ($version === 3) {
                            $v3User = (string) ($router->snmp_v3_user ?? '');
                            $authProto = strtoupper((string) ($router->snmp_v3_auth_protocol ?? ''));
                            $authPass = (string) ($router->snmp_v3_auth_password ?? '');
                            $privProto = strtoupper((string) ($router->snmp_v3_priv_protocol ?? ''));
                            $privPass = (string) ($router->snmp_v3_priv_password ?? '');

                            if ($v3User !== '') {
                                $lines[] = "sec_name = \"{$this->escapeTelegrafString($v3User)}\"";

                                $hasAuth = $authPass !== '';
                                $hasPriv = $privPass !== '';
                                $secLevel = $hasAuth && $hasPriv ? 'authPriv' : ($hasAuth ? 'authNoPriv' : 'noAuthNoPriv');
                                $lines[] = "sec_level = \"{$secLevel}\"";

                                if ($hasAuth) {
                                    $lines[] = "auth_protocol = \"{$this->escapeTelegrafString($authProto !== '' ? $authProto : 'MD5')}\"";
                                    $lines[] = "auth_password = \"{$this->escapeTelegrafString($authPass)}\"";
                                }

                                if ($hasPriv) {
                                    $lines[] = "priv_protocol = \"{$this->escapeTelegrafString($privProto !== '' ? $privProto : 'DES')}\"";
                                    $lines[] = "priv_password = \"{$this->escapeTelegrafString($privPass)}\"";
                                }
                            }
                        }

                        $lines[] = '[[inputs.snmp.table]]';
                        $lines[] = 'name = "hrStorage"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageIndex"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.1"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageType"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.2"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageDescr"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.3"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageAllocationUnits"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.4"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageSize"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.5"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "hrStorageUsed"';
                        $lines[] = 'oid = "1.3.6.1.2.1.25.2.3.1.6"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp]]';
                        $lines[] = "interval = \"{$fastInterval}\"";
                        $lines[] = "agents = [\"udp://{$ip}:161\"]";
                        $lines[] = "version = {$version}";

                        if ($version !== 3) {
                            $lines[] = "community = \"{$this->escapeTelegrafString($snmpCommunity)}\"";
                        }

                        $lines[] = 'timeout = "2s"';
                        $lines[] = 'retries = 1';
                        $lines[] = 'max_repetitions = 25';
                        $lines[] = 'name = "interface_counters"';
                        $lines[] = "[inputs.snmp.tags]";
                        $lines[] = "tenant_id = \"{$tenant->id}\"";
                        $lines[] = "router_id = \"{$routerId}\"";
                        $lines[] = "device_type = \"{$deviceType}\"";
                        $lines[] = '';

                        if ($version === 3) {
                            $v3User = (string) ($router->snmp_v3_user ?? '');
                            $authProto = strtoupper((string) ($router->snmp_v3_auth_protocol ?? ''));
                            $authPass = (string) ($router->snmp_v3_auth_password ?? '');
                            $privProto = strtoupper((string) ($router->snmp_v3_priv_protocol ?? ''));
                            $privPass = (string) ($router->snmp_v3_priv_password ?? '');

                            if ($v3User !== '') {
                                $lines[] = "sec_name = \"{$this->escapeTelegrafString($v3User)}\"";

                                $hasAuth = $authPass !== '';
                                $hasPriv = $privPass !== '';
                                $secLevel = $hasAuth && $hasPriv ? 'authPriv' : ($hasAuth ? 'authNoPriv' : 'noAuthNoPriv');
                                $lines[] = "sec_level = \"{$secLevel}\"";

                                if ($hasAuth) {
                                    $lines[] = "auth_protocol = \"{$this->escapeTelegrafString($authProto !== '' ? $authProto : 'MD5')}\"";
                                    $lines[] = "auth_password = \"{$this->escapeTelegrafString($authPass)}\"";
                                }

                                if ($hasPriv) {
                                    $lines[] = "priv_protocol = \"{$this->escapeTelegrafString($privProto !== '' ? $privProto : 'DES')}\"";
                                    $lines[] = "priv_password = \"{$this->escapeTelegrafString($privPass)}\"";
                                }
                            }
                        }

                        $lines[] = '[[inputs.snmp.table]]';
                        $lines[] = 'name = "ifx"';
                        $lines[] = 'oid = "1.3.6.1.2.1.31.1.1.1"';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "ifName"';
                        $lines[] = 'oid = "1.3.6.1.2.1.31.1.1.1.1"';
                        $lines[] = 'is_tag = true';
                        $lines[] = '';

                        $lines[] = '[[inputs.snmp.table.field]]';
                        $lines[] = 'name = "ifHCInOctets"';
                        $lines[] = 'oid = "1.3.6.1.2.1.31.1.1.1.6"';
                        $lines[] = '';

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
