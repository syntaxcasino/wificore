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
        // Helper to check if table exists in CURRENT schema (ignoring public in search path)
        $hasTableInCurrentSchema = function($tableName) {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                ) as exists
            ", [$tableName]);
            return $result->exists;
        };

        // Create vpn_configurations table in tenant schema
        if (!$hasTableInCurrentSchema('vpn_configurations')) {
            Schema::create('vpn_configurations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('router_id')->nullable();
                $table->unsignedBigInteger('tenant_vpn_tunnel_id')->nullable();
                
                // VPN Type
                $table->string('vpn_type', 20)->default('wireguard');
                
                // WireGuard Configuration
                $table->text('server_public_key')->nullable();
                $table->text('server_private_key')->nullable();
                $table->text('client_public_key')->nullable();
                $table->text('client_private_key')->nullable();
                $table->string('preshared_key', 255)->nullable();
                
                // Network Configuration
                $table->ipAddress('server_ip');
                $table->ipAddress('client_ip');
                $table->string('subnet_cidr', 20);
                $table->integer('listen_port')->default(51820);
                
                // Server Endpoint
                $table->string('server_endpoint', 255);
                $table->string('server_public_ip', 255)->nullable();
                
                // Connection Status
                $table->string('status', 20)->default('pending');
                $table->timestamp('last_handshake_at')->nullable();
                $table->bigInteger('rx_bytes')->default(0);
                $table->bigInteger('tx_bytes')->default(0);
                
                // Configuration Scripts
                $table->text('mikrotik_script')->nullable();
                $table->text('linux_script')->nullable();
                
                // Metadata
                $table->string('interface_name', 50)->default('wg0');
                $table->integer('keepalive_interval')->default(25);
                $table->json('allowed_ips')->nullable();
                $table->json('dns_servers')->nullable();
                
                $table->timestamps();
                $table->softDeletes();
                
                // Indexes
                $table->index('router_id');
                $table->index('status');
                $table->index('tenant_vpn_tunnel_id');
                $table->unique('client_ip');

                // Foreign Keys
                // Router is in tenant schema
                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
                
                // Tenant VPN Tunnel is in public schema
                $table->foreign('tenant_vpn_tunnel_id')->references('id')->on(new \Illuminate\Database\Query\Expression('public.tenant_vpn_tunnels'))->onDelete('cascade');
            });
        }
        
        // Create vpn_subnet_allocations table in tenant schema
        if (!$hasTableInCurrentSchema('vpn_subnet_allocations')) {
            Schema::create('vpn_subnet_allocations', function (Blueprint $table) {
                $table->bigIncrements('id');
                
                // Subnet allocation
                $table->string('subnet_cidr', 20);
                $table->integer('subnet_octet_2')->unique();
                $table->ipAddress('gateway_ip');
                $table->ipAddress('range_start');
                $table->ipAddress('range_end');
                
                // Usage tracking
                $table->integer('total_ips')->default(65534);
                $table->integer('allocated_ips')->default(0);
                $table->integer('available_ips')->default(65534);
                
                // Status
                $table->string('status', 20)->default('active');
                
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_subnet_allocations');
        Schema::dropIfExists('vpn_configurations');
    }
};
