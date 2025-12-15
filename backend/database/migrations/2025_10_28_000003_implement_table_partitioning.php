<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * NOTE: This migration is DISABLED because table partitioning is now handled
     * by PostgreSQL initialization scripts (postgres/partitioning-setup.sql).
     * The PostgreSQL approach provides:
     * - Better control over partition configuration
     * - Proper pg_partman integration
     * - Daily partitioning for high-volume RADIUS tables (radacct, radpostauth)
     * - Automated partition maintenance via pg_cron
     * - 90-day retention policy
     * 
     * Tables that need partitioning should be configured in the PostgreSQL
     * init scripts, not in Laravel migrations.
     */
    public function up(): void
    {
        // Skip - partitioning handled by PostgreSQL init scripts
        return;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reverting partitioning is complex and may result in data loss
        // This is a simplified version for development only
        
        DB::statement('DROP EXTENSION IF EXISTS pg_partman CASCADE');
        
        // Recreate tables without partitioning (development only)
        // In production, you would need a more sophisticated rollback strategy
    }

    /**
     * Partition a table by month
     */
    private function partitionTable(string $tableName): void
    {
        // Check if table exists
        $tableExists = DB::select("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = ?
        ", [$tableName]);

        if (empty($tableExists)) {
            // Table doesn't exist, skip partitioning
            return;
        }

        // Rename existing table
        DB::statement("ALTER TABLE {$tableName} RENAME TO {$tableName}_old");

        // Create new partitioned table (excluding constraints that can't be partitioned)
        // We exclude CONSTRAINTS and INDEXES because primary keys must include partition column
        DB::statement("
            CREATE TABLE {$tableName} (
                LIKE {$tableName}_old 
                INCLUDING DEFAULTS 
                INCLUDING GENERATED
            )
            PARTITION BY RANGE (created_at)
        ");

        // Recreate indexes on the parent table (they'll be inherited by partitions)
        // Note: We skip primary key constraint as it can't include partition column
        DB::statement("
            CREATE INDEX IF NOT EXISTS {$tableName}_created_at_idx 
            ON {$tableName} (created_at)
        ");

        // Copy data from old table to new partitioned table
        // This will be distributed to partitions automatically
        DB::statement("
            INSERT INTO {$tableName} SELECT * FROM {$tableName}_old
        ");

        // Drop old table with CASCADE to handle foreign key dependencies
        // Foreign keys will be automatically recreated pointing to the new partitioned table
        DB::statement("DROP TABLE {$tableName}_old CASCADE");
    }

    /**
     * Create initial partitions for current and future months
     */
    private function createInitialPartitions(): void
    {
        $tables = ['payments', 'user_sessions', 'system_logs', 'hotspot_sessions'];
        
        foreach ($tables as $table) {
            // Create partitions for current month and next 3 months
            for ($i = 0; $i < 4; $i++) {
                $date = now()->addMonths($i);
                $partitionName = $table . '_' . $date->format('Y_m');
                $startDate = $date->startOfMonth()->format('Y-m-d');
                $endDate = $date->copy()->addMonth()->startOfMonth()->format('Y-m-d');

                DB::statement("
                    CREATE TABLE IF NOT EXISTS {$partitionName}
                    PARTITION OF {$table}
                    FOR VALUES FROM ('{$startDate}') TO ('{$endDate}')
                ");

                // Create indexes on partition
                DB::statement("
                    CREATE INDEX IF NOT EXISTS {$partitionName}_tenant_id_idx 
                    ON {$partitionName} (tenant_id)
                ");

                DB::statement("
                    CREATE INDEX IF NOT EXISTS {$partitionName}_created_at_idx 
                    ON {$partitionName} (created_at)
                ");
            }
        }
    }

    /**
     * Setup automatic partition maintenance using pg_partman
     */
    private function setupAutomaticPartitionMaintenance(): void
    {
        $tables = ['payments', 'user_sessions', 'system_logs', 'hotspot_sessions'];

        foreach ($tables as $table) {
            // Register table with pg_partman
            DB::statement("
                SELECT partman.create_parent(
                    p_parent_table := 'public.{$table}',
                    p_control := 'created_at',
                    p_type := 'native',
                    p_interval := '1 month',
                    p_premake := 3,
                    p_start_partition := '" . now()->startOfMonth()->format('Y-m-d') . "'
                )
            ");

            // Configure partition retention (keep 12 months)
            DB::statement("
                UPDATE partman.part_config 
                SET retention = '12 months',
                    retention_keep_table = false,
                    retention_keep_index = false
                WHERE parent_table = 'public.{$table}'
            ");
        }

        // Create cron job entry for partition maintenance
        // This should be run daily: SELECT partman.run_maintenance();
        DB::statement("
            COMMENT ON EXTENSION pg_partman IS 
            'Run maintenance: SELECT partman.run_maintenance(); (schedule daily via cron)'
        ");
    }

    private function supportsPgPartman(): bool
    {
        $result = DB::select("
            SELECT 1
            FROM pg_available_extensions
            WHERE name = 'pg_partman'
            LIMIT 1
        ");

        return ! empty($result);
    }
};
