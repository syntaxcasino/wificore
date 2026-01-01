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
            if (!Schema::hasColumn('system_logs', 'user_id')) {
                $table->uuid('user_id')->nullable()->after('tenant_id');
            }
            if (!Schema::hasColumn('system_logs', 'category')) {
                $table->string('category', 50)->nullable()->after('user_id')->index();
            }
            if (!Schema::hasColumn('system_logs', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('details');
            }
            if (!Schema::hasColumn('system_logs', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }
            
            // Add indexes for common queries (check if they don't exist)
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexesFound = $sm->listTableIndexes('system_logs');
            
            if (!isset($indexesFound['system_logs_tenant_id_category_created_at_index'])) {
                $table->index(['tenant_id', 'category', 'created_at']);
            }
            if (!isset($indexesFound['system_logs_user_id_created_at_index'])) {
                $table->index(['user_id', 'created_at']);
            }
            if (!isset($indexesFound['system_logs_category_action_created_at_index'])) {
                $table->index(['category', 'action', 'created_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            // Drop indexes if they exist
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexesFound = $sm->listTableIndexes('system_logs');
            
            if (isset($indexesFound['system_logs_tenant_id_category_created_at_index'])) {
                $table->dropIndex(['tenant_id', 'category', 'created_at']);
            }
            if (isset($indexesFound['system_logs_user_id_created_at_index'])) {
                $table->dropIndex(['user_id', 'created_at']);
            }
            if (isset($indexesFound['system_logs_category_action_created_at_index'])) {
                $table->dropIndex(['category', 'action', 'created_at']);
            }
            
            // Drop columns if they exist
            $columnsToCheck = ['user_id', 'category', 'ip_address', 'user_agent'];
            $columnsToDrop = [];
            
            foreach ($columnsToCheck as $column) {
                if (Schema::hasColumn('system_logs', $column)) {
                    $columnsToDrop[] = $column;
                }
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
