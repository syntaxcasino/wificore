<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasTable = function (string $tableName): bool {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                ) as exists
            ", [$tableName]);
            return (bool) ($result->exists ?? false);
        };

        if ($hasTable('pppoe_timed_vouchers')) {
            return;
        }

        Schema::create('pppoe_timed_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pppoe_user_id');
            $table->string('account_number', 20);

            // Duration definition
            $table->string('duration_label', 50);   // e.g. "8 Hours", "1 Day"
            $table->unsignedInteger('duration_hours');  // 8, 24, 72, etc.
            $table->decimal('price', 10, 2);

            // Status lifecycle: pending_payment → active → expired / cancelled
            $table->string('status', 20)->default('pending_payment');

            // Payment linkage
            $table->string('transaction_id', 100)->nullable();  // M-Pesa CheckoutRequestID
            $table->string('payment_reference', 100)->nullable(); // M-Pesa receipt
            $table->decimal('amount_paid', 10, 2)->nullable();

            // Activation window
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pppoe_user_id')
                ->references('id')
                ->on('pppoe_users')
                ->onDelete('cascade');

            $table->index('pppoe_user_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_timed_vouchers');
    }
};
