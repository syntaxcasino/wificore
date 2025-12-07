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
        Schema::table('routers', function (Blueprint $table) {
            // VPN configuration fields - VPN is MANDATORY for all routers
            $table->ipAddress('vpn_ip')->nullable()->after('ip_address');
            $table->enum('vpn_status', ['pending', 'active', 'inactive', 'error'])->default('pending')->after('vpn_ip');
            $table->boolean('vpn_enabled')->default(true)->after('vpn_status');
            $table->timestamp('vpn_last_handshake')->nullable()->after('vpn_enabled');
            
            // Index for VPN queries
            $table->index('vpn_ip');
            $table->index('vpn_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropIndex(['vpn_ip']);
            $table->dropIndex(['vpn_status']);
            $table->dropColumn(['vpn_ip', 'vpn_status', 'vpn_enabled', 'vpn_last_handshake']);
        });
    }
};
