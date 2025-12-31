<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = App\Models\Tenant::first();
echo "Tenant: " . $tenant->name . " (Schema: " . $tenant->schema_name . ")\n";

$manager = app(App\Services\TenantMigrationManager::class);

// Reflection to access private method getTenantMigrationFiles
$reflection = new ReflectionClass($manager);
$method = $reflection->getMethod('getTenantMigrationFiles');
$method->setAccessible(true);
$files = $method->invoke($manager);

echo "Found " . count($files) . " migration files:\n";
foreach ($files as $file) {
    echo " - " . basename($file) . "\n";
}

echo "\nRunning migrations for tenant...\n";
$result = $manager->runMigrationsForTenant($tenant);
echo "Result: " . ($result ? 'Success' : 'Failure') . "\n";

// Check executed migrations
$executed = \Illuminate\Support\Facades\DB::table('tenant_schema_migrations')
    ->where('tenant_id', $tenant->id)
    ->pluck('migration')
    ->toArray();

echo "\nExecuted migrations in DB:\n";
foreach ($executed as $migration) {
    echo " - " . $migration . "\n";
}
