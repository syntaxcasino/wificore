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

        // 1. Create router_vpn_configs table
        if (!$hasTableInCurrentSchema('router_vpn_configs')) {
            Schema::create('router_vpn_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // tenant_id removed - schema scoped
                $table->uuid('router_id');
                
                // WireGuard configuration
                $table->string('wireguard_public_key');
                $table->text('wireguard_private_key')->nullable(); // Encrypted
                $table->ipAddress('vpn_ip_address'); // 10.10.10.X
                $table->integer('listen_port')->default(13231);
                
                // Connection status
                $table->boolean('vpn_connected')->default(false);
                $table->timestamp('last_handshake')->nullable();
                $table->bigInteger('bytes_received')->default(0);
                $table->bigInteger('bytes_sent')->default(0);
                
                // RADIUS configuration
                $table->ipAddress('radius_server_ip')->default('10.10.10.1');
                $table->integer('radius_auth_port')->default(1812);
                $table->integer('radius_acct_port')->default(1813);
                $table->string('radius_secret');
                
                $table->timestamps();
                
                // Foreign Keys
                // routers table is in the same tenant schema
                $table->foreign('router_id')
                    ->references('id')
                    ->on('routers')
                    ->onDelete('cascade');
                
                // Indexes
                $table->unique('router_id');
                $table->unique('vpn_ip_address');
                $table->unique('wireguard_public_key');
            });
            
            try {
                $this->migrateFromPublic('router_vpn_configs');
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate router_vpn_configs: " . $e->getMessage());
            }
        }

        // 2. Create router_configs table
        if (!$hasTableInCurrentSchema('router_configs')) {
            Schema::create('router_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('router_id');
                $table->string('config_type', 50);
                $table->json('config_data')->nullable();
                $table->text('config_content')->nullable();
                $table->timestamps();
                
                // Foreign Keys
                $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
                
                // Indexes
                $table->index('router_id');
                $table->index('config_type');
            });
            
            try {
                $this->migrateFromPublic('router_configs');
            } catch (\Exception $e) {
                \Log::warning("Failed to migrate router_configs: " . $e->getMessage());
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
        } elseif (Schema::connection('pgsql')->hasColumn("public.{$tableName}", 'router_id')) {
            // Filter by routers belonging to this tenant
            // Since public.routers might be deleted, we check if the router exists in the CURRENT TENANT SCHEMA
            $query->whereIn('router_id', function($q) use ($tenantSchema) {
                $q->select('id')
                  ->from("{$tenantSchema}.routers");
            });
        } else {
            \Log::warning("Skipping migration for {$tableName}: No tenant_id or router_id to filter by.");
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
        Schema::dropIfExists('router_configs');
        Schema::dropIfExists('router_vpn_configs');
    }
};
