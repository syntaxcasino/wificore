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
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('reminder_type', 50);
            $table->integer('days_before_due')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->string('channel', 20);
            $table->string('status', 20)->default('sent');
            $table->json('response')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('subscription_id')
                ->references('id')
                ->on('user_subscriptions')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('subscription_id');
            $table->index('reminder_type');
            $table->index('sent_at');
        });

        // Check constraints (PostgreSQL) - must be after table creation
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_type_check CHECK (reminder_type IN ('due_soon', 'overdue', 'grace_period', 'disconnected', 'final_warning'))");
            DB::statement("ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_channel_check CHECK (channel IN ('email', 'sms', 'in_app', 'push'))");
            DB::statement("ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_status_check CHECK (status IN ('sent', 'failed', 'pending', 'delivered'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
