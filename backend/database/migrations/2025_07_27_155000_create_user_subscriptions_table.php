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
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('package_id');
            $table->uuid('payment_id')->nullable();
            $table->string('mac_address', 17);
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->string('status', 20)->default('active');
            $table->string('mikrotik_username')->nullable();
            $table->string('mikrotik_password')->nullable();
            $table->bigInteger('data_used_mb')->default(0);
            $table->integer('time_used_minutes')->default(0);
            $table->date('next_payment_date')->nullable();
            $table->integer('grace_period_days')->default(3);
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('disconnected_at')->nullable();
            $table->string('disconnection_reason')->nullable();
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
            
            // Indexes
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('end_time');
            $table->index('next_payment_date');
        });

        // Check constraint (PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE user_subscriptions ADD CONSTRAINT user_subscriptions_status_check CHECK (status IN ('active', 'expired', 'suspended', 'grace_period', 'disconnected', 'cancelled'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};
