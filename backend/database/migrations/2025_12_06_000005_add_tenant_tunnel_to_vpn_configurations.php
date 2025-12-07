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
        Schema::table('vpn_configurations', function (Blueprint $table) {
            // Add reference to tenant VPN tunnel
            $table->foreignId('tenant_vpn_tunnel_id')
                  ->after('tenant_id')
                  ->nullable()
                  ->constrained('tenant_vpn_tunnels')
                  ->onDelete('cascade');
            
            // Add index for faster lookups
            $table->index('tenant_vpn_tunnel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_configurations', function (Blueprint $table) {
            $table->dropForeign(['tenant_vpn_tunnel_id']);
            $table->dropIndex(['tenant_vpn_tunnel_id']);
            $table->dropColumn('tenant_vpn_tunnel_id');
        });
    }
};
