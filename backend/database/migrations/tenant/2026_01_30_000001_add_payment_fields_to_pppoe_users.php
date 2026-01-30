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

        if (!$hasTableInCurrentSchema('pppoe_users')) {
            return;
        }

        Schema::table('pppoe_users', function (Blueprint $table) {
            // Account number with tenant prefix (e.g., T-00001 for Traidnet)
            $table->string('account_number', 20)->unique()->after('username');
            
            // Payment status tracking
            $table->string('payment_status', 20)->default('unpaid')->after('status');
            $table->timestamp('last_payment_date')->nullable()->after('payment_status');
            $table->timestamp('next_payment_due')->nullable()->after('last_payment_date');
            $table->decimal('amount_due', 10, 2)->default(0)->after('next_payment_due');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('amount_due');
            
            // Grace period and suspension
            $table->boolean('in_grace_period')->default(false)->after('amount_paid');
            $table->timestamp('grace_period_ends')->nullable()->after('in_grace_period');
            $table->timestamp('suspended_at')->nullable()->after('grace_period_ends');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            
            // Payment method tracking
            $table->string('payment_method', 50)->nullable()->after('suspension_reason');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
            
            // Indexes for performance
            $table->index('account_number');
            $table->index('payment_status');
            $table->index('next_payment_due');
            $table->index('in_grace_period');
        });
    }

    public function down(): void
    {
        Schema::table('pppoe_users', function (Blueprint $table) {
            $table->dropIndex(['account_number']);
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['next_payment_due']);
            $table->dropIndex(['in_grace_period']);
            
            $table->dropColumn([
                'account_number',
                'payment_status',
                'last_payment_date',
                'next_payment_due',
                'amount_due',
                'amount_paid',
                'in_grace_period',
                'grace_period_ends',
                'suspended_at',
                'suspension_reason',
                'payment_method',
                'payment_reference',
            ]);
        });
    }
};
