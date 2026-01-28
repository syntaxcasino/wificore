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
        // Enable SNMP on all existing routers that don't have it explicitly disabled
        DB::statement("
            UPDATE routers 
            SET snmp_enabled = true, 
                snmp_version = 'v2c'
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
