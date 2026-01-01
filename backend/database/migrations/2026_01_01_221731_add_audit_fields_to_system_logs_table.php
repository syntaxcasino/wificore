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
        Schema::table('system_logs', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('tenant_id');
            $table->string('category', 50)->nullable()->after('user_id')->index();
            $table->string('ip_address', 45)->nullable()->after('details');
            $table->text('user_agent')->nullable()->after('ip_address');
            
            // Add indexes for common queries
            $table->index(['tenant_id', 'category', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['category', 'action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'category', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['category', 'action', 'created_at']);
            
            $table->dropColumn(['user_id', 'category', 'ip_address', 'user_agent']);
        });
    }
};
