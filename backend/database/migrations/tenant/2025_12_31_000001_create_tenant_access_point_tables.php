<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

        // Access Points Table
        if (!$hasTableInCurrentSchema('access_points')) {
            Schema::create('access_points', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id is removed as table is in tenant schema
                $table->uuid('router_id')->nullable();
                $table->string('name', 100);
                $table->string('vendor', 50);
                $table->string('model', 100)->nullable();
                $table->string('ip_address', 45);
                $table->string('mac_address', 17)->nullable();
                $table->string('serial_number', 100)->nullable(); // Ensure this is included
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

                // Foreign Keys
                // Router is in tenant schema
                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');

                // Indexes
                $table->index('router_id');
                $table->index('vendor');
                $table->index('status');
                $table->index('ip_address');
                $table->index('serial_number');
            });

            // Constraints
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE access_points ADD CONSTRAINT access_points_vendor_check CHECK (vendor IN ('ruijie', 'tenda', 'tplink', 'mikrotik', 'ubiquiti', 'other'))");
                DB::statement("ALTER TABLE access_points ADD CONSTRAINT access_points_protocol_check CHECK (management_protocol IN ('snmp', 'ssh', 'api', 'telnet', 'http'))");
                DB::statement("ALTER TABLE access_points ADD CONSTRAINT access_points_status_check CHECK (status IN ('online', 'offline', 'unknown', 'error'))");
            }
        }

        // AP Active Sessions Table
        if (!$hasTableInCurrentSchema('ap_active_sessions')) {
            Schema::create('ap_active_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
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
                $table->foreign('access_point_id')
                    ->references('id')
                    ->on('access_points') // Local schema reference
                    ->onDelete('cascade');

                $table->foreign('router_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.routers'))
                    ->onDelete('cascade');

                // Indexes
                $table->index('access_point_id');
                $table->index('router_id');
                $table->index('username');
                $table->index('mac_address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ap_active_sessions');
        Schema::dropIfExists('access_points');
    }
};
