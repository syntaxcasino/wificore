<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add optimized indexes for PPPoE user portal lookups
 * 
 * CRITICAL for login performance:
 * - Account number lookups
 * - Username lookups  
 * - Status + account combination queries
 */
return new class extends Migration
{
    private function columnExists(string $table, string $column): bool
    {
        // Tenant migrations run with `SET LOCAL search_path TO <tenant>, public`.
        // `current_schema()` is therefore the tenant schema for this transaction.
        $row = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = ?
                  AND column_name = ?
            ) AS exists",
            [$table, $column]
        );

        $val = $row->exists ?? false;

        // PostgreSQL may return booleans as 't'/'f' strings.
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val)) {
            return $val === 1;
        }
        if (is_string($val)) {
            return in_array(strtolower($val), ['1', 't', 'true', 'y', 'yes'], true);
        }

        return false;
    }

    public function up(): void
    {
        // OPTIMIZATION: Index for account number lookups (login)
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_pppoe_users_account_number 
            ON pppoe_users(account_number)
        ');

        // OPTIMIZATION: Composite index for username + status (login by username)
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_pppoe_users_username_status 
            ON pppoe_users(username, status)
        ');

        // OPTIMIZATION: Index for status filtering (active users)
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_pppoe_users_status_active 
            ON pppoe_users(status) 
            WHERE status = \'active\'
        ');

        // OPTIMIZATION: Covering index for portal queries
        // NOTE: pppoe_users is schema-isolated in tenant schemas, so it does NOT have tenant_id column.
        // The tenant_id is implicit via the schema name (search_path).
        $columns = ['id', 'username', 'account_number', 'status'];

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS idx_pppoe_users_portal_lookup ON pppoe_users(%s)',
            implode(', ', $columns)
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_users_account_number');
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_users_username_status');
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_users_status_active');
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_users_portal_lookup');
    }
};
