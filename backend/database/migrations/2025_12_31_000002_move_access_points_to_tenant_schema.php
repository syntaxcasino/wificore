<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info("Starting migration of Access Points to tenant schemas");
        
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;
            
            // Check if schema exists
            $schemaExists = DB::selectOne("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?", [$schemaName]);
            if (!$schemaExists) {
                continue;
            }
            
            Log::info("Migrating Access Points for tenant: {$tenant->name} ({$schemaName})");
            
            // 1. Migrate Access Points
            // We select from public.access_points where tenant_id matches
            $accessPoints = DB::table('public.access_points')
                ->where('tenant_id', $tenant->id)
                ->get();
                
            foreach ($accessPoints as $ap) {
                // Insert into tenant schema
                // We need to be careful with column mapping if schema changed
                // The new schema does NOT have tenant_id
                
                DB::table("{$schemaName}.access_points")->insertOrIgnore([
                    'id' => $ap->id,
                    'router_id' => $ap->router_id,
                    'name' => $ap->name,
                    'vendor' => $ap->vendor,
                    'model' => $ap->model,
                    'ip_address' => $ap->ip_address,
                    'mac_address' => $ap->mac_address,
                    'serial_number' => $ap->serial_number ?? null,
                    'management_protocol' => $ap->management_protocol,
                    'credentials' => $ap->credentials,
                    'location' => $ap->location,
                    'status' => $ap->status,
                    'active_users' => $ap->active_users,
                    'total_capacity' => $ap->total_capacity,
                    'signal_strength' => $ap->signal_strength,
                    'uptime_seconds' => $ap->uptime_seconds,
                    'last_seen_at' => $ap->last_seen_at,
                    'created_at' => $ap->created_at,
                    'updated_at' => $ap->updated_at,
                ]);
            }
            
            Log::info("Migrated " . count($accessPoints) . " Access Points to {$schemaName}");
            
            // 2. Migrate Active Sessions
            $sessions = DB::table('public.ap_active_sessions')
                ->where('tenant_id', $tenant->id)
                ->get();
                
            foreach ($sessions as $session) {
                DB::table("{$schemaName}.ap_active_sessions")->insertOrIgnore([
                    'id' => $session->id,
                    'access_point_id' => $session->access_point_id,
                    'router_id' => $session->router_id,
                    'username' => $session->username,
                    'mac_address' => $session->mac_address,
                    'ip_address' => $session->ip_address,
                    'session_id' => $session->session_id,
                    'connected_at' => $session->connected_at,
                    'last_activity_at' => $session->last_activity_at,
                    'bytes_in' => $session->bytes_in,
                    'bytes_out' => $session->bytes_out,
                    'signal_strength' => $session->signal_strength,
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                ]);
            }
            
            Log::info("Migrated " . count($sessions) . " Active Sessions to {$schemaName}");
        }
        
        // 3. Drop tables from public schema to enforce strict isolation
        // We use Schema::dropIfExists but specify public schema explicitly just in case
        // Although Schema facade usually defaults to public or search path
        
        // Use raw SQL to be explicit about public schema
        DB::statement('DROP TABLE IF EXISTS public.ap_active_sessions CASCADE');
        DB::statement('DROP TABLE IF EXISTS public.access_points CASCADE');
        
        Log::info("Dropped Access Point tables from public schema");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-create public tables
        Schema::create('access_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('router_id')->nullable();
            $table->string('name', 100);
            $table->string('vendor', 50);
            $table->string('model', 100)->nullable();
            $table->string('ip_address', 45);
            $table->string('mac_address', 17)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('management_protocol', 20)->default('snmp');
            $table->json('credentials')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->integer('active_users')->default(0);
            $table->integer('total_capacity')->nullable();
            $table->integer('signal_strength')->nullable();
            $table->bigInteger('uptime_seconds')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
        });

        Schema::create('ap_active_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('access_point_id');
            $table->uuid('router_id')->nullable();
            $table->string('username', 100)->nullable();
            $table->string('mac_address', 17);
            $table->string('ip_address', 45)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->bigInteger('bytes_in')->default(0);
            $table->bigInteger('bytes_out')->default(0);
            $table->integer('signal_strength')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('access_point_id')->references('id')->on('access_points')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
        });
        
        // Data restoration would go here, but it's complex to pull back from multiple schemas
    }
};
