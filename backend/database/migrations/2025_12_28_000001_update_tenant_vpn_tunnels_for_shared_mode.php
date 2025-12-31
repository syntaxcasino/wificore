<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_vpn_tunnels', function (Blueprint $table) {
            // Drop unique constraint on interface_name as it will be shared (wg0) in host mode
            $table->dropUnique(['interface_name']);
            // Add index for faster lookups
            $table->index(['tenant_id', 'interface_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_vpn_tunnels', function (Blueprint $table) {
            $table->unique('interface_name');
            $table->dropIndex(['tenant_id', 'interface_name']);
        });
    }
};
