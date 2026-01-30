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

        if ($hasTableInCurrentSchema('pppoe_payments')) {
            return;
        }

        Schema::create('pppoe_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pppoe_user_id');
            $table->string('account_number', 20);
            
            // Payment details
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 50); // paybill, manual, mpesa, bank, cash
            $table->string('payment_reference', 100)->nullable();
            $table->string('transaction_id', 100)->nullable();
            
            // Payment status
            $table->string('status', 20)->default('pending'); // pending, completed, failed, reversed
            $table->timestamp('payment_date');
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable(); // User ID who verified manual payment
            
            // Period covered by payment
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            
            // Additional info
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // Store paybill details, etc.
            
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign keys
            $table->foreign('pppoe_user_id')
                ->references('id')
                ->on('pppoe_users')
                ->onDelete('cascade');
            
            // Indexes
            $table->index('pppoe_user_id');
            $table->index('account_number');
            $table->index('status');
            $table->index('payment_date');
            $table->index('payment_reference');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_payments');
    }
};
