<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasTableInCurrentSchema = function ($tableName) {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                ) as exists
            ", [$tableName]);

            return (bool) ($result->exists ?? false);
        };

        if ($hasTableInCurrentSchema('pppoe_users')) {
            return;
        }

        Schema::create('pppoe_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('username')->unique();
            $table->string('account_number', 20)->unique();
            $table->string('password');
            $table->string('portal_password', 255)->nullable();

            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 30)->nullable();

            $table->uuid('package_id');
            $table->uuid('router_id');

            $table->timestamp('expires_at')->nullable();
            $table->string('rate_limit', 50)->nullable();
            $table->unsignedInteger('simultaneous_use')->default(1);

            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('active');
            $table->string('payment_status', 20)->default('unpaid');
            $table->timestamp('last_payment_date')->nullable();
            $table->timestamp('next_payment_due')->nullable();
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('balance', 10, 2)->default(0);

            $table->boolean('in_grace_period')->default(false);
            $table->timestamp('grace_period_ends')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();

            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_invoice_sent_at')->nullable();
            $table->timestamp('last_receipt_sent_at')->nullable();

            $table->string('payment_method', 50)->nullable();
            $table->string('payment_reference', 100)->nullable();

            $table->timestamp('paused_at')->nullable();
            $table->timestamp('pause_ends_at')->nullable();
            $table->string('pause_reason', 100)->nullable();
            $table->unsignedSmallInteger('pause_count')->default(0);
            $table->date('pause_billing_cycle_start')->nullable();
            $table->unsignedSmallInteger('pause_duration_days')->nullable();

            $table->uuid('pending_package_id')->nullable();
            $table->timestamp('plan_switch_effective_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('package_id')
                ->references('id')
                ->on('packages')
                ->onDelete('restrict');

            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');

            $table->index('router_id');
            $table->index('package_id');
            $table->index('status');
            $table->index('is_active');
            $table->index('expires_at');
            $table->index('payment_status');
            $table->index('next_payment_due');
            $table->index('in_grace_period');
            $table->index('account_number', 'idx_pppoe_users_account_number');
            $table->index(['username', 'status'], 'idx_pppoe_users_username_status');
            $table->index(['id', 'username', 'account_number', 'status'], 'idx_pppoe_users_portal_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_users');
    }
};
