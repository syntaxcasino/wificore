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
        Schema::create('wireguard_peers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->string('peer_name')->nullable();
            $table->text('public_key')->nullable();
            $table->string('endpoint')->nullable();
            $table->text('allowed_ips')->nullable();
            $table->timestamp('last_handshake')->nullable();
            
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
            
            $table->index('router_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wireguard_peers');
    }
};
