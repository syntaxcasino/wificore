<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Fixing metrics migration...\n\n";

try {
    // Drop tables if they exist
    echo "Dropping existing tables...\n";
    DB::statement('DROP TABLE IF EXISTS worker_snapshots CASCADE');
    DB::statement('DROP TABLE IF EXISTS performance_metrics CASCADE');
    DB::statement('DROP TABLE IF EXISTS system_health_metrics CASCADE');
    DB::statement('DROP TABLE IF EXISTS queue_metrics CASCADE');
    echo "✅ Tables dropped\n\n";
    
    // Remove migration record
    echo "Removing migration record...\n";
    DB::table('migrations')
        ->where('migration', '2025_11_01_035000_create_system_metrics_tables')
        ->delete();
    echo "✅ Migration record removed\n\n";
    
    echo "✅ Done! Now restart the backend to run the migration.\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
