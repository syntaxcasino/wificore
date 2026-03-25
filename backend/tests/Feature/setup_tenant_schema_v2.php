<?php
/**
 * One-time script: create ts_testing schema and run all tenant migrations on it.
 * Run inside wificore-test-runner container.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$schema = 'ts_testing';

// 1. Create schema
DB::statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
echo "Schema {$schema} created/verified\n";

// 2. Set search_path so all migrations run inside the tenant schema
DB::statement("SET search_path TO {$schema}, public");
echo "search_path = {$schema}, public\n";

// 3. Run each tenant migration file by instantiating the class
$migrationPath = base_path('database/migrations/tenant');
$files = glob($migrationPath . '/*.php');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    // Load the file to get the class name registered in PHP
    $before = get_declared_classes();
    include_once $file;
    $after = get_declared_classes();
    $new = array_diff($after, $before);

    if (empty($new)) {
        // Class already declared (included previously), find it by checking all anonymous/named classes
        echo "  SKIP (already loaded) {$name}\n";
        continue;
    }

    $className = array_pop($new);

    try {
        $instance = new $className();
        $instance->up();
        echo "  OK  {$name} ({$className})\n";
    } catch (\Throwable $e) {
        echo "  SKIP {$name}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Checking tables:\n";
$tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = '{$schema}' ORDER BY tablename");
foreach ($tables as $t) {
    echo "  - {$t->tablename}\n";
}
