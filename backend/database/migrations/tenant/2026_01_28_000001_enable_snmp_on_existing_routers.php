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
        // Enable SNMPv3 on all existing routers that don't have it explicitly configured
        // Generate secure credentials for each router
        $defaultUser = env('TELEGRAF_SNMPV3_USER', 'snmpmonitor');
        $defaultAuthPassword = env('TELEGRAF_SNMPV3_AUTH_PASSWORD', bin2hex(random_bytes(16)));
        $defaultPrivPassword = env('TELEGRAF_SNMPV3_PRIV_PASSWORD', bin2hex(random_bytes(16)));
        
        DB::statement("
            UPDATE routers 
            SET snmp_enabled = true, 
                snmp_version = 'v3',
                snmp_v3_user = '{$defaultUser}',
                snmp_v3_auth_protocol = 'SHA256',
                snmp_v3_auth_password = '{$defaultAuthPassword}',
                snmp_v3_priv_protocol = 'AES',
                snmp_v3_priv_password = '{$defaultPrivPassword}'
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
