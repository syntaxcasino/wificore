<?php

namespace Tests\Helpers;

use App\Models\Tenant;

/**
 * Process-level singleton that tracks which migrations have been applied
 * to the wms_testing database during this test run.
 *
 * Traits and test classes each get their own copy of static properties,
 * so we centralise migration-state flags here.
 */
class TestDatabaseState
{
    public static bool $publicMigrationsRun = false;
    public static bool $tenantSchemaMigrated = false;
    public static ?Tenant $sharedTenant = null;
    public static string $testSchemaName = 'tenant_test_payments';
}
