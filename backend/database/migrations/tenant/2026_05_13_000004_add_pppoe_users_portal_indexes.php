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
        // Merged into 2026_01_20_000001_create_tenant_pppoe_users_table.php
    }

    public function down(): void
    {
        // Merged into 2026_01_20_000001_create_tenant_pppoe_users_table.php
    }
};
