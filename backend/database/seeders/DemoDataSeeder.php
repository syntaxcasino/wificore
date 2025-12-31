<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed demo data for testing and development
     * Only run in non-production environments
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('⚠️  Demo data seeder skipped in production environment.');
            return;
        }

        $this->command->info('🌱 Seeding demo data...');

        // Create demo tenants
        $tenantA = $this->createDemoTenant('Tenant A', 'tenant-a', 'admin@tenant-a.com');
        $tenantB = $this->createDemoTenant('Tenant B', 'tenant-b', 'admin@tenant-b.com');

        // Create demo admin users for each tenant
        $this->createDemoAdmin($tenantA, 'admin-a', 'admin-a@tenant-a.com');
        $this->createDemoAdmin($tenantB, 'admin-b', 'admin-b@tenant-b.com');

        // Create demo packages for each tenant
        $this->createDemoPackages($tenantA);
        $this->createDemoPackages($tenantB);

        // Create demo routers for each tenant
        $this->createDemoRouter($tenantA, 'Router A1', '192.168.1.1');
        $this->createDemoRouter($tenantB, 'Router B1', '192.168.2.1');

        $this->command->info('✅ Demo data seeded successfully!');
        $this->command->info('');
        $this->command->info('Demo Accounts:');
        $this->command->info('  Tenant A Admin: admin-a@tenant-a.com / Password123!');
        $this->command->info('  Tenant B Admin: admin-b@tenant-b.com / Password123!');
    }

    private function createDemoTenant(string $name, string $slug, string $email): Tenant
    {
        return Tenant::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'email' => $email,
                'phone' => '+254700000000',
                'address' => 'Demo Address',
                'is_active' => true,
                'settings' => [
                    'timezone' => 'Africa/Nairobi',
                    'currency' => 'KES',
                ],
            ]
        );
    }

    private function createDemoAdmin(Tenant $tenant, string $username, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => ucfirst(str_replace('-', ' ', $username)),
                'username' => $username,
                'password' => Hash::make('Password123!'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }

    private function createDemoPackages(Tenant $tenant): void
    {
        $packages = [
            [
                'name' => 'Basic Plan',
                'type' => 'hotspot',
                'price' => 50,
                'duration' => '24',
                'speed' => '2M/2M',
                'description' => 'Basic internet package for 24 hours',
            ],
            [
                'name' => 'Standard Plan',
                'type' => 'hotspot',
                'price' => 100,
                'duration' => '168',
                'speed' => '5M/5M',
                'description' => 'Standard internet package for 1 week',
            ],
            [
                'name' => 'Premium Plan',
                'type' => 'hotspot',
                'price' => 500,
                'duration' => '720',
                'speed' => '10M/10M',
                'description' => 'Premium internet package for 1 month',
            ],
        ];

        foreach ($packages as $packageData) {
            Package::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => $packageData['name'],
                ],
                array_merge($packageData, [
                    'tenant_id' => $tenant->id,
                    'upload_speed' => explode('/', $packageData['speed'])[0],
                    'download_speed' => explode('/', $packageData['speed'])[1],
                    'devices' => 1,
                    'is_active' => true,
                    'status' => 'active',
                ])
            );
        }
    }

    private function createDemoRouter(Tenant $tenant, string $name, string $ip): Router
    {
        // Switch to tenant schema
        \Illuminate\Support\Facades\DB::statement("SET search_path TO {$tenant->schema_name}, public");

        $router = Router::firstOrCreate(
            [
                'ip_address' => $ip,
            ],
            [
                'name' => $name,
                'username' => 'admin',
                'password' => encrypt('admin'),
                'port' => 8728,
                'status' => 'pending',
                'vendor' => 'mikrotik',
                'device_type' => 'router',
            ]
        );

        // Switch back to public schema
        \Illuminate\Support\Facades\DB::statement("SET search_path TO public");

        return $router;
    }
}
