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

        // 1. Create Routers Table
        if (!$hasTableInCurrentSchema('routers')) {
            Schema::create('routers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed as it is in tenant schema
                $table->string('name');
                $table->string('ip_address', 45);
                $table->string('vpn_ip', 45)->nullable();
                $table->string('vpn_status', 20)->default('inactive');
                $table->boolean('vpn_enabled')->default(false);
                $table->timestamp('vpn_last_handshake')->nullable();
                $table->string('model')->nullable();
                $table->string('os_version')->nullable();
                $table->timestamp('last_seen')->nullable();
                $table->integer('port')->default(8728);
                $table->string('username');
                $table->string('password'); // Will be encrypted by model
                $table->string('location')->nullable();
                $table->string('status', 20)->default('unknown');
                $table->string('provisioning_stage', 50)->default('pending');
                $table->json('interface_assignments')->nullable();
                $table->json('configurations')->nullable();
                $table->string('config_token', 64)->nullable();
                $table->string('vendor', 50)->default('mikrotik');
                $table->string('device_type', 50)->default('router');
                $table->json('capabilities')->nullable();
                $table->json('interface_list')->nullable();
                $table->json('reserved_interfaces')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
            
            // Migrate data from public schema if exists
            try {
                $this->migrateFromPublic('routers');
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate routers from public schema: " . $e->getMessage());
            }
        }

        // 2. Create Router Services Table
        if (!$hasTableInCurrentSchema('router_services')) {
            Schema::create('router_services', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed
                $table->uuid('router_id');
                $table->string('service_type', 50);
                $table->string('service_name', 100);
                $table->json('interfaces')->default('[]');
                $table->json('configuration')->default('{}');
                $table->string('status', 20)->default('inactive');
                $table->integer('active_users')->default(0);
                $table->integer('total_sessions')->default(0);
                $table->timestamp('last_checked_at')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
                
                $table->index('router_id');
                $table->index('service_type');
                $table->index('status');
            });
            
            $this->migrateFromPublic('router_services');
        }

        // 3. Create Wireguard Peers Table
        if (!$hasTableInCurrentSchema('wireguard_peers')) {
            Schema::create('wireguard_peers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('router_id');
                $table->string('peer_name')->nullable();
                $table->text('public_key')->nullable();
                $table->string('endpoint')->nullable();
                $table->text('allowed_ips')->nullable();
                $table->timestamp('last_handshake')->nullable();
                $table->timestamps(); // Added timestamps which were missing in original but good practice

                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
                
                $table->index('router_id');
            });
            
            $this->migrateFromPublic('wireguard_peers');
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

        // Get tenant info
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

        // Check if table has tenant_id column
        $hasTenantId = Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'tenant_id');

        $query = DB::table("public.{$tableName}");

        if ($hasTenantId) {
            $query->where('tenant_id', $tenantId);
        } elseif ($tableName === 'wireguard_peers' && Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'router_id')) {
            // Join with routers to get tenant_id
            $query->whereIn('router_id', function($q) use ($tenantId) {
                $q->select('id')
                  ->from('public.routers')
                  ->where('tenant_id', $tenantId);
            });
        } elseif ($tableName === 'router_services' && Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'router_id')) {
             // Join with routers to get tenant_id (if router_services doesn't have tenant_id, though it usually does)
             $query->whereIn('router_id', function($q) use ($tenantId) {
                $q->select('id')
                  ->from('public.routers')
                  ->where('tenant_id', $tenantId);
            });
        } else {
             \Log::warning("Skipping migration for {$tableName}: No tenant_id column and no known relationship to filter by tenant.");
             return;
        }

        $data = $query->get();
        
        foreach ($data as $row) {
            $rowArray = (array)$row;
            if (isset($rowArray['tenant_id'])) {
                unset($rowArray['tenant_id']);
            }
            
            try {
                DB::table($tableName)->insert($rowArray);
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
        Schema::dropIfExists('wireguard_peers');
        Schema::dropIfExists('router_services');
        Schema::dropIfExists('routers');
    }
};
