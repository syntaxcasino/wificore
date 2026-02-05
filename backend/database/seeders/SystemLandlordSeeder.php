<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SystemLandlordSeeder extends Seeder
{
    /**
     * Seed the system landlord tenant and user.
     *
     * The landlord tenant is a special tenant that manages the SaaS platform.
     * It has system-level access to aggregate metrics and billing controls.
     */
    public function run(): void
    {
        $this->command->info('Creating System Landlord tenant and user...');

        DB::beginTransaction();

        try {
            // Create landlord tenant
            $tenant = $this->createLandlordTenant();

            // Create landlord admin user
            $user = $this->createLandlordUser($tenant);

            DB::commit();

            $this->command->info('✅ System Landlord created successfully!');
            $this->command->warn('');
            $this->command->warn('⚠️  IMPORTANT: Change the default landlord password immediately!');
            $this->command->warn('    Email: ' . $user->email);
            $this->command->warn('    Default Password: ' . config('saas.landlord.default_admin_password', 'Landlord@123!'));
            $this->command->warn('');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to create System Landlord: ' . $e->getMessage());
            Log::error('SystemLandlordSeeder failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Create the landlord tenant
     */
    private function createLandlordTenant(): Tenant
    {
        $slug = config('saas.landlord.tenant_slug', 'system-landlord');
        $name = config('saas.landlord.tenant_name', 'System Landlord');

        // Check if landlord tenant already exists
        $existingTenant = Tenant::where('slug', $slug)->first();

        if ($existingTenant) {
            $this->command->info('Landlord tenant already exists, updating...');
            $existingTenant->update([
                'name' => $name,
                'is_landlord' => true,
                'is_active' => true,
                'subscription_status' => 'active',
                'subscription_ends_at' => null, // Landlord never expires
            ]);
            return $existingTenant;
        }

        // Create new landlord tenant
        $tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'email' => config('saas.landlord.default_admin_email', 'landlord@wificore.local'),
            'is_active' => true,
            'is_landlord' => true,
            'subscription_status' => 'active',
            'subscription_ends_at' => null, // Landlord never expires
            'schema_name' => 'landlord_schema',
            'schema_created' => false, // Landlord doesn't need tenant schema
            'settings' => [
                'is_system_tenant' => true,
                'billing_enabled' => true,
            ],
        ]);

        $this->command->info("Created landlord tenant: {$tenant->name} (ID: {$tenant->id})");

        return $tenant;
    }

    /**
     * Create the landlord admin user
     */
    private function createLandlordUser(Tenant $tenant): User
    {
        $email = config('saas.landlord.default_admin_email', 'landlord@wificore.local');
        $password = config('saas.landlord.default_admin_password', 'Landlord@123!');

        // Check if landlord user already exists
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            $this->command->info('Landlord user already exists, updating role...');
            $existingUser->update([
                'role' => User::ROLE_SYSTEM_ADMIN,
                'tenant_id' => null, // System admin has no tenant
                'is_active' => true,
            ]);
            return $existingUser;
        }

        // Create new landlord user
        $user = User::create([
            'id' => Str::uuid(),
            'name' => 'System Landlord',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_SYSTEM_ADMIN,
            'tenant_id' => null, // System admin has no tenant (can access all)
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command->info("Created landlord user: {$user->email} (ID: {$user->id})");

        // Add to RADIUS for authentication
        $this->addToRadius($user, $password);

        return $user;
    }

    /**
     * Add landlord user to RADIUS
     */
    private function addToRadius(User $user, string $password): void
    {
        try {
            // Add to radcheck table for authentication
            DB::table('radcheck')->updateOrInsert(
                ['username' => $user->email],
                [
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $password,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Add tenant mapping to public schema
            DB::table('radcheck')->updateOrInsert(
                ['username' => $user->email, 'attribute' => 'Tenant-Schema'],
                [
                    'op' => ':=',
                    'value' => 'public',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $this->command->info('Added landlord user to RADIUS');

        } catch (\Exception $e) {
            $this->command->warn('Could not add landlord to RADIUS: ' . $e->getMessage());
            Log::warning('Failed to add landlord to RADIUS', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
