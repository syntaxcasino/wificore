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
        Schema::create('vpn_configurations', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable(); // Nullable - table is in tenant schema
            $table->uuid('router_id')->nullable();
            
            // VPN Type (wireguard, ipsec, etc.)
            $table->enum('vpn_type', ['wireguard', 'ipsec'])->default('wireguard');
            
            // WireGuard Configuration
            $table->text('server_public_key')->nullable();
            $table->text('server_private_key')->nullable(); // Encrypted
            $table->text('client_public_key')->nullable();
            $table->text('client_private_key')->nullable(); // Encrypted
            $table->string('preshared_key')->nullable(); // Encrypted, optional extra security
            
            // Network Configuration
            $table->ipAddress('server_ip'); // e.g., 10.100.0.1
            $table->ipAddress('client_ip'); // e.g., 10.100.1.1
            $table->string('subnet_cidr', 20); // e.g., 10.100.0.0/16 (tenant-specific)
            $table->integer('listen_port')->default(51820);
            
            // Server Endpoint (public IP/domain)
            $table->string('server_endpoint'); // e.g., vpn.example.com:51820
            $table->string('server_public_ip')->nullable();
            
            // Connection Status
            $table->enum('status', ['pending', 'active', 'inactive', 'error'])->default('pending');
            $table->timestamp('last_handshake_at')->nullable();
            $table->bigInteger('rx_bytes')->default(0);
            $table->bigInteger('tx_bytes')->default(0);
            
            // Configuration Scripts
            $table->text('mikrotik_script')->nullable(); // Generated MikroTik script
            $table->text('linux_script')->nullable(); // Generated Linux script (if needed)
            
            // Metadata
            $table->string('interface_name', 50)->default('wg0'); // WireGuard interface name
            $table->integer('keepalive_interval')->default(25); // Persistent keepalive
            $table->json('allowed_ips')->nullable(); // IPs allowed through tunnel
            $table->json('dns_servers')->nullable(); // DNS servers for tunnel
            
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign keys
            // Note: tenant_id foreign key removed - table is in tenant schema
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
            
            // Indexes
            $table->index('router_id');
            $table->index('status');
            $table->unique('client_ip'); // Unique per tenant (schema provides isolation)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vpn_configurations');
    }
};
