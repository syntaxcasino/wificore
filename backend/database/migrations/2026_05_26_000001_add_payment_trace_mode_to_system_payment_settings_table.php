<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_payment_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('system_payment_settings', 'payment_trace_mode')) {
                $table->string('payment_trace_mode', 20)->default('stdout')->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_payment_settings', function (Blueprint $table) {
            if (Schema::hasColumn('system_payment_settings', 'payment_trace_mode')) {
                $table->dropColumn('payment_trace_mode');
            }
        });
    }
};
