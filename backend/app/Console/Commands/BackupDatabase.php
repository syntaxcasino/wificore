<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup {--type=full : Backup type (full, schema, data)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a backup of the PostgreSQL database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $timestamp = Carbon::now()->format('Y-m-d_His');
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        
        $backupDir = storage_path('app/backups/database');
        
        // Create backup directory if it doesn't exist
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $filename = "{$database}_{$type}_{$timestamp}.sql";
        $filepath = "{$backupDir}/{$filename}";
        
        $this->info("Starting {$type} database backup...");
        
        // Set PGPASSWORD environment variable
        putenv("PGPASSWORD=" . config('database.connections.pgsql.password'));
        
        // Build pg_dump command based on type
        $command = $this->buildBackupCommand($type, $host, $port, $username, $database, $filepath);
        
        // Execute backup
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            $this->error("Backup failed!");
            $this->error(implode("\n", $output));
            return 1;
        }
        
        // Compress the backup
        $this->info("Compressing backup...");
        $compressedFile = "{$filepath}.gz";
        exec("gzip {$filepath}", $output, $returnVar);
        
        if ($returnVar === 0 && file_exists($compressedFile)) {
            $size = $this->formatBytes(filesize($compressedFile));
            $this->info("Backup completed successfully!");
            $this->info("File: {$filename}.gz");
            $this->info("Size: {$size}");
            $this->info("Location: {$compressedFile}");
            
            // Clean up old backups (keep last 7 days)
            $this->cleanupOldBackups($backupDir);
            
            // Log backup
            DB::table('system_logs')->insert([
                'tenant_id' => null,
                'user_id' => null,
                'category' => 'backup',
                'action' => 'database_backup_completed',
                'details' => json_encode([
                    'type' => $type,
                    'filename' => "{$filename}.gz",
                    'size' => filesize($compressedFile),
                    'timestamp' => $timestamp,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            return 0;
        }
        
        $this->error("Compression failed!");
        return 1;
    }
    
    /**
     * Build pg_dump command based on backup type
     */
    protected function buildBackupCommand($type, $host, $port, $username, $database, $filepath)
    {
        $baseCommand = "pg_dump -h {$host} -p {$port} -U {$username}";
        
        switch ($type) {
            case 'schema':
                return "{$baseCommand} --schema-only {$database} > {$filepath}";
            case 'data':
                return "{$baseCommand} --data-only {$database} > {$filepath}";
            case 'full':
            default:
                return "{$baseCommand} --clean --if-exists {$database} > {$filepath}";
        }
    }
    
    /**
     * Clean up old backups (keep last 7 days)
     */
    protected function cleanupOldBackups($backupDir)
    {
        $this->info("Cleaning up old backups...");
        
        $files = glob("{$backupDir}/*.sql.gz");
        $cutoffTime = Carbon::now()->subDays(7)->timestamp;
        $deletedCount = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
            }
        }
        
        if ($deletedCount > 0) {
            $this->info("Deleted {$deletedCount} old backup(s)");
        }
    }
    
    /**
     * Format bytes to human readable format
     */
    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
