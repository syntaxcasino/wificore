<?php

namespace Tests\Helpers;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Router;
use App\Models\Package;
use App\Models\RouterTenantMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Helper trait for tests that need a working tenant schema.
 *
 * Tenant-isolation rules enforced here:
 *   PUBLIC schema  → tenants, users, router_tenant_map, mpesa_transaction_maps,
 *                    radius core tables, system tables.
 *   TENANT schema  → packages, routers, pppoe_users, pppoe_payments, payments,
 *                    subscriptions, paybill_settings, tenant-radius tables.
 *
 * Migration order:
 *   1. Public schema migrations run ONCE per process (tracked in TestDatabaseState).
 *   2. Tenant schema is created + migrated ONCE per process (same tracker).
 *
 * Usage: use this trait in a Pest/PHPUnit TestCase, then call
 *   $this->setUpTestTenant() in setUp().
 */
trait TenantTestHelper
{
    /**
     * Bootstrap the test database for the current test.
     * Returns the shared Tenant instance.
     */
    protected function setUpTestTenant(): Tenant
    {
        // --- Step 1: public schema tables (run once per process) ---
        if (!TestDatabaseState::$publicMigrationsRun) {
            $this->runPublicMigrations();
        }

        // --- Step 2: tenant schema (run once per process) ---
        if (TestDatabaseState::$sharedTenant === null) {
            $this->createTenantAndSchema();
        }

        // Point every DB query for this connection at the tenant schema first,
        // then fall back to public for shared tables.
        $this->switchConnectionSearchPath(TestDatabaseState::$testSchemaName . ',public');

        return TestDatabaseState::$sharedTenant;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Run the PUBLIC schema migrations (tenants, users, cache, jobs, radius, …).
     * These tables live in the default 'public' schema of wms_testing.
     *
     * We configure the connection's search_path and force a reconnect BEFORE
     * calling Artisan, because SET search_path only affects the current session
     * and Artisan::call() may open a fresh connection that loses the setting.
     */
    private function runPublicMigrations(): void
    {
        $this->switchConnectionSearchPath('public');

        Artisan::call('migrate', [
            '--path'  => 'database/migrations',
            '--force' => true,
        ]);

        TestDatabaseState::$publicMigrationsRun = true;
    }

    /**
     * Create the tenant record (public schema) + PostgreSQL tenant schema +
     * run tenant-schema migrations.
     * Everything here runs ONCE per test process.
     */
    private function createTenantAndSchema(): void
    {
        // Create the PostgreSQL schema for our test tenant.
        $this->switchConnectionSearchPath('public');
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . TestDatabaseState::$testSchemaName);

        // Upsert the Tenant row into public.tenants.
        TestDatabaseState::$sharedTenant = Tenant::withoutGlobalScopes()->firstOrCreate(
            ['slug' => 'test-payments-tenant'],
            [
                'id'             => Str::uuid()->toString(),
                'name'           => 'Test Payments Tenant',
                'schema_name'    => TestDatabaseState::$testSchemaName,
                'email'          => 'test@payments.test',
                'is_active'      => true,
                'is_default'     => false,
                'is_landlord'    => false,
                'schema_created' => true,
            ]
        );

        if (!TestDatabaseState::$tenantSchemaMigrated) {
            // Switch connection to tenant schema FIRST (so the migrator's
            // `migrations` table and all DDL land in tenant_test_payments).
            $this->switchConnectionSearchPath(
                TestDatabaseState::$testSchemaName . ',public'
            );

            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant',
                '--force' => true,
            ]);

            TestDatabaseState::$tenantSchemaMigrated = true;
        }
    }

    /**
     * Set the connection's search_path in config and purge the connection pool
     * so the next query (and any Artisan::call) uses a fresh connection with
     * the correct search_path.
     */
    private function switchConnectionSearchPath(string $searchPath): void
    {
        config(['database.connections.pgsql.search_path' => $searchPath]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        // Force the sticky flag so subsequent reads (incl. migration table-existence
        // checks) use the write PDO instead of the read replica, which is not always
        // reachable from the test-runner container.
        DB::connection()->recordsHaveBeenModified();
    }

    // -------------------------------------------------------------------------
    // Public factory helpers (called by individual tests)
    // -------------------------------------------------------------------------

    /**
     * Create an admin User in the PUBLIC schema users table.
     * Called AFTER setUpTestTenant() so public search_path is available via fallback.
     */
    protected function createAdminUser(Tenant $tenant): User
    {
        $suffix = Str::random(6);

        // Temporarily ensure we write to public.users
        $this->switchConnectionSearchPath('public');

        $user = User::withoutGlobalScopes()->create([
            'id'                => Str::uuid()->toString(),
            'tenant_id'         => $tenant->id,
            'name'              => 'Test Admin',
            'username'          => 'admin_' . $suffix,
            'email'             => 'admin-' . $suffix . '@payments.test',
            'password'          => bcrypt('password'),
            'role'              => User::ROLE_ADMIN,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        // Restore tenant search_path
        $this->switchConnectionSearchPath(TestDatabaseState::$testSchemaName . ',public');

        return $user;
    }

    /**
     * Create a Package in the TENANT schema packages table.
     * Must be called after setUpTestTenant() has set the search_path.
     */
    protected function createPackage(array $overrides = []): Package
    {
        return Package::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'type'           => 'pppoe',
            'name'           => 'Test Package 100MB',
            'description'    => 'Test package',
            'price'          => 100.00,
            'duration'       => '30',
            'upload_speed'   => '10M',
            'download_speed' => '10M',
            'speed'          => '10M/10M',
            'devices'        => 1,
            'is_active'      => true,
            'is_public'      => true,
        ], $overrides));
    }

    /**
     * Create a Router in the TENANT schema routers table, and insert a mapping
     * row into the PUBLIC schema router_tenant_map table.
     */
    protected function createRouter(Tenant $tenant, array $overrides = []): Router
    {
        $routerId = Str::uuid()->toString();

        // Router lives in the tenant schema (search_path already set).
        $router = Router::create(array_merge([
            'id'         => $routerId,
            'name'       => 'Test Router',
            'ip_address' => '192.168.1.1',
            'username'   => 'admin',
            'password'   => 'router_password',
            'status'     => 'online',
        ], $overrides));

        // Mapping row lives in the public schema — temporarily switch then restore.
        $this->switchConnectionSearchPath('public');
        RouterTenantMap::updateOrCreate(
            ['router_id' => $routerId],
            ['tenant_id' => $tenant->id, 'ip_address' => '192.168.1.1']
        );
        $this->switchConnectionSearchPath(TestDatabaseState::$testSchemaName . ',public');

        return $router;
    }

    /**
     * Reset search_path to public at the end of a test.
     */
    protected function tearDownTenantContext(): void
    {
        $this->switchConnectionSearchPath('public');
    }
}
