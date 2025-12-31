<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Starting Force Tenant Migrations...\n";

$tenants = Tenant::all();

foreach ($tenants as $tenant) {
    echo "\nProcessing Tenant: {$tenant->name} (Schema: {$tenant->schema_name})\n";
    
    // 1. Set search path
    DB::statement("SET search_path TO {$tenant->schema_name}, public");
    echo "Set search_path to {$tenant->schema_name}, public\n";

    // 2. Define migrations to check/run
    $migrations = [
        '2025_01_01_000001_create_tenant_radius_tables.php' => [
            'tables' => ['radcheck', 'radreply', 'radgroupcheck', 'radgroupreply', 'radusergroup', 'radacct', 'radpostauth', 'nas'],
            'path' => database_path('migrations/tenant/2025_01_01_000001_create_tenant_radius_tables.php')
        ],
        '2025_12_05_000000_create_tenant_router_tables.php' => [
            'tables' => ['routers', 'router_services', 'wireguard_peers'],
            'path' => database_path('migrations/tenant/2025_12_05_000000_create_tenant_router_tables.php')
        ],
        '2025_12_06_000001_create_tenant_vpn_tables.php' => [
            'tables' => ['vpn_configurations', 'vpn_subnet_allocations'],
            'path' => database_path('migrations/tenant/2025_12_06_000001_create_tenant_vpn_tables.php')
        ],
        '2025_12_31_000001_create_tenant_access_point_tables.php' => [
            'tables' => ['access_points', 'ap_active_sessions'],
            'path' => database_path('migrations/tenant/2025_12_31_000001_create_tenant_access_point_tables.php')
        ],
        '2025_12_31_000004_create_tenant_router_extra_tables.php' => [
            'tables' => ['router_vpn_configs', 'router_configs'],
            'path' => database_path('migrations/tenant/2025_12_31_000004_create_tenant_router_extra_tables.php')
        ],
        '2025_12_31_000006_move_package_router_to_tenant_schema.php' => [
            'tables' => ['package_router'],
            'path' => database_path('migrations/tenant/2025_12_31_000006_move_package_router_to_tenant_schema.php')
        ],
        '2025_12_31_000011_create_tenant_payment_tables.php' => [
            'tables' => ['payments', 'user_subscriptions', 'payment_reminders', 'service_control_logs'],
            'path' => database_path('migrations/tenant/2025_12_31_000011_create_tenant_payment_tables.php')
        ],
        '2025_12_31_000013_move_hotspot_entities_to_tenant.php' => [
            'tables' => ['hotspot_users', 'hotspot_sessions', 'user_sessions', 'vouchers', 'radius_sessions', 'hotspot_credentials', 'session_disconnections', 'data_usage_logs'],
            'path' => database_path('migrations/tenant/2025_12_31_000013_move_hotspot_entities_to_tenant.php')
        ]
    ];

    foreach ($migrations as $name => $info) {
        $allTablesExist = true;
        foreach ($info['tables'] as $table) {
            $exists = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = ? 
                    AND table_name = ?
                ) as exists
            ", [$tenant->schema_name, $table]);
            
            if (!$exists->exists) {
                echo "Table '{$table}' missing in schema '{$tenant->schema_name}'.\n";
                $allTablesExist = false;
            } else {
                echo "Table '{$table}' exists.\n";
            }
        }

        if (!$allTablesExist) {
            echo "Running migration: $name\n";
            if (file_exists($info['path'])) {
                $migration = require $info['path'];
                try {
                    $migration->up();
                    echo "Migration executed successfully.\n";
                    
                    // Record migration if not exists
                    $migrationName = pathinfo($name, PATHINFO_FILENAME);
                    $exists = DB::table('public.tenant_schema_migrations')
                        ->where('tenant_id', $tenant->id)
                        ->where('migration', $migrationName)
                        ->exists();
                        
                    if (!$exists) {
                         DB::table('public.tenant_schema_migrations')->insert([
                            'id' => \Illuminate\Support\Str::uuid(),
                            'tenant_id' => $tenant->id,
                            'migration' => $migrationName,
                            'batch' => 999,
                            'executed_at' => now()
                        ]);
                        echo "Recorded migration in tenant_schema_migrations.\n";
                    }
                } catch (\Exception $e) {
                    echo "Error running migration: " . $e->getMessage() . "\n";
                    echo $e->getTraceAsString() . "\n";
                }
            } else {
                echo "Migration file not found: {$info['path']}\n";
            }
        } else {
            echo "All tables for $name exist. Skipping.\n";
        }
    }
    
    // Reset search path
    DB::statement("SET search_path TO public");
}

echo "\nDone.\n";
