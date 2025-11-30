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
        Schema::create('service_control_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->string('action', 50);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('radius_response')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('subscription_id')
                ->references('id')
                ->on('user_subscriptions')
                ->onDelete('set null');

            // Indexes
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('subscription_id');
            $table->index('action');
            $table->index('status');
            $table->index('created_at');
        });

        // Check constraints (PostgreSQL) - must be after table creation
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE service_control_logs ADD CONSTRAINT service_control_logs_action_check CHECK (action IN ('disconnect', 'reconnect', 'suspend', 'activate', 'terminate'))");
            DB::statement("ALTER TABLE service_control_logs ADD CONSTRAINT service_control_logs_status_check CHECK (status IN ('pending', 'completed', 'failed', 'retrying'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_control_logs');
    }
};
