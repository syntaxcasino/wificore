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
        Schema::create('ap_active_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('access_point_id');
            $table->uuid('router_id')->nullable();
            $table->string('username', 100)->nullable();
            $table->string('mac_address', 17);
            $table->string('ip_address', 45)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->bigInteger('bytes_in')->default(0);
            $table->bigInteger('bytes_out')->default(0);
            $table->integer('signal_strength')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('access_point_id')
                ->references('id')
                ->on('access_points')
                ->onDelete('cascade');

            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('access_point_id');
            $table->index('router_id');
            $table->index('username');
            $table->index('mac_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ap_active_sessions');
    }
};
