<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Alters existing router_services table to support zero-config provisioning
     */
    public function up(): void
    {
        Schema::table('router_services', function (Blueprint $table) {
            // Add new columns for zero-config provisioning
            if (!Schema::hasColumn('router_services', 'interface_name')) {
                $table->string('interface_name', 100)->after('router_id');
            }
            
            if (!Schema::hasColumn('router_services', 'ip_pool_id')) {
                $table->uuid('ip_pool_id')->nullable()->after('service_type');
            }
            
            if (!Schema::hasColumn('router_services', 'vlan_id')) {
                $table->integer('vlan_id')->nullable()->after('ip_pool_id');
            }
            
            if (!Schema::hasColumn('router_services', 'vlan_required')) {
                $table->boolean('vlan_required')->default(false)->after('vlan_id');
            }
            
            if (!Schema::hasColumn('router_services', 'radius_profile')) {
                $table->string('radius_profile')->nullable()->after('vlan_required');
            }
            
            if (!Schema::hasColumn('router_services', 'advanced_config')) {
                $table->jsonb('advanced_config')->nullable()->after('radius_profile');
            }
            
            if (!Schema::hasColumn('router_services', 'deployment_status')) {
                $table->enum('deployment_status', ['pending', 'deploying', 'deployed', 'failed'])
                    ->default('pending')
                    ->after('advanced_config');
            }
            
            if (!Schema::hasColumn('router_services', 'deployed_at')) {
                $table->timestamp('deployed_at')->nullable()->after('deployment_status');
            }
        });
        
        // Add foreign keys and indexes using raw SQL checks (Laravel 11+ compatible)
        Schema::table('router_services', function (Blueprint $table) {
            // Check if foreign key exists using PostgreSQL system catalogs
            $hasFkToIpPools = \DB::select("
                SELECT 1 FROM pg_constraint 
                WHERE conname LIKE '%router_services_ip_pool_id_foreign%'
                LIMIT 1
            ");
            
            if (empty($hasFkToIpPools) && Schema::hasColumn('router_services', 'ip_pool_id')) {
                $table->foreign('ip_pool_id')
                    ->references('id')
                    ->on('tenant_ip_pools')
                    ->onDelete('set null');
            }
        });
        
        // Add indexes separately to avoid conflicts
        Schema::table('router_services', function (Blueprint $table) {
            // Check and add deployment_status index
            $hasDeploymentIndex = \DB::select("
                SELECT 1 FROM pg_indexes 
                WHERE indexname = 'idx_router_services_deployment_status'
                LIMIT 1
            ");
            
            if (empty($hasDeploymentIndex) && Schema::hasColumn('router_services', 'deployment_status')) {
                $table->index('deployment_status', 'idx_router_services_deployment_status');
            }
            
            // Check and add router_deployment composite index
            $hasRouterDeploymentIndex = \DB::select("
                SELECT 1 FROM pg_indexes 
                WHERE indexname = 'idx_router_services_router_deployment'
                LIMIT 1
            ");
            
            if (empty($hasRouterDeploymentIndex) && Schema::hasColumns('router_services', ['router_id', 'deployment_status'])) {
                $table->index(['router_id', 'deployment_status'], 'idx_router_services_router_deployment');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router_services', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['ip_pool_id']);
            
            // Drop indexes
            $table->dropIndex('idx_router_services_deployment_status');
            $table->dropIndex('idx_router_services_router_deployment');
            
            // Drop columns
            $table->dropColumn([
                'interface_name',
                'ip_pool_id',
                'vlan_id',
                'vlan_required',
                'radius_profile',
                'advanced_config',
                'deployment_status',
                'deployed_at',
            ]);
        });
    }
};
