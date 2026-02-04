<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApplyTenantIndexes extends Command
{
    protected $signature = 'tenant:apply-indexes 
                            {--tenant= : Specific tenant ID to apply indexes to}
                            {--dry-run : Show what indexes would be created without executing}';

    protected $description = 'Apply performance indexes to tenant schemas for faster data loading';

    private array $indexDefinitions = [
        // PPPoE Users
        'pppoe_users' => [
            'pppoe_users_status_router_id_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_users_status_router_id_idx ON pppoe_users (status, router_id)',
            'pppoe_users_payment_status_expires_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_users_payment_status_expires_idx ON pppoe_users (payment_status, expires_at)',
            'pppoe_users_created_at_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_users_created_at_idx ON pppoe_users (created_at DESC)',
            'pppoe_users_deleted_at_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_users_deleted_at_idx ON pppoe_users (deleted_at) WHERE deleted_at IS NULL',
        ],
        // Hotspot Users
        'hotspot_users' => [
            'hotspot_users_status_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_status_idx ON hotspot_users (status)',
            'hotspot_users_is_active_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_is_active_idx ON hotspot_users (is_active)',
            'hotspot_users_has_active_subscription_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_has_active_subscription_idx ON hotspot_users (has_active_subscription)',
            'hotspot_users_last_login_at_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_last_login_at_idx ON hotspot_users (last_login_at DESC)',
            'hotspot_users_mac_address_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_mac_address_idx ON hotspot_users (mac_address)',
            'hotspot_users_created_at_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_created_at_idx ON hotspot_users (created_at DESC)',
            'hotspot_users_deleted_at_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_users_deleted_at_idx ON hotspot_users (deleted_at) WHERE deleted_at IS NULL',
        ],
        // Hotspot Sessions
        'hotspot_sessions' => [
            'hotspot_sessions_mac_address_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_sessions_mac_address_idx ON hotspot_sessions (mac_address)',
            'hotspot_sessions_session_start_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_sessions_session_start_idx ON hotspot_sessions (session_start DESC)',
            'hotspot_sessions_session_end_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_sessions_session_end_idx ON hotspot_sessions (session_end)',
            'hotspot_sessions_active_user_idx' => 'CREATE INDEX IF NOT EXISTS hotspot_sessions_active_user_idx ON hotspot_sessions (is_active, hotspot_user_id)',
        ],
        // RADIUS Accounting
        'radacct' => [
            'radacct_callingstationid_idx' => 'CREATE INDEX IF NOT EXISTS radacct_callingstationid_idx ON radacct (callingstationid)',
            'radacct_framedipaddress_idx' => 'CREATE INDEX IF NOT EXISTS radacct_framedipaddress_idx ON radacct (framedipaddress)',
            'radacct_acctterminatecause_idx' => 'CREATE INDEX IF NOT EXISTS radacct_acctterminatecause_idx ON radacct (acctterminatecause)',
            'radacct_active_sessions_idx' => 'CREATE INDEX IF NOT EXISTS radacct_active_sessions_idx ON radacct (acctstoptime) WHERE acctstoptime IS NULL',
            'radacct_username_start_idx' => 'CREATE INDEX IF NOT EXISTS radacct_username_start_idx ON radacct (username, acctstarttime DESC)',
        ],
        // RADIUS Check/Reply
        'radcheck' => [
            'radcheck_username_idx' => 'CREATE INDEX IF NOT EXISTS radcheck_username_idx ON radcheck (username)',
        ],
        'radreply' => [
            'radreply_username_idx' => 'CREATE INDEX IF NOT EXISTS radreply_username_idx ON radreply (username)',
        ],
        // Routers
        'routers' => [
            'routers_name_idx' => 'CREATE INDEX IF NOT EXISTS routers_name_idx ON routers (name)',
            'routers_created_at_idx' => 'CREATE INDEX IF NOT EXISTS routers_created_at_idx ON routers (created_at DESC)',
            'routers_status_created_idx' => 'CREATE INDEX IF NOT EXISTS routers_status_created_idx ON routers (status, created_at DESC)',
            'routers_device_type_idx' => 'CREATE INDEX IF NOT EXISTS routers_device_type_idx ON routers (device_type)',
            'routers_snmp_enabled_idx' => 'CREATE INDEX IF NOT EXISTS routers_snmp_enabled_idx ON routers (snmp_enabled)',
        ],
        // Router Services
        'router_services' => [
            'router_services_created_at_idx' => 'CREATE INDEX IF NOT EXISTS router_services_created_at_idx ON router_services (created_at DESC)',
            'router_services_router_status_idx' => 'CREATE INDEX IF NOT EXISTS router_services_router_status_idx ON router_services (router_id, status)',
        ],
        // Vouchers
        'vouchers' => [
            'vouchers_created_at_idx' => 'CREATE INDEX IF NOT EXISTS vouchers_created_at_idx ON vouchers (created_at DESC)',
            'vouchers_status_package_idx' => 'CREATE INDEX IF NOT EXISTS vouchers_status_package_idx ON vouchers (status, package_id)',
            'vouchers_deleted_at_idx' => 'CREATE INDEX IF NOT EXISTS vouchers_deleted_at_idx ON vouchers (deleted_at) WHERE deleted_at IS NULL',
        ],
        // Payments
        'payments' => [
            'payments_status_created_idx' => 'CREATE INDEX IF NOT EXISTS payments_status_created_idx ON payments (status, created_at DESC)',
            'payments_package_id_idx' => 'CREATE INDEX IF NOT EXISTS payments_package_id_idx ON payments (package_id)',
        ],
        // User Subscriptions
        'user_subscriptions' => [
            'user_subscriptions_package_id_idx' => 'CREATE INDEX IF NOT EXISTS user_subscriptions_package_id_idx ON user_subscriptions (package_id)',
            'user_subscriptions_created_at_idx' => 'CREATE INDEX IF NOT EXISTS user_subscriptions_created_at_idx ON user_subscriptions (created_at DESC)',
        ],
        // Radius Sessions
        'radius_sessions' => [
            'radius_sessions_created_at_idx' => 'CREATE INDEX IF NOT EXISTS radius_sessions_created_at_idx ON radius_sessions (created_at DESC)',
            'radius_sessions_mac_address_idx' => 'CREATE INDEX IF NOT EXISTS radius_sessions_mac_address_idx ON radius_sessions (mac_address)',
        ],
        // VPN Configurations
        'vpn_configurations' => [
            'vpn_configurations_router_id_idx' => 'CREATE INDEX IF NOT EXISTS vpn_configurations_router_id_idx ON vpn_configurations (router_id)',
            'vpn_configurations_status_idx' => 'CREATE INDEX IF NOT EXISTS vpn_configurations_status_idx ON vpn_configurations (status)',
        ],
        // Access Points
        'access_points' => [
            'access_points_created_at_idx' => 'CREATE INDEX IF NOT EXISTS access_points_created_at_idx ON access_points (created_at DESC)',
            'access_points_mac_address_idx' => 'CREATE INDEX IF NOT EXISTS access_points_mac_address_idx ON access_points (mac_address)',
            'access_points_status_router_idx' => 'CREATE INDEX IF NOT EXISTS access_points_status_router_idx ON access_points (status, router_id)',
        ],
        // PPPoE Payments
        'pppoe_payments' => [
            'pppoe_payments_pppoe_user_id_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_payments_pppoe_user_id_idx ON pppoe_payments (pppoe_user_id)',
            'pppoe_payments_status_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_payments_status_idx ON pppoe_payments (status)',
            'pppoe_payments_created_at_idx' => 'CREATE INDEX IF NOT EXISTS pppoe_payments_created_at_idx ON pppoe_payments (created_at DESC)',
        ],
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tenantId = $this->option('tenant');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $query = Tenant::where('is_active', true);
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->error('No active tenants found.');
            return Command::FAILURE;
        }

        $this->info("📊 Applying performance indexes to {$tenants->count()} tenant(s)...\n");

        $totalIndexes = 0;
        $totalCreated = 0;

        foreach ($tenants as $tenant) {
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🏢 Tenant: {$tenant->name} ({$tenant->schema_name})");

            if (!$tenant->schema_name) {
                $this->warn("   ⚠️  Skipping - no schema name");
                continue;
            }

            try {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");

                $createdCount = 0;

                foreach ($this->indexDefinitions as $table => $indexes) {
                    $tableExists = $this->tableExists($table);
                    
                    if (!$tableExists) {
                        continue;
                    }

                    foreach ($indexes as $indexName => $sql) {
                        $totalIndexes++;

                        if ($dryRun) {
                            $this->line("   📝 Would create: {$indexName} on {$table}");
                            $createdCount++;
                        } else {
                            try {
                                DB::statement($sql);
                                $createdCount++;
                                $this->line("   ✅ Created: {$indexName}");
                            } catch (\Exception $e) {
                                if (str_contains($e->getMessage(), 'already exists')) {
                                    $this->line("   ⏭️  Exists: {$indexName}");
                                } else {
                                    $this->warn("   ❌ Failed: {$indexName} - {$e->getMessage()}");
                                }
                            }
                        }
                    }
                }

                $totalCreated += $createdCount;
                $this->info("   📈 Indexes processed: {$createdCount}");

            } catch (\Exception $e) {
                $this->error("   ❌ Error: {$e->getMessage()}");
                Log::error('Failed to apply indexes to tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                DB::statement('SET search_path TO public');
            }
        }

        $this->line("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("✨ Complete! Processed {$totalCreated}/{$totalIndexes} indexes across {$tenants->count()} tenant(s)");

        if (!$dryRun) {
            $this->info("💡 Run ANALYZE on tables for optimal query planning:");
            $this->line("   docker exec -it wificore-db psql -U admin -d wms_770_ts -c 'ANALYZE;'");
        }

        return Command::SUCCESS;
    }

    private function tableExists(string $table): bool
    {
        $result = DB::selectOne("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = CURRENT_SCHEMA()
                AND table_name = ?
            ) as exists
        ", [$table]);

        return (bool) ($result->exists ?? false);
    }
}
