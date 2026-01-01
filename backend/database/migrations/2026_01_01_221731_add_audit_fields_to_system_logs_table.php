<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Check if an index exists on a PostgreSQL table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select(
            "SELECT COUNT(*) as count FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        );
        
        return $result[0]->count > 0;
    }

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
                $table->string('category', 50)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('system_logs', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('details');
            }
            if (!Schema::hasColumn('system_logs', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }
        });
        
        // Add indexes separately to avoid issues with index name detection
        if (!$this->indexExists('system_logs', 'system_logs_category_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->index('category');
            });
        }
        
        if (!$this->indexExists('system_logs', 'system_logs_tenant_id_category_created_at_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->index(['tenant_id', 'category', 'created_at']);
            });
        }
        
        if (!$this->indexExists('system_logs', 'system_logs_user_id_created_at_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->index(['user_id', 'created_at']);
            });
        }
        
        if (!$this->indexExists('system_logs', 'system_logs_category_action_created_at_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->index(['category', 'action', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes if they exist
        if ($this->indexExists('system_logs', 'system_logs_category_action_created_at_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->dropIndex(['category', 'action', 'created_at']);
            });
        }
        
        if ($this->indexExists('system_logs', 'system_logs_user_id_created_at_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'created_at']);
            });
        }
        
        if ($this->indexExists('system_logs', 'system_logs_tenant_id_category_created_at_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'category', 'created_at']);
            });
        }
        
        if ($this->indexExists('system_logs', 'system_logs_category_index')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->dropIndex(['category']);
            });
        }
        
        // Drop columns if they exist
        Schema::table('system_logs', function (Blueprint $table) {
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
