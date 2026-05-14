<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class SystemAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Creates a default system admin user for platform management
     */
    public function run(): void
    {
        $username = 'sysadmin';
        $password = 'Admin@123!'; // Change this in production!
        $fixedUuid = '00000000-0000-0000-0000-000000000001'; // Fixed UUID — matches dairycore pattern

        // Always bypass tenant scope for system-level seed data.
        // NOTE: the app's tenant global scope is App\Scopes\TenantScope (via BelongsToTenant trait).
        $existingAdmin = User::withoutTenantScope()
            ->where('id', $fixedUuid)
            ->orWhere('username', $username)
            ->orWhere('email', 'sysadmin@system.local')
            ->first();

        if ($existingAdmin) {
            $this->command->info('System admin already exists: ' . $existingAdmin->username);
            // Ensure role and active status are correct
            $existingAdmin->update([
                'role' => User::ROLE_SYSTEM_ADMIN,
                'is_active' => true,
                'tenant_id' => null,
            ]);

            // Ensure RADIUS entries exist (idempotent)
            $this->ensureRadiusEntries($username, $password);
            return;
        }
        
        DB::beginTransaction();
        
        try {
            // Use withoutEvents to avoid BelongsToTenant::creating() assigning a default tenant_id.
            $systemAdmin = User::withoutEvents(function () use ($fixedUuid, $username, $password) {
                return User::withoutTenantScope()->create([
                    'id' => $fixedUuid,
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
            });
            
            $this->ensureRadiusEntries($username, $password);
            
            DB::commit();
            
            $this->command->info('✅ System admin created successfully!');
            $this->command->warn('⚠️  Default credentials:');
            $this->command->line('   Username: ' . $username);
            $this->command->line('   Password: ' . $password);
            $this->command->error('🔒 IMPORTANT: Change the password immediately in production!');
            
        } catch (QueryException $e) {
            // If another container is seeding concurrently, we can race on the fixed UUID insert.
            // Treat unique-violation as "already exists", then update/ensure RADIUS idempotently.
            if ((string) $e->getCode() === '23505') {
                DB::rollBack();

                $existingAdmin = User::withoutTenantScope()
                    ->where('id', $fixedUuid)
                    ->orWhere('username', $username)
                    ->orWhere('email', 'sysadmin@system.local')
                    ->first();

                if ($existingAdmin) {
                    $existingAdmin->update([
                        'role' => User::ROLE_SYSTEM_ADMIN,
                        'is_active' => true,
                        'tenant_id' => null,
                    ]);
                    $this->ensureRadiusEntries($username, $password);
                    $this->command->info('System admin already exists: ' . $existingAdmin->username);
                    return;
                }

                // If we can't find it, rethrow to surface the real issue.
            }

            DB::rollBack();
            $this->command->error('Failed to create system admin: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to create system admin: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ensure RADIUS and schema mapping entries exist (idempotent).
     * Uses updateOrInsert to avoid duplicate key errors on re-seed.
     */
    private function ensureRadiusEntries(string $username, string $password): void
    {
        // Add to RADIUS (public schema) — idempotent
        DB::table('radcheck')->updateOrInsert(
            [
                'username' => $username,
                'attribute' => 'Cleartext-Password',
            ],
            [
                'op' => ':=',
                'value' => $password,
            ]
        );

        // Add to radius_user_schema_mapping (use public schema for system admin) — idempotent
        DB::table('radius_user_schema_mapping')->updateOrInsert(
            ['username' => $username],
            [
                'schema_name' => 'public',
                'tenant_id' => null,
                'user_role' => User::ROLE_SYSTEM_ADMIN,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
