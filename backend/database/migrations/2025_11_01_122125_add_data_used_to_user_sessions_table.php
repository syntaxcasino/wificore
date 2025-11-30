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
        Schema::table('user_sessions', function (Blueprint $table) {
            // Add data usage tracking columns
            $table->bigInteger('data_used')->default(0)->comment('Total data used in bytes');
            $table->bigInteger('data_upload')->default(0)->comment('Upload data in bytes');
            $table->bigInteger('data_download')->default(0)->comment('Download data in bytes');
            
            // Add indexes for performance
            $table->index('data_used');
            $table->index(['tenant_id', 'data_used']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'data_used']);
            $table->dropIndex(['data_used']);
            $table->dropColumn(['data_used', 'data_upload', 'data_download']);
        });
    }
};
