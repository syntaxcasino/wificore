<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Moves the packages table from public schema to tenant schema.
     * Packages are tenant-specific data and should live in tenant schemas
     * for proper schema-based isolation (no tenant_id column needed).
     */
    public function up(): void
    {
        // Helper to check if table exists in CURRENT schema (ignoring public in search path)
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

        // 1. Create packages table in tenant schema (without tenant_id)
        if (!$hasTableInCurrentSchema('packages')) {
            Schema::create('packages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // NO tenant_id - schema isolation provides tenancy
                $table->string('type');
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('duration', 50);
                $table->string('upload_speed', 50);
                $table->string('download_speed', 50);
                $table->string('speed', 50)->nullable();
                $table->float('price');
                $table->integer('devices');
                $table->string('data_limit', 50)->nullable();
                $table->string('validity', 50)->nullable();
                $table->boolean('enable_burst')->default(false);
                $table->boolean('enable_schedule')->default(false);
                $table->timestamp('scheduled_activation_time')->nullable();
                $table->timestamp('scheduled_deactivation_time')->nullable();
                $table->boolean('hide_from_client')->default(false);
                $table->boolean('is_global')->default(true);
                $table->string('status', 20)->default('active');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_public')->default(true);
                $table->integer('users_count')->default(0);
                $table->timestamps();

                $table->index('type');
                $table->index('status');
                $table->index('is_active');
                $table->index('is_public');
                $table->index('is_global');
                $table->index('scheduled_activation_time');
                $table->index('scheduled_deactivation_time');
            });

            // Migrate data from public schema if exists
            try {
                $this->migratePackagesFromPublic();
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate packages from public schema: " . $e->getMessage());
            }
        }

    }

    /**
     * Migrate packages data from public schema to tenant schema
     */
    protected function migratePackagesFromPublic(): void
    {
        // Check if public packages table exists
        $publicTableExists = DB::selectOne("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = 'packages'
            ) as exists
        ");

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
            \Log::warning("Could not determine tenant ID for schema {$tenantSchema} during packages migration");
            return;
        }

        \Log::info("Migrating packages for tenant {$tenantId} ({$tenantSchema})");

        // Check if public.packages has tenant_id column (legacy migration path)
        $hasTenantId = Schema::connection('pgsql')->hasColumn("public.packages", 'tenant_id');
        if (!$hasTenantId) {
            \Log::info("Skipping packages migration: public.packages doesn't have tenant_id column (expected in current architecture)");
            return;
        }

        // Get packages belonging to this tenant (legacy path)
        $packages = DB::table('public.packages')->where('tenant_id', $tenantId)->get();

        foreach ($packages as $package) {
            $rowArray = (array) $package;
            // Remove tenant_id as it's not needed in tenant schema
            unset($rowArray['tenant_id']);

            try {
                DB::table('packages')->insert($rowArray);
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate package {$package->id}: " . $e->getMessage());
            }
        }

        \Log::info("Migrated " . count($packages) . " packages for tenant {$tenantId}");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
