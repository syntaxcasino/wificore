<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration ensures all tenant users have proper RADIUS entries
     * in their tenant schemas with Tenant-ID attribute for proper isolation.
     */
    public function up(): void
    {
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $schemaName = $tenant->schema_name;
            
            // Skip if schema doesn't exist
            $schemaExists = DB::selectOne("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?", [$schemaName]);
            if (!$schemaExists) {
                \Log::warning("Schema {$schemaName} does not exist for tenant {$tenant->id}, skipping");
                continue;
            }
            
            \Log::info("Ensuring RADIUS entries for tenant: {$tenant->name} (schema: {$schemaName})");
            
            // Set search path to tenant schema
            DB::statement("SET search_path TO {$schemaName}, public");
            
            // Get all users for this tenant
            $users = DB::table('public.users')
                ->where('tenant_id', $tenant->id)
                ->where('role', '!=', 'system_admin')
                ->get();
            
            foreach ($users as $user) {
                // Check if user has radcheck entry
                $radcheckExists = DB::table('radcheck')
                    ->where('username', $user->username)
                    ->exists();
                
                if (!$radcheckExists) {
                    \Log::info("Creating radcheck entry for user: {$user->username}");
                    
                    // Create radcheck entry with current password hash
                    // Note: In production, you should have actual passwords
                    DB::table('radcheck')->insert([
                        'username' => $user->username,
                        'attribute' => 'Cleartext-Password',
                        'op' => ':=',
                        'value' => 'CHANGE_ME', // User must reset password
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                
                // Check if user has Tenant-ID in radreply
                $tenantIdExists = DB::table('radreply')
                    ->where('username', $user->username)
                    ->where('attribute', 'Tenant-ID')
                    ->exists();
                
                if (!$tenantIdExists) {
                    \Log::info("Adding Tenant-ID to radreply for user: {$user->username}");
                    
                    // Add Tenant-ID attribute
                    DB::table('radreply')->insert([
                        'username' => $user->username,
                        'attribute' => 'Tenant-ID',
                        'op' => ':=',
                        'value' => $schemaName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                
                // Ensure Service-Type attribute exists
                $serviceTypeExists = DB::table('radreply')
                    ->where('username', $user->username)
                    ->where('attribute', 'Service-Type')
                    ->exists();
                
                if (!$serviceTypeExists) {
                    DB::table('radreply')->insert([
                        'username' => $user->username,
                        'attribute' => 'Service-Type',
                        'op' => ':=',
                        'value' => $user->role === 'admin' ? 'Administrative-User' : 'Framed-User',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            
            \Log::info("Completed RADIUS entries for tenant: {$tenant->name}");
        }
        
        // Reset search path to public
        DB::statement("SET search_path TO public");
        
        \Log::info("All tenant RADIUS entries ensured successfully");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is idempotent and safe to re-run
        // No rollback needed as it only adds missing entries
        \Log::info("No rollback needed for RADIUS entry migration");
    }
};
