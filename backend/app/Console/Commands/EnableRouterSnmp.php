<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnableRouterSnmp extends Command
{
    protected $signature = 'routers:enable-snmp
                            {--community= : SNMP community string (defaults to TELEGRAF_SNMP_COMMUNITY)}
                            {--version=2c : SNMP version to set (default: 2c)}';

    protected $description = 'Enable SNMP on all routers that do not have it enabled, so Telegraf can poll metrics';

    public function handle(): int
    {
        $community = $this->option('community') ?: config('telegraf.snmp_community', 'public');
        $version = $this->option('version') ?: '2c';

        $tenants = Tenant::where('is_active', true)->get(['id', 'schema_name', 'name']);
        $updated = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            if (!$tenant->schema_name) {
                continue;
            }

            try {
                $tenantContext = app(TenantContext::class);
                $tenantContext->setTenant($tenant);
                DB::statement("SET search_path TO {$tenant->schema_name}, public");

                $routers = Router::where('snmp_enabled', false)
                    ->orWhereNull('snmp_enabled')
                    ->get(['id', 'name', 'snmp_enabled', 'snmp_version', 'snmp_v3_user']);

                foreach ($routers as $router) {
                    // Don't overwrite existing SNMPv3 credentials
                    if ($router->snmp_v3_user) {
                        $router->update(['snmp_enabled' => true]);
                        $this->line("  ✅ {$router->name} — re-enabled existing SNMPv3");
                    } else {
                        $router->update([
                            'snmp_enabled' => true,
                            'snmp_version' => $version,
                            'snmp_community' => $community,
                        ]);
                        $this->line("  ✅ {$router->name} — enabled SNMPv{$version} (community: {$community})");
                    }
                    $updated++;
                }

                $alreadyEnabled = Router::where('snmp_enabled', true)->count() - $updated;
                if ($alreadyEnabled > 0) {
                    $skipped += $alreadyEnabled;
                }
            } catch (\Throwable $e) {
                $this->warn("  ⚠ Tenant {$tenant->name}: {$e->getMessage()}");
            } finally {
                try {
                    DB::statement('SET search_path TO public');
                } catch (\Throwable $e) {
                }
            }
        }

        $this->info("Updated {$updated} router(s). Skipped {$skipped} already-enabled.");

        // Regenerate Telegraf config
        $this->info('Regenerating Telegraf config...');
        $this->call('telegraf:generate-config');

        return Command::SUCCESS;
    }
}
