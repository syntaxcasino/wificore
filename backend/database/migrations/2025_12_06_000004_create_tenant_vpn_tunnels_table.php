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
        Schema::create('tenant_vpn_tunnels', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->unique();
            $table->string('interface_name', 10)->unique(); // wg0, wg1, wg2, etc.
            $table->text('server_private_key'); // Encrypted
            $table->text('server_public_key');
            $table->ipAddress('server_ip'); // 10.X.0.1
            $table->string('subnet_cidr', 20); // 10.X.0.0/16
            $table->integer('listen_port'); // 51820, 51821, etc.
            $table->enum('status', ['active', 'inactive', 'error'])->default('active');
            $table->timestamp('last_handshake_at')->nullable();
            $table->integer('connected_peers')->default(0);
            $table->bigInteger('bytes_received')->default(0);
            $table->bigInteger('bytes_sent')->default(0);
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('status');
            $table->index('interface_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_vpn_tunnels');
    }
};
