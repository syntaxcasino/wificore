<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaintainPartitions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'partitions:maintain
                            {--schema= : Specific tenant schema to maintain (optional)}
                            {--dry-run : Show what would be done without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pg_partman maintenance to create new partitions and drop old ones';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $schema = $this->option('schema');
        $dryRun = $this->option('dry-run');

        try {
            $this->info('Starting partition maintenance...');
            
            if ($dryRun) {
                $this->warn('DRY RUN MODE - No changes will be made');
            }

            // Run pg_partman maintenance
            if (!$dryRun) {
                DB::select('SELECT partman.run_maintenance_proc()');
                $this->info('✓ Partition maintenance completed');
            } else {
                $this->info('Would run: SELECT partman.run_maintenance_proc()');
            }

            // Get partition statistics
            $stats = $this->getPartitionStats($schema);
            
            if (!empty($stats)) {
                $this->newLine();
                $this->info('Partition Statistics:');
                $this->table(
                    ['Schema', 'Table', 'Total Size', 'Partition Count'],
                    $stats
                );
            }

            // Run ANALYZE for query optimization
            if (!$dryRun) {
                DB::statement('ANALYZE');
                $this->info('✓ Database statistics updated (ANALYZE)');
            } else {
                $this->info('Would run: ANALYZE');
            }

            // Log success
            Log::info('Partition maintenance completed successfully', [
                'schema' => $schema ?? 'all',
                'dry_run' => $dryRun,
            ]);

            $this->newLine();
            $this->info('Partition maintenance completed successfully!');
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Partition maintenance failed: ' . $e->getMessage());
            Log::error('Partition maintenance failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return Command::FAILURE;
        }
    }

    /**
     * Get partition statistics for monitoring
     */
    private function getPartitionStats(?string $schema = null): array
    {
        $query = "
            SELECT 
                schemaname,
                tablename,
                pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as total_size,
                (SELECT count(*) 
                 FROM pg_inherits 
                 WHERE inhparent = (schemaname||'.'||tablename)::regclass) as partition_count
            FROM pg_tables
            WHERE tablename IN ('radacct', 'radpostauth', 'water_transactions', 'jobs')
        ";

        if ($schema) {
            $query .= " AND schemaname = :schema";
            return DB::select($query, ['schema' => $schema]);
        }

        $query .= " ORDER BY schemaname, tablename";
        return DB::select($query);
    }
}
