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
        Schema::create('router_vpn_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('router_id');
            
            // WireGuard configuration
            $table->string('wireguard_public_key');
            $table->text('wireguard_private_key')->nullable(); // Encrypted
            $table->ipAddress('vpn_ip_address'); // 10.10.10.X
            $table->integer('listen_port')->default(13231);
            
            // Connection status
            $table->boolean('vpn_connected')->default(false);
            $table->timestamp('last_handshake')->nullable();
            $table->bigInteger('bytes_received')->default(0);
            $table->bigInteger('bytes_sent')->default(0);
            
            // RADIUS configuration
            $table->ipAddress('radius_server_ip')->default('10.10.10.1');
            $table->integer('radius_auth_port')->default(1812);
            $table->integer('radius_acct_port')->default(1813);
            $table->string('radius_secret');
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');
            
            $table->timestamps();
            
            // Indexes
            $table->unique('router_id');
            $table->unique('vpn_ip_address');
            $table->unique('wireguard_public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_vpn_configs');
    }
};
