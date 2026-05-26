<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the foreign key constraint on vouchers.used_by to allow
     * both regular users (users.id) and PPPoE users (pppoe_users.id)
     * to redeem vouchers.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Drop the foreign key if it exists
            // The constraint name follows Laravel's convention: {table}_{column}_foreign
            try {
                $table->dropForeign(['used_by']);
            } catch (\Exception $e) {
                // Constraint might not exist or have a different name
                // Try common alternative names
                $alternatives = [
                    'vouchers_used_by_foreign',
                    'vouchers_used_by_fk',
                    'used_by_foreign',
                ];
                
                foreach ($alternatives as $name) {
                    try {
                        $table->dropForeign($name);
                        break;
                    } catch (\Exception $e) {
                        // Continue to next alternative
                    }
                }
            }
        });
    }

    /**
     * Reverse the migration by re-adding the foreign key.
     * Note: This will fail if PPPoE users have already redeemed vouchers.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Note: We cannot safely re-add this constraint because
            // PPPoE user IDs won't exist in the users table
            // If needed, manual intervention would be required
        });
    }
};
