<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Switch SNMP default from v3 to v2c (SNMPv2c).
 * 
 * Most MikroTik routers ship with SNMPv2c enabled by default.
 * SNMPv3 requires explicit configuration on the router side.
 * This migration:
 * 1. Adds snmp_community column for per-router community strings
 * 2. Changes default snmp_version to '2c'
 * 3. Updates existing routers that have v3 but no v3 credentials to use v2c
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add snmp_community column if it doesn't exist
        if (!Schema::hasColumn('routers', 'snmp_community')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->string('snmp_community', 64)->default('public')->after('snmp_version');
            });
        }

        // Change default snmp_version column default to '2c' (idempotent)
        if (Schema::hasColumn('routers', 'snmp_version')) {
            $default = DB::selectOne(<<<'SQL'
                SELECT pg_get_expr(d.adbin, d.adrelid) AS default_expr
                FROM pg_attrdef d
                JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
                WHERE d.adrelid = 'routers'::regclass
                  AND a.attname = 'snmp_version'
                SQL);

            $expr = is_object($default) ? ($default->default_expr ?? null) : null;

            // pg_get_expr returns a SQL expression, e.g. '2c'::character varying
            if (!is_string($expr) || !str_contains($expr, "'2c'")) {
                // ALTER TABLE takes an ACCESS EXCLUSIVE lock even for metadata-only changes.
                // During a live system, that can hit lock_timeout and cause noisy retries.
                // Be patient here, but keep the scope limited to this statement.
                $oldLockTimeout = null;
                try {
                    $old = DB::selectOne("SHOW lock_timeout");
                    $oldLockTimeout = is_object($old) ? ($old->lock_timeout ?? null) : null;
                } catch (\Throwable) {
                    // Ignore; we'll still attempt the ALTER with a best-effort lock timeout.
                }

                try {
                    DB::statement("SET lock_timeout = '60s'");
                    DB::statement("ALTER TABLE routers ALTER COLUMN snmp_version SET DEFAULT '2c'");
                } finally {
                    if (is_string($oldLockTimeout) && $oldLockTimeout !== '') {
                        // Restore the previous session setting to avoid surprising later statements.
                        $restore = str_replace("'", "''", $oldLockTimeout);
                        DB::statement("SET lock_timeout = '{$restore}'");
                    }
                }
            }
        }

        // Update existing routers: if snmp_version is 'v3' but v3 credentials are empty,
        // switch them to '2c' since v3 won't work without credentials
        DB::statement("
            UPDATE routers 
            SET snmp_version = '2c',
                snmp_community = 'public'
            WHERE (snmp_version = 'v3' OR snmp_version = '3')
              AND (snmp_v3_user IS NULL OR snmp_v3_user = '')
              AND (snmp_v3_auth_password IS NULL OR snmp_v3_auth_password = '')
        ");

        // Also set community on any router that doesn't have one yet
        DB::statement("
            UPDATE routers 
            SET snmp_community = 'public'
            WHERE snmp_community IS NULL OR snmp_community = ''
        ");
    }

    public function down(): void
    {
        // Revert default back to v3
        if (Schema::hasColumn('routers', 'snmp_version')) {
            $oldLockTimeout = null;
            try {
                $old = DB::selectOne("SHOW lock_timeout");
                $oldLockTimeout = is_object($old) ? ($old->lock_timeout ?? null) : null;
            } catch (\Throwable) {
                // Ignore
            }

            try {
                DB::statement("SET lock_timeout = '60s'");
                DB::statement("ALTER TABLE routers ALTER COLUMN snmp_version SET DEFAULT 'v3'");
            } finally {
                if (is_string($oldLockTimeout) && $oldLockTimeout !== '') {
                    $restore = str_replace("'", "''", $oldLockTimeout);
                    DB::statement("SET lock_timeout = '{$restore}'");
                }
            }
        }

        if (Schema::hasColumn('routers', 'snmp_community')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->dropColumn('snmp_community');
            });
        }
    }
};
