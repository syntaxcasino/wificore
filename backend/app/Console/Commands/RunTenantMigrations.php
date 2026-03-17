<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantMigrationManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunTenantMigrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:run-migrations
                            {tenant? : Tenant UUID (omit to run for ALL tenants)}
                            {--force : Run even if schema_created flag is false}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pending tenant schema migrations for a specific tenant or all tenants';

    /**
     * Execute the console command.
     */
    public function handle(TenantMigrationManager $migrationManager): int
    {
        $tenantId = $this->argument('tenant');

        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->get();

            if ($tenants->isEmpty()) {
                $this->error("Tenant not found: {$tenantId}");
                return Command::FAILURE;
            }
        } else {
            $tenants = Tenant::whereNotNull('schema_name')->get();
            $this->info("Running migrations for all {$tenants->count()} tenants with a schema_name.");
        }

        $success = 0;
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $this->line("Processing tenant: <info>{$tenant->name}</info> (ID: {$tenant->id}, schema: {$tenant->schema_name})");

            // Verify the schema exists in PostgreSQL
            $schemaExists = DB::selectOne(
                "SELECT EXISTS (SELECT FROM information_schema.schemata WHERE schema_name = ?) AS exists",
                [$tenant->schema_name]
            );

            if (!$schemaExists || !$schemaExists->exists) {
                $this->warn("  Schema does not exist in PostgreSQL — run tenant:fix-schemas first.");
                $failed++;
                continue;
            }

            if (!$tenant->schema_created && !$this->option('force')) {
                $this->warn("  schema_created flag is false — use --force to migrate anyway.");
                $failed++;
                continue;
            }

            $result = $migrationManager->runMigrationsForTenant($tenant);

            if ($result) {
                // Ensure the schema_created flag is set after a successful migration run
                if (!$tenant->schema_created) {
                    $tenant->update(['schema_created' => true]);
                }
                $this->info("  Migrations completed successfully.");
                $success++;
            } else {
                $this->error("  Migration failed — check laravel.log for details.");
                $failed++;
            }

            $this->newLine();
        }

        $this->table(
            ['Tenants Succeeded', 'Tenants Failed'],
            [[$success, $failed]]
        );

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
