<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
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
    protected $signature = 'tenants:fix-schema-mappings {--tenant-id=} {--include-pppoe}';

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
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Fixed: {$fixed}");
        $this->info("  Skipped (already exists): {$skipped}");
        $this->info("  Errors: {$errors}");

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
     * Ensure a mapping exists for a username
     */
    private function ensureMapping(string $username, Tenant $tenant, string $userRole): string
    {
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
