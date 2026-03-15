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
            if (!Schema::hasColumn('pppoe_users', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('account_number');
            }

            if (!Schema::hasColumn('pppoe_users', 'customer_email')) {
                $table->string('customer_email')->nullable()->after('customer_name');
            }

            if (!Schema::hasColumn('pppoe_users', 'customer_phone')) {
                $table->string('customer_phone', 30)->nullable()->after('customer_email');
            }

            if (!Schema::hasColumn('pppoe_users', 'last_reminder_sent_at')) {
                $table->timestamp('last_reminder_sent_at')->nullable()->after('payment_reference');
            }

            if (!Schema::hasColumn('pppoe_users', 'reminder_count')) {
                $table->unsignedInteger('reminder_count')->default(0)->after('last_reminder_sent_at');
            }

            if (!Schema::hasColumn('pppoe_users', 'last_invoice_sent_at')) {
                $table->timestamp('last_invoice_sent_at')->nullable()->after('reminder_count');
            }

            if (!Schema::hasColumn('pppoe_users', 'last_receipt_sent_at')) {
                $table->timestamp('last_receipt_sent_at')->nullable()->after('last_invoice_sent_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pppoe_users')) {
            return;
        }

        Schema::table('pppoe_users', function (Blueprint $table) {
            if (Schema::hasColumn('pppoe_users', 'last_receipt_sent_at')) {
                $table->dropColumn('last_receipt_sent_at');
            }

            if (Schema::hasColumn('pppoe_users', 'last_invoice_sent_at')) {
                $table->dropColumn('last_invoice_sent_at');
            }

            if (Schema::hasColumn('pppoe_users', 'reminder_count')) {
                $table->dropColumn('reminder_count');
            }

            if (Schema::hasColumn('pppoe_users', 'last_reminder_sent_at')) {
                $table->dropColumn('last_reminder_sent_at');
            }

            if (Schema::hasColumn('pppoe_users', 'customer_phone')) {
                $table->dropColumn('customer_phone');
            }

            if (Schema::hasColumn('pppoe_users', 'customer_email')) {
                $table->dropColumn('customer_email');
            }

            if (Schema::hasColumn('pppoe_users', 'customer_name')) {
                $table->dropColumn('customer_name');
            }
        });
    }
};
