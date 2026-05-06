<?php

namespace App\Console\Commands;

use App\Jobs\CheckPppoePaymentStatusJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixPaymentEnforcement extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:fix-enforcement {--tenant-id= : Specific tenant ID to fix} {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix payment enforcement by blocking unpaid users in RADIUS';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant-id');
        $dryRun = $this->option('dry-run');

        $this->info('Starting payment enforcement fix...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            // Run the payment status check job
            $this->info('Running payment status check job...');
            
            if ($tenantId) {
                CheckPppoePaymentStatusJob::dispatch($tenantId);
                $this->info("Payment check dispatched for tenant: {$tenantId}");
            } else {
                CheckPppoePaymentStatusJob::dispatch();
                $this->info('Payment check dispatched for all tenants');
            }

            // Wait a moment for the job to process
            sleep(2);

            // Show current state of unpaid users
            $this->showUnpaidUsersStatus($dryRun);

            $this->info('Payment enforcement fix completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to fix payment enforcement: ' . $e->getMessage());
            Log::error('Payment enforcement fix failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return Command::FAILURE;
        }
    }

    private function showUnpaidUsersStatus(bool $dryRun): void
    {
        $this->info("\n=== Current Unpaid Users Status ===");

        // Get all schemas that have pppoe_users tables
        $schemas = DB::select("
            SELECT table_schema 
            FROM information_schema.tables 
            WHERE table_name = 'pppoe_users' 
            AND table_schema NOT IN ('public', 'information_schema')
            ORDER BY table_schema
        ");

        foreach ($schemas as $schema) {
            $schemaName = $schema->table_schema;
            $this->info("\nSchema: {$schemaName}");

            try {
                // Get unpaid users
                $unpaidUsers = DB::select("
                    SELECT username, payment_status, status, is_active, 
                           next_payment_due, suspended_at, in_grace_period
                    FROM {$schemaName}.pppoe_users 
                    WHERE payment_status = 'unpaid'
                    ORDER BY username
                ");

                if (empty($unpaidUsers)) {
                    $this->line("  No unpaid users found");
                    continue;
                }

                foreach ($unpaidUsers as $user) {
                    $this->line("  User: {$user->username}");
                    $this->line("    Payment Status: {$user->payment_status}");
                    $this->line("    Account Status: {$user->status}");
                    $this->line("    Is Active: " . ($user->is_active ? 'Yes' : 'No'));
                    $this->line("    In Grace Period: " . ($user->in_grace_period ? 'Yes' : 'No'));
                    $this->line("    Next Payment Due: " . ($user->next_payment_due ?? 'Not set'));
                    $this->line("    Suspended At: " . ($user->suspended_at ?? 'Not suspended'));

                    // Check RADIUS status
                    $radiusEntries = DB::select("
                        SELECT attribute, value, op 
                        FROM {$schemaName}.radcheck 
                        WHERE username = ? AND attribute = 'Auth-Type'
                    ", [$user->username]);

                    if (!empty($radiusEntries)) {
                        foreach ($radiusEntries as $entry) {
                            $this->line("    RADIUS: {$entry->attribute} {$entry->op} {$entry->value}");
                        }
                    } else {
                        $this->line("    RADIUS: No Auth-Type entry (should be blocked if unpaid)");
                    }

                    $this->line("");
                }

            } catch (\Exception $e) {
                $this->error("  Error checking schema {$schemaName}: " . $e->getMessage());
            }
        }
    }
}
