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
        Schema::create('access_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('router_id')->nullable();
            $table->string('name', 100);
            $table->string('vendor', 50);
            $table->string('model', 100)->nullable();
            $table->string('ip_address', 45);
            $table->string('mac_address', 17)->nullable();
            $table->string('management_protocol', 20)->default('snmp');
            $table->json('credentials')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->integer('active_users')->default(0);
            $table->integer('total_capacity')->nullable();
            $table->integer('signal_strength')->nullable();
            $table->bigInteger('uptime_seconds')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Foreign key
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('router_id');
            $table->index('vendor');
            $table->index('status');
            $table->index('ip_address');
        });

        // Check constraints (PostgreSQL) - must be after table creation
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE access_points ADD CONSTRAINT access_points_vendor_check CHECK (vendor IN ('ruijie', 'tenda', 'tplink', 'mikrotik', 'ubiquiti', 'other'))");
            DB::statement("ALTER TABLE access_points ADD CONSTRAINT access_points_protocol_check CHECK (management_protocol IN ('snmp', 'ssh', 'api', 'telnet', 'http'))");
            DB::statement("ALTER TABLE access_points ADD CONSTRAINT access_points_status_check CHECK (status IN ('online', 'offline', 'unknown', 'error'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_points');
    }
};
