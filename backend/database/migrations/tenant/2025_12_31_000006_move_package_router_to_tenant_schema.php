<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Helper to check if table exists in CURRENT schema
        $hasTableInCurrentSchema = function($tableName) {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                ) as exists
            ", [$tableName]);
            return $result->exists;
        };

        if (!$hasTableInCurrentSchema('package_router')) {
            Schema::create('package_router', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('package_id');
                $table->uuid('router_id');
                // tenant_id removed as it is implied by schema
                $table->timestamps();

                // Foreign keys
                // Package is in public schema
                $table->foreign('package_id')
                    ->references('id')
                    ->on(new \Illuminate\Database\Query\Expression('public.packages'))
                    ->onDelete('cascade');
                
                // Router is in tenant schema
                $table->foreign('router_id')
                    ->references('id')
                    ->on('routers')
                    ->onDelete('cascade');

                // Indexes
                $table->index('package_id');
                $table->index('router_id');
                
                // Unique constraint
                $table->unique(['package_id', 'router_id']);
            });

            try {
                $this->migrateFromPublic('package_router');
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate package_router from public schema: " . $e->getMessage());
            }
        }
    }

    /**
     * Helper to migrate data from public schema
     */
    protected function migrateFromPublic(string $tableName): void
    {
        // Check if public table exists
        $publicTableExists = DB::selectOne("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = ?
            )
        ", [$tableName]);

        if (!$publicTableExists->exists) {
            return;
        }

        // Get tenant info from search path
        $result = DB::select("SHOW search_path");
        $searchPath = $result[0]->search_path;
        $schemas = explode(',', $searchPath);
        $tenantSchema = trim($schemas[0]);
        
        $tenantId = DB::table('public.tenants')->where('schema_name', $tenantSchema)->value('id');
        
        if (!$tenantId) {
            \Log::warning("Could not determine tenant ID for schema {$tenantSchema} during {$tableName} migration");
            return;
        }

        \Log::info("Migrating {$tableName} for tenant {$tenantId} ({$tenantSchema})");

        // Build query to fetch data for this tenant
        $query = DB::table("public.{$tableName}");
        
        // Check for tenant_id column
        $hasTenantId = Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'tenant_id');
        
        if ($hasTenantId) {
            $query->where('tenant_id', $tenantId);
        } else {
            \Log::warning("Skipping migration for {$tableName}: No tenant_id to filter by.");
            return;
        }

        $data = $query->get();
        
        foreach ($data as $row) {
            $rowArray = (array)$row;
            if (isset($rowArray['tenant_id'])) {
                unset($rowArray['tenant_id']);
            }
            
            try {
                // Ensure the referenced router exists in the tenant schema before inserting
                // This prevents foreign key violations if the router wasn't migrated/deleted
                $routerExists = DB::table('routers')->where('id', $rowArray['router_id'])->exists();
                
                if ($routerExists) {
                    DB::table($tableName)->insert($rowArray);
                } else {
                    \Log::warning("Skipping package_router row: referenced router {$rowArray['router_id']} does not exist in {$tenantSchema}.routers");
                }
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate row in {$tableName}: " . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_router');
    }
};
