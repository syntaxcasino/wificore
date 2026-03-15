<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration implements proper schema-based multi-tenancy by:
     * 1. Creating RADIUS tables in each tenant schema
     * 2. Migrating existing RADIUS data to appropriate tenant schemas
     * 3. Keeping only system admin RADIUS entries in public schema
     */
    public function up(): void
    {
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;
            
            // Skip if schema doesn't exist
            $schemaExists = DB::selectOne("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?", [$schemaName]);
            if (!$schemaExists) {
                \Log::warning("Schema {$schemaName} does not exist for tenant {$tenant->id}, skipping RADIUS table creation");
                continue;
            }
            
            \Log::info("Creating RADIUS tables in schema: {$schemaName}");
            
            // Set search path to tenant schema
            DB::statement("SET search_path TO {$schemaName}, public");
            
            // Create radcheck table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radcheck")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radcheck (
                        id SERIAL PRIMARY KEY,
                        username VARCHAR(64) NOT NULL DEFAULT '',
                        attribute VARCHAR(64) NOT NULL DEFAULT '',
                        op CHAR(2) NOT NULL DEFAULT '==',
                        value VARCHAR(253) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                
                // Create indexes
                DB::statement("CREATE INDEX idx_{$schemaName}_radcheck_username ON {$schemaName}.radcheck(username)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radcheck_username_attr ON {$schemaName}.radcheck(username, attribute)");
            }
            
            // Create radreply table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radreply")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radreply (
                        id SERIAL PRIMARY KEY,
                        username VARCHAR(64) NOT NULL DEFAULT '',
                        attribute VARCHAR(64) NOT NULL DEFAULT '',
                        op CHAR(2) NOT NULL DEFAULT '=',
                        value VARCHAR(253) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                
                // Create indexes
                DB::statement("CREATE INDEX idx_{$schemaName}_radreply_username ON {$schemaName}.radreply(username)");
            }
            
            // Create radgroupcheck table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radgroupcheck")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radgroupcheck (
                        id SERIAL PRIMARY KEY,
                        groupname VARCHAR(64) NOT NULL DEFAULT '',
                        attribute VARCHAR(64) NOT NULL DEFAULT '',
                        op CHAR(2) NOT NULL DEFAULT '==',
                        value VARCHAR(253) NOT NULL DEFAULT ''
                    )
                ");
                
                DB::statement("CREATE INDEX idx_{$schemaName}_radgroupcheck_groupname ON {$schemaName}.radgroupcheck(groupname)");
            }
            
            // Create radgroupreply table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radgroupreply")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radgroupreply (
                        id SERIAL PRIMARY KEY,
                        groupname VARCHAR(64) NOT NULL DEFAULT '',
                        attribute VARCHAR(64) NOT NULL DEFAULT '',
                        op CHAR(2) NOT NULL DEFAULT '=',
                        value VARCHAR(253) NOT NULL DEFAULT ''
                    )
                ");
                
                DB::statement("CREATE INDEX idx_{$schemaName}_radgroupreply_groupname ON {$schemaName}.radgroupreply(groupname)");
            }
            
            // Create radusergroup table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radusergroup")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radusergroup (
                        id SERIAL PRIMARY KEY,
                        username VARCHAR(64) NOT NULL DEFAULT '',
                        groupname VARCHAR(64) NOT NULL DEFAULT '',
                        priority INT NOT NULL DEFAULT 0
                    )
                ");
                
                DB::statement("CREATE INDEX idx_{$schemaName}_radusergroup_username ON {$schemaName}.radusergroup(username)");
            }
            
            // Create radacct table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radacct")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radacct (
                        radacctid BIGSERIAL PRIMARY KEY,
                        acctsessionid VARCHAR(64) NOT NULL,
                        acctuniqueid VARCHAR(32) NOT NULL,
                        username VARCHAR(64) NOT NULL DEFAULT '',
                        realm VARCHAR(64) DEFAULT '',
                        nasipaddress INET NOT NULL,
                        nasportid VARCHAR(32) DEFAULT NULL,
                        nasporttype VARCHAR(32) DEFAULT NULL,
                        acctstarttime TIMESTAMP NULL DEFAULT NULL,
                        acctupdatetime TIMESTAMP NULL DEFAULT NULL,
                        acctstoptime TIMESTAMP NULL DEFAULT NULL,
                        acctinterval INT DEFAULT NULL,
                        acctsessiontime INT DEFAULT NULL,
                        acctauthentic VARCHAR(32) DEFAULT NULL,
                        connectinfo_start VARCHAR(128) DEFAULT NULL,
                        connectinfo_stop VARCHAR(128) DEFAULT NULL,
                        acctinputoctets BIGINT DEFAULT NULL,
                        acctoutputoctets BIGINT DEFAULT NULL,
                        calledstationid VARCHAR(50) DEFAULT NULL,
                        callingstationid VARCHAR(50) DEFAULT NULL,
                        acctterminatecause VARCHAR(32) DEFAULT NULL,
                        servicetype VARCHAR(32) DEFAULT NULL,
                        framedprotocol VARCHAR(32) DEFAULT NULL,
                        framedipaddress INET DEFAULT NULL,
                        framedipv6address VARCHAR(45) DEFAULT NULL,
                        framedipv6prefix VARCHAR(45) DEFAULT NULL,
                        framedinterfaceid VARCHAR(44) DEFAULT NULL,
                        delegatedipv6prefix VARCHAR(45) DEFAULT NULL,
                        class VARCHAR(64) DEFAULT NULL
                    )
                ");
                
                // Create indexes for radacct
                DB::statement("CREATE INDEX idx_{$schemaName}_radacct_username ON {$schemaName}.radacct(username)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radacct_session ON {$schemaName}.radacct(acctsessionid)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radacct_unique ON {$schemaName}.radacct(acctuniqueid)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radacct_start ON {$schemaName}.radacct(acctstarttime)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radacct_stop ON {$schemaName}.radacct(acctstoptime)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radacct_nasip ON {$schemaName}.radacct(nasipaddress)");
            }
            
            // Create radpostauth table in tenant schema
            if (!Schema::hasTable("{$schemaName}.radpostauth")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.radpostauth (
                        id BIGSERIAL PRIMARY KEY,
                        username VARCHAR(64) NOT NULL DEFAULT '',
                        pass VARCHAR(64) NOT NULL DEFAULT '',
                        reply VARCHAR(32) NOT NULL DEFAULT '',
                        authdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        class VARCHAR(64) DEFAULT NULL
                    )
                ");
                
                DB::statement("CREATE INDEX idx_{$schemaName}_radpostauth_username ON {$schemaName}.radpostauth(username)");
                DB::statement("CREATE INDEX idx_{$schemaName}_radpostauth_date ON {$schemaName}.radpostauth(authdate)");
            }
            
            // Migrate existing RADIUS data for this tenant's users
            $this->migrateRadiusDataToTenantSchema($tenant->id, $schemaName);
        }
        
        // Reset search path to public
        DB::statement("SET search_path TO public");
        
        // Clean up public schema RADIUS tables - keep only system admin entries
        $this->cleanupPublicSchemaRadiusTables();
        
        \Log::info("Schema-based multi-tenancy for RADIUS completed successfully");
    }

    /**
     * Migrate RADIUS data from public schema to tenant schema
     */
    protected function migrateRadiusDataToTenantSchema(string $tenantId, string $schemaName): void
    {
        // Get all users for this tenant
        $users = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('role', '!=', 'system_admin')
            ->get();
        
        foreach ($users as $user) {
            // Migrate radcheck entries
            $radcheckEntries = DB::table('radcheck')
                ->where('username', $user->username)
                ->get();
            
            foreach ($radcheckEntries as $entry) {
                DB::statement("
                    INSERT INTO {$schemaName}.radcheck (username, attribute, op, value, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT DO NOTHING
                ", [
                    $entry->username,
                    $entry->attribute,
                    $entry->op,
                    $entry->value,
                    $entry->created_at ?? now(),
                    $entry->updated_at ?? now()
                ]);
            }
            
            // Migrate radreply entries
            $radreplyEntries = DB::table('radreply')
                ->where('username', $user->username)
                ->get();
            
            foreach ($radreplyEntries as $entry) {
                DB::statement("
                    INSERT INTO {$schemaName}.radreply (username, attribute, op, value, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT DO NOTHING
                ", [
                    $entry->username,
                    $entry->attribute,
                    $entry->op,
                    $entry->value,
                    $entry->created_at ?? now(),
                    $entry->updated_at ?? now()
                ]);
            }
            
            // Migrate radacct entries (accounting data)
            DB::statement("
                INSERT INTO {$schemaName}.radacct 
                SELECT * FROM public.radacct 
                WHERE username = ?
                ON CONFLICT DO NOTHING
            ", [$user->username]);
            
            // Migrate radpostauth entries (authentication logs)
            DB::statement("
                INSERT INTO {$schemaName}.radpostauth 
                SELECT * FROM public.radpostauth 
                WHERE username = ?
                ON CONFLICT DO NOTHING
            ", [$user->username]);
            
            \Log::info("Migrated RADIUS data for user {$user->username} to schema {$schemaName}");
        }
    }

    /**
     * Clean up public schema RADIUS tables - keep only system admin entries
     */
    protected function cleanupPublicSchemaRadiusTables(): void
    {
        // Get system admin usernames
        $systemAdminUsernames = DB::table('users')
            ->where('role', 'system_admin')
            ->pluck('username')
            ->toArray();
        
        if (empty($systemAdminUsernames)) {
            \Log::warning("No system admin users found, skipping public schema cleanup");
            return;
        }
        
        // Delete non-system-admin entries from public.radcheck
        DB::table('radcheck')
            ->whereNotIn('username', $systemAdminUsernames)
            ->delete();
        
        // Delete non-system-admin entries from public.radreply
        DB::table('radreply')
            ->whereNotIn('username', $systemAdminUsernames)
            ->delete();
        
        // Delete non-system-admin entries from public.radacct
        DB::table('radacct')
            ->whereNotIn('username', $systemAdminUsernames)
            ->delete();
        
        // Delete non-system-admin entries from public.radpostauth
        DB::table('radpostauth')
            ->whereNotIn('username', $systemAdminUsernames)
            ->delete();
        
        \Log::info("Cleaned up public schema RADIUS tables, kept only system admin entries");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;
            
            // Drop RADIUS tables from tenant schema
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radpostauth CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radacct CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radusergroup CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radgroupreply CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radgroupcheck CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radreply CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.radcheck CASCADE");
            
            \Log::info("Dropped RADIUS tables from schema: {$schemaName}");
        }
        
        \Log::info("Rolled back schema-based multi-tenancy for RADIUS");
    }
};
