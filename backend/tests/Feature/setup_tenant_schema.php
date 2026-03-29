<?php
/**
 * One-time script: create ts_testing schema and run all tenant migrations on it.
 * Connects directly to postgres (not pgbouncer) to avoid transaction-mode pooling
 * resetting the search_path between queries.
 *
 * Run inside wificore-test-runner container:
 *   php /var/www/html/tests/Feature/setup_tenant_schema.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$schema = 'ts_testing';

// ---------------------------------------------------------------------------
// Force the Laravel 'pgsql' connection to use the search_path option so that
// every Schema:: call lands in ts_testing, not public.
// We also reconnect directly to postgres (not pgbouncer) because pgbouncer in
// transaction mode resets SET search_path between transactions.
// ---------------------------------------------------------------------------
$config = config('database.connections.pgsql');
$config['host']        = env('DB_HOST', 'wificore-postgres');
$config['port']        = env('DB_PORT', '5432');
$config['search_path'] = $schema . ',public';    // Laravel >=10 uses this key
$config['options']     = [
    \PDO::ATTR_PERSISTENT => false,
];
config(['database.connections.pgsql' => $config]);

// Purge any existing connection so Laravel picks up the new config
DB::purge('pgsql');
DB::reconnect('pgsql');

// 1. Create schema (must exist before search_path resolves to it)
DB::unprepared("CREATE SCHEMA IF NOT EXISTS {$schema}");
echo "Schema {$schema} created/verified\n";

// 2. Verify CURRENT_SCHEMA() is correct
$current = DB::selectOne('SELECT CURRENT_SCHEMA() as s')->s;
echo "CURRENT_SCHEMA() = {$current}\n";

if ($current !== $schema) {
    echo "ERROR: search_path not applied correctly. Aborting.\n";
    exit(1);
}

// 3. Run each tenant migration
$migrationPath = base_path('database/migrations/tenant');
$files = glob($migrationPath . '/*.php');
sort($files);

foreach ($files as $file) {
    $migration = require $file;
    $name = basename($file);
    try {
        $migration->up();
        echo "  OK  {$name}\n";
    } catch (\Throwable $e) {
        echo "  SKIP {$name}: " . $e->getMessage() . "\n";
    }
}

// 4. Report tables created
$tables = DB::select(
    "SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename",
    [$schema]
);
echo "\nTables in {$schema}:\n";
foreach ($tables as $t) {
    echo "  - {$t->tablename}\n";
}
echo "\nDone.\n";
