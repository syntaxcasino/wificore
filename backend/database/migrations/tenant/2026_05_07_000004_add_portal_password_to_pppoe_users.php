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
        Schema::table('pppoe_users', function (Blueprint $table) {
            // Portal password for customer self-service (different from PPPoE client password)
            $table->string('portal_password', 255)->nullable()->after('account_number')
                ->comment('Hashed portal password for customer self-service portal');
            
            // Account balance for prepaid/flexi accounts
            $table->decimal('balance', 12, 2)->default(0)->after('payment_status')
                ->comment('Account balance for payments/vouchers');
        });

        // Add index for faster account number lookups (important for portal login)
        Schema::table('pppoe_users', function (Blueprint $table) {
            $table->index('account_number', 'idx_pppoe_account_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pppoe_users', function (Blueprint $table) {
            $table->dropColumn(['portal_password', 'balance']);
            $table->dropIndex('idx_pppoe_account_number');
        });
    }
};
