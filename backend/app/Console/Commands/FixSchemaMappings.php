<?php

namespace App\Console\Commands;

use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixSchemaMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:fix-schema-mappings {--tenant-id=} {--include-pppoe} {--fix-pppoe-router-ids} {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix missing schema mappings for tenant users and PPPoE users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fixing schema mappings for tenant users...');

        $dryRun = (bool) $this->option('dry-run');
        $fixRouterIds = (bool) $this->option('fix-pppoe-router-ids');
        $tenantContext = app(TenantContext::class);

        // Get tenants to process
        $query = Tenant::query();
        
        if ($this->option('tenant-id')) {
            $query->where('id', $this->option('tenant-id'));
        }

        $tenants = $query->get();
        
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return 0;
        }

        $fixed = 0;
        $skipped = 0;
        $errors = 0;
        $routerFixed = 0;
        $routerSkipped = 0;
        $routerErrors = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} (ID: {$tenant->id})");

            // Fix tenant admin users (from public.users table)
            $users = User::where('tenant_id', $tenant->id)->get();

            foreach ($users as $user) {
                $result = $this->ensureMapping($user->username, $tenant, 'admin');
                if ($result === 'fixed') $fixed++;
                elseif ($result === 'skipped') $skipped++;
                else $errors++;
            }

            // Fix PPPoE users (from tenant schema's pppoe_users table)
            if ($this->option('include-pppoe') || $this->confirm("Include PPPoE users for {$tenant->name}?", true)) {
                $this->fixPppoeUsers($tenant, $fixed, $skipped, $errors);
            }

            if ($fixRouterIds || $this->confirm("Repair PPPoE router_id values for {$tenant->name}?", true)) {
                $this->fixPppoeRouterIds($tenant, $tenantContext, $dryRun, $routerFixed, $routerSkipped, $routerErrors);
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Fixed: {$fixed}");
        $this->info("  Skipped (already exists): {$skipped}");
        $this->info("  Errors: {$errors}");
        $this->info("  PPPoE router_id fixed: {$routerFixed}");
        $this->info("  PPPoE router_id skipped: {$routerSkipped}");
        $this->info("  PPPoE router_id errors: {$routerErrors}");

        return 0;
    }

    /**
     * Fix PPPoE users for a tenant
     */
    private function fixPppoeUsers(Tenant $tenant, int &$fixed, int &$skipped, int &$errors): void
    {
        $this->info("  Checking PPPoE users in schema: {$tenant->schema_name}");

        try {
            // Query PPPoE users directly from tenant schema
            $pppoeUsers = DB::select("
                SELECT username FROM {$tenant->schema_name}.pppoe_users
            ");

            if (empty($pppoeUsers)) {
                $this->line("  No PPPoE users found in {$tenant->schema_name}");
                return;
            }

            $this->info("  Found " . count($pppoeUsers) . " PPPoE users");

            foreach ($pppoeUsers as $pppoeUser) {
                $result = $this->ensureMapping($pppoeUser->username, $tenant, 'pppoe');
                if ($result === 'fixed') $fixed++;
                elseif ($result === 'skipped') $skipped++;
                else $errors++;
            }

        } catch (\Exception $e) {
            $this->error("  Failed to query PPPoE users: {$e->getMessage()}");
            $errors++;
        }
    }

    /**
     * Fix PPPoE router_id values using RADIUS accounting data
     */
    private function fixPppoeRouterIds(
        Tenant $tenant,
        TenantContext $tenantContext,
        bool $dryRun,
        int &$fixed,
        int &$skipped,
        int &$errors
    ): void {
        $this->info("  Checking PPPoE router_id values in schema: {$tenant->schema_name}");

        try {
            $tenantContext->runInTenantContext($tenant, function () use ($dryRun, &$fixed, &$skipped, &$errors) {
                if (!Schema::hasTable('pppoe_users')) {
                    $this->line('  PPPoE users table not found in tenant schema.');
                    return;
                }

                $pppoeUsers = PppoeUser::query()
                    ->select(['id', 'username', 'router_id'])
                    ->orderBy('created_at')
                    ->get();

                if ($pppoeUsers->isEmpty()) {
                    $this->line('  No PPPoE users found.');
                    return;
                }

                $publicRadacctExists = (bool) (DB::selectOne("SELECT to_regclass('public.radacct') AS t")->t ?? null);

                foreach ($pppoeUsers as $pppoeUser) {
                    $currentRouterId = $pppoeUser->router_id ? (string) $pppoeUser->router_id : '';
                    if ($currentRouterId !== '' && Router::whereKey($currentRouterId)->exists()) {
                        $skipped++;
                        continue;
                    }

                    $router = $this->resolveRouterFromAccounting((string) $pppoeUser->username, $publicRadacctExists);
                    if (!$router) {
                        $this->line("    - No router found for username {$pppoeUser->username}");
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->info("    → Would set router_id for {$pppoeUser->username} to {$router->id}");
                        $fixed++;
                        continue;
                    }

                    try {
                        $pppoeUser->router_id = $router->id;
                        $pppoeUser->save();
                        $this->info("    ✓ Set router_id for {$pppoeUser->username} to {$router->id}");
                        $fixed++;
                    } catch (\Exception $e) {
                        $this->error("    ✗ Failed to update {$pppoeUser->username}: {$e->getMessage()}");
                        $errors++;
                    }
                }
            });
        } catch (\Exception $e) {
            $this->error("  Failed to repair PPPoE router_id values: {$e->getMessage()}");
            $errors++;
        }
    }

    private function resolveRouterFromAccounting(string $username, bool $publicRadacctExists): ?Router
    {
        $radacct = null;
        if (Schema::hasTable('radacct')) {
            $radacct = DB::table('radacct')
                ->where('username', $username)
                ->orderByDesc('acctstarttime')
                ->orderByDesc('acctupdatetime')
                ->first();
        }

        if (!$radacct && $publicRadacctExists) {
            $radacct = DB::table('public.radacct')
                ->where('username', $username)
                ->orderByDesc('acctstarttime')
                ->orderByDesc('acctupdatetime')
                ->first();
        }

        if (!$radacct) {
            return null;
        }

        $nasIp = $radacct->nasipaddress ?? null;
        if (!$nasIp) {
            return null;
        }

        return $this->findRouterByIp((string) $nasIp);
    }

    private function findRouterByIp(string $ip): ?Router
    {
        $ip = $this->normalizeIp($ip);
        if ($ip === '') {
            return null;
        }

        return Router::query()
            ->where('vpn_ip', $ip)
            ->orWhere('ip_address', $ip)
            ->orWhereRaw("split_part(ip_address, '/', 1) = ?", [$ip])
            ->orWhereRaw("split_part(vpn_ip, '/', 1) = ?", [$ip])
            ->first();
    }

    private function normalizeIp(?string $ip): string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        return preg_replace('/\/.+$/', '', $ip);
    }

    /**
     * Ensure a mapping exists for a username
     */
    private function ensureMapping(string $username, Tenant $tenant, string $userRole): string
    {
        $username = strtolower(trim($username));

        try {
            // Check if schema mapping already exists
            $exists = DB::table('public.radius_user_schema_mapping')
                ->where('username', $username)
                ->exists();

            if ($exists) {
                $this->line("    ✓ Mapping exists for: {$username}");
                return 'skipped';
            }

            // Create schema mapping
            DB::table('public.radius_user_schema_mapping')->insert([
                'username' => $username,
                'schema_name' => $tenant->schema_name,
                'tenant_id' => $tenant->id,
                'user_role' => $userRole,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info("    ✓ Created mapping for: {$username} -> {$tenant->schema_name} (role: {$userRole})");
            return 'fixed';

        } catch (\Exception $e) {
            $this->error("    ✗ Failed for {$username}: {$e->getMessage()}");
            return 'error';
        }
    }
}
