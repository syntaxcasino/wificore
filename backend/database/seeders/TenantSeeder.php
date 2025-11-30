<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default tenant should already exist from migration
        // Create additional demo tenants for testing
        
        $tenants = [
            [
                'name' => 'Demo ISP 1',
                'slug' => 'demo-isp-1',
                'email' => 'admin@demo-isp-1.com',
                'phone' => '+254700000001',
                'address' => 'Nairobi, Kenya',
                'is_active' => true,
                'settings' => [
                    'timezone' => 'Africa/Nairobi',
                    'currency' => 'KES',
                    'max_routers' => 10,
                    'max_users' => 1000,
                ],
            ],
            [
                'name' => 'Demo ISP 2',
                'slug' => 'demo-isp-2',
                'email' => 'admin@demo-isp-2.com',
                'phone' => '+254700000002',
                'address' => 'Mombasa, Kenya',
                'is_active' => true,
                'settings' => [
                    'timezone' => 'Africa/Nairobi',
                    'currency' => 'KES',
                    'max_routers' => 5,
                    'max_users' => 500,
                ],
            ],
        ];

        foreach ($tenants as $tenantData) {
            Tenant::firstOrCreate(
                ['slug' => $tenantData['slug']],
                $tenantData
            );
        }
    }
}
