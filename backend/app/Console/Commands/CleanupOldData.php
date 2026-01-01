<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanupOldData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:cleanup {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old data according to retention policies';

    /**
     * Data retention policies (in days)
     */
    protected $retentionPolicies = [
        'system_logs' => 90,           // Keep logs for 90 days
        'failed_jobs' => 30,           // Keep failed jobs for 30 days
        'jobs' => 7,                   // Keep completed jobs for 7 days
        'sessions' => 30,              // Keep old sessions for 30 days
        'password_reset_tokens' => 1,  // Keep tokens for 1 day
        'personal_access_tokens' => 365, // Keep revoked tokens for 1 year
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('DRY RUN MODE - No data will be deleted');
        }
        
        $this->info('Starting data cleanup based on retention policies...');
        $this->newLine();
        
        $totalDeleted = 0;
        
        // Clean up each table according to retention policy
        foreach ($this->retentionPolicies as $table => $retentionDays) {
            $deleted = $this->cleanupTable($table, $retentionDays, $isDryRun);
            $totalDeleted += $deleted;
        }
        
        // Clean up tenant-specific data
        $tenantDeleted = $this->cleanupTenantData($isDryRun);
        $totalDeleted += $tenantDeleted;
        
        $this->newLine();
        
        if ($isDryRun) {
            $this->info("DRY RUN: Would delete {$totalDeleted} records");
        } else {
            $this->info("Successfully deleted {$totalDeleted} old records");
            
            // Log cleanup
            DB::table('system_logs')->insert([
                'tenant_id' => null,
                'user_id' => null,
                'category' => 'maintenance',
                'action' => 'data_cleanup_completed',
                'details' => json_encode([
                    'records_deleted' => $totalDeleted,
                    'policies' => $this->retentionPolicies,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        
        return 0;
    }
    
    /**
     * Clean up a specific table
     */
    protected function cleanupTable(string $table, int $retentionDays, bool $isDryRun): int
    {
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        
        try {
            // Check if table exists
            if (!$this->tableExists($table)) {
                $this->warn("Table '{$table}' does not exist, skipping...");
                return 0;
            }
            
            // Count records to be deleted
            $query = DB::table($table)->where('created_at', '<', $cutoffDate);
            $count = $query->count();
            
            if ($count === 0) {
                $this->line("✓ {$table}: No old records to delete");
                return 0;
            }
            
            if ($isDryRun) {
                $this->line("→ {$table}: Would delete {$count} records older than {$retentionDays} days");
                return $count;
            }
            
            // Delete old records
            $deleted = $query->delete();
            $this->info("✓ {$table}: Deleted {$deleted} records older than {$retentionDays} days");
            
            return $deleted;
            
        } catch (\Exception $e) {
            $this->error("✗ {$table}: Error - " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Clean up tenant-specific data
     */
    protected function cleanupTenantData(bool $isDryRun): int
    {
        $totalDeleted = 0;
        
        // Get all active tenants
        $tenants = DB::table('tenants')
            ->where('is_active', true)
            ->where('schema_created', true)
            ->get();
        
        $this->newLine();
        $this->info('Cleaning up tenant-specific data...');
        
        foreach ($tenants as $tenant) {
            try {
                // Switch to tenant schema
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                
                // Clean up old user sessions (30 days)
                $deleted = $this->cleanupTenantTable(
                    'user_sessions',
                    30,
                    $isDryRun,
                    $tenant->name
                );
                $totalDeleted += $deleted;
                
                // Clean up old radius sessions (90 days)
                $deleted = $this->cleanupTenantTable(
                    'radius_sessions',
                    90,
                    $isDryRun,
                    $tenant->name
                );
                $totalDeleted += $deleted;
                
                // Clean up old data usage logs (180 days)
                $deleted = $this->cleanupTenantTable(
                    'data_usage_logs',
                    180,
                    $isDryRun,
                    $tenant->name
                );
                $totalDeleted += $deleted;
                
                // Reset search path
                DB::statement("SET search_path TO public");
                
            } catch (\Exception $e) {
                $this->error("Error cleaning tenant {$tenant->name}: " . $e->getMessage());
                DB::statement("SET search_path TO public");
            }
        }
        
        return $totalDeleted;
    }
    
    /**
     * Clean up tenant-specific table
     */
    protected function cleanupTenantTable(string $table, int $retentionDays, bool $isDryRun, string $tenantName): int
    {
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        
        try {
            if (!$this->tableExists($table)) {
                return 0;
            }
            
            $query = DB::table($table)->where('created_at', '<', $cutoffDate);
            $count = $query->count();
            
            if ($count === 0) {
                return 0;
            }
            
            if ($isDryRun) {
                $this->line("→ {$tenantName}/{$table}: Would delete {$count} records");
                return $count;
            }
            
            $deleted = $query->delete();
            $this->line("✓ {$tenantName}/{$table}: Deleted {$deleted} records");
            
            return $deleted;
            
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Check if table exists
     */
    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get retention policy for a table
     */
    public function getRetentionPolicy(string $table): ?int
    {
        return $this->retentionPolicies[$table] ?? null;
    }
}
