<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class SystemAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Creates a default system admin user for platform management
     */
    public function run(): void
    {
        // Check if system admin already exists
        $systemAdmin = User::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->where('role', User::ROLE_SYSTEM_ADMIN)
            ->first();
        
        if ($systemAdmin) {
            $this->command->info('System admin already exists: ' . $systemAdmin->username);
            return;
        }
        
        // Create default system admin (landlord/system administrator)
        $username = 'sysadmin';
        $password = 'Admin@123'; // Change this in production!
        
        DB::beginTransaction();
        
        try {
            $systemAdmin = User::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->create([
                    'tenant_id' => null, // System admin has no tenant
                    'name' => 'System Administrator',
                    'username' => $username,
                    'email' => 'sysadmin@system.local',
                    'password' => Hash::make($password),
                    'role' => User::ROLE_SYSTEM_ADMIN,
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'account_number' => 'SYS-ADMIN-001',
                ]);
            
            // Add to RADIUS (public schema)
            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
            ]);
            
            // Add to radius_user_schema_mapping (use public schema for system admin)
            DB::table('radius_user_schema_mapping')->insert([
                'username' => $username,
                'schema_name' => 'public',
                'tenant_id' => null,
                'user_role' => User::ROLE_SYSTEM_ADMIN,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            DB::commit();
            
            $this->command->info('✅ System admin created successfully!');
            $this->command->warn('⚠️  Default credentials:');
            $this->command->line('   Username: ' . $username);
            $this->command->line('   Password: ' . $password);
            $this->command->error('🔒 IMPORTANT: Change the password immediately in production!');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to create system admin: ' . $e->getMessage());
            throw $e;
        }
    }
}
