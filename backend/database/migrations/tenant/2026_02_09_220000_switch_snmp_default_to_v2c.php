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

        // Change default snmp_version column default to '2c'
        if (Schema::hasColumn('routers', 'snmp_version')) {
            DB::statement("ALTER TABLE routers ALTER COLUMN snmp_version SET DEFAULT '2c'");
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
            DB::statement("ALTER TABLE routers ALTER COLUMN snmp_version SET DEFAULT 'v3'");
        }

        if (Schema::hasColumn('routers', 'snmp_community')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->dropColumn('snmp_community');
            });
        }
    }
};
