<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Move tenant_ip_pools from tenant schemas to public schema.
 * 
 * IP pools are a system-managed resource that the system admin allocates
 * to tenants. They need cross-tenant visibility and don't belong in
 * individual tenant schemas. The model already uses tenant_id for scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_ip_pools')) {
            return;
        }

        Schema::create('tenant_ip_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->enum('service_type', ['hotspot', 'pppoe', 'management'])->index();
            $table->string('pool_name');
            $table->string('network_cidr', 50);
            $table->string('gateway_ip', 45);
            $table->string('range_start', 45);
            $table->string('range_end', 45);
            $table->string('dns_primary', 45)->nullable();
            $table->string('dns_secondary', 45)->nullable();
            $table->integer('total_ips');
            $table->integer('allocated_ips')->default(0);
            $table->integer('available_ips');
            $table->boolean('auto_generated')->default(true);
            $table->enum('status', ['active', 'exhausted', 'disabled'])->default('active')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'service_type', 'network_cidr']);
            $table->index(['tenant_id', 'service_type', 'status']);
        });

        // Migrate data from tenant schemas if any exist
        $this->migrateFromTenantSchemas();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ip_pools');
    }

    /**
     * Migrate existing IP pool data from tenant schemas to public schema.
     */
    private function migrateFromTenantSchemas(): void
    {
        $tenants = DB::table('tenants')
            ->where('schema_created', true)
            ->whereNotNull('schema_name')
            ->get();

        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;

            // Check if the tenant schema has the old tenant_ip_pools table
            $tableExists = DB::select("
                SELECT EXISTS (
                    SELECT FROM pg_tables 
                    WHERE schemaname = ? AND tablename = 'tenant_ip_pools'
                ) AS exists
            ", [$schemaName]);

            if (!empty($tableExists) && $tableExists[0]->exists) {
                // Copy rows from tenant schema to public, skip duplicates
                DB::statement("
                    INSERT INTO public.tenant_ip_pools 
                        (id, tenant_id, service_type, pool_name, network_cidr, gateway_ip, 
                         range_start, range_end, dns_primary, dns_secondary, total_ips, 
                         allocated_ips, available_ips, auto_generated, status, metadata, 
                         created_at, updated_at)
                    SELECT 
                        id, tenant_id, service_type, pool_name, network_cidr, gateway_ip, 
                        range_start, range_end, dns_primary, dns_secondary, total_ips, 
                        allocated_ips, available_ips, auto_generated, status, metadata, 
                        created_at, updated_at
                    FROM \"{$schemaName}\".tenant_ip_pools
                    ON CONFLICT (id) DO NOTHING
                ");
            }
        }
    }
};
