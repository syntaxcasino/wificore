<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add optimized indexes for PPPoE portal payment queries
 * 
 * These indexes significantly improve:
 * - Recent pending payment checks (duplicate prevention)
 * - Payment status lookups by transaction_id
 * - User's recent payments list
 */
return new class extends Migration
{
    public function up(): void
    {
        // OPTIMIZATION: Composite index for "recent pending payments" query
        // Used by: initiateMpesaPayment() - prevents duplicate STK pushes
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_pppoe_payments_user_status_created 
            ON pppoe_payments(pppoe_user_id, status, created_at DESC)
        ');

        // OPTIMIZATION: Index for transaction_id lookups with user scoping
        // Used by: checkPaymentStatus() - fast payment status checks
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_pppoe_payments_user_transaction 
            ON pppoe_payments(pppoe_user_id, transaction_id)
        ');

        // OPTIMIZATION: Index for payment_method filtering
        // Used by: initiateMpesaPayment() - pending MPesa checks
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_pppoe_payments_user_method_status_created 
            ON pppoe_payments(pppoe_user_id, payment_method, status, created_at DESC)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_payments_user_status_created');
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_payments_user_transaction');
        DB::statement('DROP INDEX IF EXISTS idx_pppoe_payments_user_method_status_created');
    }
};
