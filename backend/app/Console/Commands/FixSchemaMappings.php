<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSchemaMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:fix-schema-mappings {--tenant-id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix missing schema mappings for tenant users';

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

            // Get all users for this tenant
            $users = User::where('tenant_id', $tenant->id)->get();

            foreach ($users as $user) {
                try {
                    // Check if schema mapping already exists
                    $exists = DB::table('radius_user_schema_mapping')
                        ->where('username', $user->username)
                        ->exists();

                    if ($exists) {
                        $this->line("  ✓ Mapping exists for: {$user->username}");
                        $skipped++;
                        continue;
                    }

                    // Create schema mapping
                    DB::table('radius_user_schema_mapping')->insert([
                        'username' => $user->username,
                        'schema_name' => $tenant->schema_name,
                        'tenant_id' => $tenant->id,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->info("  ✓ Created mapping for: {$user->username} -> {$tenant->schema_name}");
                    $fixed++;

                } catch (\Exception $e) {
                    $this->error("  ✗ Failed for {$user->username}: {$e->getMessage()}");
                    $errors++;
                }
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Fixed: {$fixed}");
        $this->info("  Skipped (already exists): {$skipped}");
        $this->info("  Errors: {$errors}");

        return 0;
    }
}
