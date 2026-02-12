<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable SNMPv2c on all existing routers that don't have SNMP enabled.
        // Uses v2c with community 'public' as the safe default — no encrypted
        // columns are touched, avoiding plaintext-in-encrypted-column bugs.
        DB::statement("
            UPDATE routers 
            SET snmp_enabled = true, 
                snmp_version = '2c',
                snmp_community = COALESCE(NULLIF(snmp_community, ''), 'public')
            WHERE snmp_enabled IS NULL 
               OR snmp_enabled = false
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't disable SNMP on rollback as it might break monitoring
        // This is a safe no-op rollback
    }
};
