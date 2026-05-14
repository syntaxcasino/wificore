<?php

namespace App\Console\Commands;

use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair missing RADIUS schema mappings for PPPoE users.
 * 
 * This command ensures all PPPoE users have entries in the
 * radius_user_schema_mapping table, which is required for FreeRADIUS
 * to correctly route authentication and accounting queries.
 */
class RepairPppoeSchemaMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pppoe:repair-schema-mappings 
                            {--tenant-id= : Specific tenant ID to process}
                            {--dry-run : Show what would be fixed without making changes}
                            {--username= : Specific username to fix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Repair missing RADIUS schema mappings for PPPoE users (root cause fix for active sessions not displaying)';

    /**
     * Execute the console command.
     */
    public function handle(TenantContext $tenantContext): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $specificTenantId = $this->option('tenant-id');
        $specificUsername = $this->option('username');

        $this->info('=== PPPoE Schema Mapping Repair ===');
        $this->info('This fixes the root cause where PPP active sessions do not display');
        $this->info('because FreeRADIUS cannot determine the tenant schema.');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $totalFixed = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $totalMissing = 0;

        // Get tenants to process
        $tenants = $this->getTenants($specificTenantId);

        if ($tenants->isEmpty()) {
            $this->error('No tenants found to process.');
            return 1;
        }

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} ({$tenant->id})");
            $this->info("Schema: {$tenant->schema_name}");

            try {
                $result = $tenantContext->runInTenantContext($tenant, function () use ($tenant, $dryRun, $specificUsername, &$totalMissing) {
                    return $this->processTenantPppoeUsers($tenant, $dryRun, $specificUsername);
                });

                $totalFixed += $result['fixed'];
                $totalSkipped += $result['skipped'];
                $totalErrors += $result['errors'];
                $totalMissing += $result['missing_mapping'];

            } catch (\Exception $e) {
                $this->error("  Failed to process tenant: {$e->getMessage()}");
                Log::error('RepairPppoeSchemaMappings: Tenant processing failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $totalErrors++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('=== Summary ===');
        $this->info("Tenants processed: {$tenants->count()}");
        $this->info("Missing mappings found: {$totalMissing}");
        if ($dryRun) {
            $this->info("Would fix: {$totalFixed}");
        } else {
            $this->info("Fixed: {$totalFixed}");
        }
        $this->info("Already correct: {$totalSkipped}");
        $this->info("Errors: {$totalErrors}");
        $this->newLine();

        if ($totalMissing > 0 && !$dryRun) {
            $this->info('Root cause fixed! FreeRADIUS accounting should now work correctly.');
            $this->info('New sessions will appear in PPP Active Sessions after users reconnect.');
        }

        return $totalErrors > 0 ? 1 : 0;
    }

    /**
     * Get tenants to process.
     *
     * @param string|null $specificTenantId
     * @return \Illuminate\Support\Collection
     */
    private function getTenants(?string $specificTenantId): \Illuminate\Support\Collection
    {
        if ($specificTenantId) {
            $tenant = Tenant::find($specificTenantId);
            return $tenant ? collect([$tenant]) : collect();
        }

        return Tenant::where('is_active', true)
            ->where('schema_created', true)
            ->get();
    }

    /**
     * Process PPPoE users for a tenant.
     *
     * @param Tenant $tenant
     * @param bool $dryRun
     * @param string|null $specificUsername
     * @return array
     */
    private function processTenantPppoeUsers(Tenant $tenant, bool $dryRun, ?string $specificUsername): array
    {
        $fixed = 0;
        $skipped = 0;
        $errors = 0;
        $missingMapping = 0;

        // Get all PPPoE users in this tenant
        $query = PppoeUser::query();

        if ($specificUsername) {
            $query->where('username', $specificUsername);
        }

        $pppoeUsers = $query->get();

        $this->info("  Found {$pppoeUsers->count()} PPPoE user(s)");

        foreach ($pppoeUsers as $pppoeUser) {
            try {
                $normalizedUsername = strtolower(trim($pppoeUser->username));

                // Check if mapping exists
                $exists = DB::table('public.radius_user_schema_mapping')
                    ->where('username', $normalizedUsername)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $this->line("    ✓ {$normalizedUsername} - mapping exists");
                    continue;
                }

                $missingMapping++;

                if ($dryRun) {
                    $this->warn("    → {$normalizedUsername} - WOULD CREATE mapping");
                    $fixed++;
                    continue;
                }

                // Create the mapping
                DB::statement("
                    INSERT INTO public.radius_user_schema_mapping 
                    (username, schema_name, tenant_id, user_role, is_active, created_at, updated_at)
                    VALUES (?, ?, ?::uuid, 'pppoe', true, NOW(), NOW())
                ", [$normalizedUsername, $tenant->schema_name, $tenant->id]);

                $this->info("    ✓ {$pppoeUser->username} - mapping CREATED");
                $fixed++;

                Log::info('RADIUS schema mapping repaired', [
                    'username' => $pppoeUser->username,
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name,
                ]);

            } catch (\Exception $e) {
                $this->error("    ✗ {$pppoeUser->username} - ERROR: {$e->getMessage()}");
                Log::error('Failed to repair schema mapping', [
                    'username' => $pppoeUser->username,
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        return [
            'fixed' => $fixed,
            'skipped' => $skipped,
            'errors' => $errors,
            'missing_mapping' => $missingMapping,
        ];
    }
}
