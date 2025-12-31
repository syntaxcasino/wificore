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
     * This migration implements proper schema-based multi-tenancy for VPN by:
     * 1. Creating VPN tables in each tenant schema
     * 2. Migrating existing VPN data to appropriate tenant schemas
     * 3. Keeping only tenant_vpn_tunnels in public schema (system-level coordination)
     * 4. Moving vpn_configurations and vpn_subnet_allocations to tenant schemas
     */
    public function up(): void
    {
        \Log::info("Starting schema-based multi-tenancy implementation for VPN tables");
        
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;
            
            // Skip if schema doesn't exist
            $schemaExists = DB::selectOne("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?", [$schemaName]);
            if (!$schemaExists) {
                \Log::warning("Schema {$schemaName} does not exist for tenant {$tenant->id}, skipping VPN table creation");
                continue;
            }
            
            \Log::info("Creating VPN tables in schema: {$schemaName}");
            
            // Set search path to tenant schema
            DB::statement("SET search_path TO {$schemaName}, public");
            
            // Create vpn_configurations table in tenant schema
            if (!Schema::hasTable("{$schemaName}.vpn_configurations")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.vpn_configurations (
                        id BIGSERIAL PRIMARY KEY,
                        router_id UUID NULL,
                        tenant_vpn_tunnel_id BIGINT NULL,
                        
                        -- VPN Type
                        vpn_type VARCHAR(20) DEFAULT 'wireguard',
                        
                        -- WireGuard Configuration
                        server_public_key TEXT NULL,
                        server_private_key TEXT NULL,
                        client_public_key TEXT NULL,
                        client_private_key TEXT NULL,
                        preshared_key VARCHAR(255) NULL,
                        
                        -- Network Configuration
                        server_ip INET NOT NULL,
                        client_ip INET NOT NULL,
                        subnet_cidr VARCHAR(20) NOT NULL,
                        listen_port INT DEFAULT 51820,
                        
                        -- Server Endpoint
                        server_endpoint VARCHAR(255) NOT NULL,
                        server_public_ip VARCHAR(255) NULL,
                        
                        -- Connection Status
                        status VARCHAR(20) DEFAULT 'pending',
                        last_handshake_at TIMESTAMP NULL,
                        rx_bytes BIGINT DEFAULT 0,
                        tx_bytes BIGINT DEFAULT 0,
                        
                        -- Configuration Scripts
                        mikrotik_script TEXT NULL,
                        linux_script TEXT NULL,
                        
                        -- Metadata
                        interface_name VARCHAR(50) DEFAULT 'wg0',
                        keepalive_interval INT DEFAULT 25,
                        allowed_ips JSON NULL,
                        dns_servers JSON NULL,
                        
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        deleted_at TIMESTAMP NULL,
                        
                        // Foreign key to routers table (in public schema)
                        FOREIGN KEY (router_id) REFERENCES public.routers(id) ON DELETE CASCADE,
                        -- Foreign key to tenant_vpn_tunnels (in public schema)
                        FOREIGN KEY (tenant_vpn_tunnel_id) REFERENCES public.tenant_vpn_tunnels(id) ON DELETE CASCADE
                    )
                ");
                
                // Create indexes
                DB::statement("CREATE INDEX idx_{$schemaName}_vpn_config_router ON {$schemaName}.vpn_configurations(router_id)");
                DB::statement("CREATE INDEX idx_{$schemaName}_vpn_config_status ON {$schemaName}.vpn_configurations(status)");
                DB::statement("CREATE INDEX idx_{$schemaName}_vpn_config_tunnel ON {$schemaName}.vpn_configurations(tenant_vpn_tunnel_id)");
                DB::statement("CREATE UNIQUE INDEX idx_{$schemaName}_vpn_config_client_ip ON {$schemaName}.vpn_configurations(client_ip)");
                
                \Log::info("Created vpn_configurations table in schema: {$schemaName}");
            }
            
            // Create vpn_subnet_allocations table in tenant schema
            if (!Schema::hasTable("{$schemaName}.vpn_subnet_allocations")) {
                DB::statement("
                    CREATE TABLE {$schemaName}.vpn_subnet_allocations (
                        id BIGSERIAL PRIMARY KEY,
                        
                        -- Subnet allocation
                        subnet_cidr VARCHAR(20) NOT NULL,
                        subnet_octet_2 INT UNIQUE NOT NULL,
                        gateway_ip INET NOT NULL,
                        range_start INET NOT NULL,
                        range_end INET NOT NULL,
                        
                        -- Usage tracking
                        total_ips INT DEFAULT 65534,
                        allocated_ips INT DEFAULT 0,
                        available_ips INT DEFAULT 65534,
                        
                        -- Status
                        status VARCHAR(20) DEFAULT 'active',
                        
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                
                // Create indexes
                DB::statement("CREATE UNIQUE INDEX idx_{$schemaName}_vpn_subnet_octet ON {$schemaName}.vpn_subnet_allocations(subnet_octet_2)");
                
                \Log::info("Created vpn_subnet_allocations table in schema: {$schemaName}");
            }
            
            // Migrate existing VPN data for this tenant
            $this->migrateVpnDataToTenantSchema($tenant->id, $schemaName);
        }
        
        // Reset search path to public
        DB::statement("SET search_path TO public");
        
        // Clean up public schema VPN tables - delete tenant-specific data
        $this->cleanupPublicSchemaVpnTables();
        
        \Log::info("Schema-based multi-tenancy for VPN completed successfully");
    }

    /**
     * Migrate VPN data from public schema to tenant schema
     */
    protected function migrateVpnDataToTenantSchema(string $tenantId, string $schemaName): void
    {
        // Migrate vpn_configurations
        if (Schema::hasTable('vpn_configurations')) {
            $vpnConfigs = DB::table('vpn_configurations')
                ->where('tenant_id', $tenantId)
                ->get();
            
            foreach ($vpnConfigs as $config) {
                DB::statement("
                    INSERT INTO {$schemaName}.vpn_configurations (
                        id, router_id, tenant_vpn_tunnel_id, vpn_type,
                        server_public_key, server_private_key, client_public_key, client_private_key, preshared_key,
                        server_ip, client_ip, subnet_cidr, listen_port,
                        server_endpoint, server_public_ip,
                        status, last_handshake_at, rx_bytes, tx_bytes,
                        mikrotik_script, linux_script,
                        interface_name, keepalive_interval, allowed_ips, dns_servers,
                        created_at, updated_at, deleted_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (id) DO NOTHING
                ", [
                    $config->id,
                    $config->router_id,
                    $config->tenant_vpn_tunnel_id,
                    $config->vpn_type ?? 'wireguard',
                    $config->server_public_key,
                    $config->server_private_key,
                    $config->client_public_key,
                    $config->client_private_key,
                    $config->preshared_key,
                    $config->server_ip,
                    $config->client_ip,
                    $config->subnet_cidr,
                    $config->listen_port ?? 51820,
                    $config->server_endpoint,
                    $config->server_public_ip,
                    $config->status ?? 'pending',
                    $config->last_handshake_at,
                    $config->rx_bytes ?? 0,
                    $config->tx_bytes ?? 0,
                    $config->mikrotik_script,
                    $config->linux_script,
                    $config->interface_name ?? 'wg0',
                    $config->keepalive_interval ?? 25,
                    $config->allowed_ips,
                    $config->dns_servers,
                    $config->created_at ?? now(),
                    $config->updated_at ?? now(),
                    $config->deleted_at
                ]);
            }
            
            \Log::info("Migrated " . count($vpnConfigs) . " VPN configurations to schema {$schemaName}");
        }
        
        // Migrate vpn_subnet_allocations
        if (Schema::hasTable('vpn_subnet_allocations')) {
            $subnetAllocs = DB::table('vpn_subnet_allocations')
                ->where('tenant_id', $tenantId)
                ->get();
            
            foreach ($subnetAllocs as $alloc) {
                DB::statement("
                    INSERT INTO {$schemaName}.vpn_subnet_allocations (
                        id, subnet_cidr, subnet_octet_2, gateway_ip, range_start, range_end,
                        total_ips, allocated_ips, available_ips, status,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (id) DO NOTHING
                ", [
                    $alloc->id,
                    $alloc->subnet_cidr,
                    $alloc->subnet_octet_2,
                    $alloc->gateway_ip,
                    $alloc->range_start,
                    $alloc->range_end,
                    $alloc->total_ips ?? 65534,
                    $alloc->allocated_ips ?? 0,
                    $alloc->available_ips ?? 65534,
                    $alloc->status ?? 'active',
                    $alloc->created_at ?? now(),
                    $alloc->updated_at ?? now()
                ]);
            }
            
            \Log::info("Migrated " . count($subnetAllocs) . " VPN subnet allocations to schema {$schemaName}");
        }
    }

    /**
     * Clean up public schema VPN tables - delete tenant-specific data
     */
    protected function cleanupPublicSchemaVpnTables(): void
    {
        // Delete all vpn_configurations from public schema (all are tenant-specific)
        if (Schema::hasTable('vpn_configurations')) {
            $deletedConfigs = DB::table('vpn_configurations')->delete();
            \Log::info("Deleted {$deletedConfigs} VPN configurations from public schema");
        }
        
        // Delete all vpn_subnet_allocations from public schema (all are tenant-specific)
        if (Schema::hasTable('vpn_subnet_allocations')) {
            $deletedAllocs = DB::table('vpn_subnet_allocations')->delete();
            \Log::info("Deleted {$deletedAllocs} VPN subnet allocations from public schema");
        }
        
        // NOTE: tenant_vpn_tunnels stays in public schema as it's used for system-level coordination
        \Log::info("Kept tenant_vpn_tunnels in public schema (system-level coordination)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;
            
            // Migrate data back to public schema before dropping
            $this->migrateVpnDataBackToPublic($tenant->id, $schemaName);
            
            // Drop VPN tables from tenant schema
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.vpn_configurations CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schemaName}.vpn_subnet_allocations CASCADE");
            
            \Log::info("Dropped VPN tables from schema: {$schemaName}");
        }
        
        \Log::info("Rolled back schema-based multi-tenancy for VPN");
    }

    /**
     * Migrate VPN data back to public schema (for rollback)
     */
    protected function migrateVpnDataBackToPublic(string $tenantId, string $schemaName): void
    {
        // Migrate vpn_configurations back
        $vpnConfigs = DB::table("{$schemaName}.vpn_configurations")->get();
        
        foreach ($vpnConfigs as $config) {
            DB::table('vpn_configurations')->insert([
                'id' => $config->id,
                'tenant_id' => $tenantId,
                'router_id' => $config->router_id,
                'tenant_vpn_tunnel_id' => $config->tenant_vpn_tunnel_id,
                'vpn_type' => $config->vpn_type,
                'server_public_key' => $config->server_public_key,
                'server_private_key' => $config->server_private_key,
                'client_public_key' => $config->client_public_key,
                'client_private_key' => $config->client_private_key,
                'preshared_key' => $config->preshared_key,
                'server_ip' => $config->server_ip,
                'client_ip' => $config->client_ip,
                'subnet_cidr' => $config->subnet_cidr,
                'listen_port' => $config->listen_port,
                'server_endpoint' => $config->server_endpoint,
                'server_public_ip' => $config->server_public_ip,
                'status' => $config->status,
                'last_handshake_at' => $config->last_handshake_at,
                'rx_bytes' => $config->rx_bytes,
                'tx_bytes' => $config->tx_bytes,
                'mikrotik_script' => $config->mikrotik_script,
                'linux_script' => $config->linux_script,
                'interface_name' => $config->interface_name,
                'keepalive_interval' => $config->keepalive_interval,
                'allowed_ips' => $config->allowed_ips,
                'dns_servers' => $config->dns_servers,
                'created_at' => $config->created_at,
                'updated_at' => $config->updated_at,
                'deleted_at' => $config->deleted_at
            ]);
        }
        
        // Migrate vpn_subnet_allocations back
        $subnetAllocs = DB::table("{$schemaName}.vpn_subnet_allocations")->get();
        
        foreach ($subnetAllocs as $alloc) {
            DB::table('vpn_subnet_allocations')->insert([
                'id' => $alloc->id,
                'tenant_id' => $tenantId,
                'subnet_cidr' => $alloc->subnet_cidr,
                'subnet_octet_2' => $alloc->subnet_octet_2,
                'gateway_ip' => $alloc->gateway_ip,
                'range_start' => $alloc->range_start,
                'range_end' => $alloc->range_end,
                'total_ips' => $alloc->total_ips,
                'allocated_ips' => $alloc->allocated_ips,
                'available_ips' => $alloc->available_ips,
                'status' => $alloc->status,
                'created_at' => $alloc->created_at,
                'updated_at' => $alloc->updated_at
            ]);
        }
    }
};
