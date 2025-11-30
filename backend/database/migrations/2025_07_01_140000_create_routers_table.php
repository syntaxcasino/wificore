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
        Schema::create('routers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->string('ip_address', 45)->nullable();
            $table->string('model')->nullable();
            $table->string('os_version', 50)->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->integer('port')->default(8728);
            $table->string('username', 100);
            $table->text('password');
            $table->string('location')->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('provisioning_stage', 50)->nullable();
            $table->json('interface_assignments')->nullable();
            $table->json('configurations')->nullable();
            $table->string('config_token')->unique()->nullable();
            $table->string('vendor', 50)->default('mikrotik');
            $table->string('device_type', 50)->default('router');
            $table->json('capabilities')->nullable();
            $table->json('interface_list')->nullable();
            $table->json('reserved_interfaces')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->index('vendor');
            $table->index('device_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
