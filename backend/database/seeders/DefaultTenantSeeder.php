<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use Illuminate\Support\Str;

class DefaultTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a default tenant for initial setup
     */
    public function run(): void
    {
        // Check if default tenant already exists
        $existingTenant = Tenant::where('slug', 'default')->first();

        if ($existingTenant) {
            $this->command->info('Default tenant already exists.');
            return;
        }

        // Create default tenant
        $defaultTenant = Tenant::create([
            'name' => 'Default Tenant',
            'slug' => 'default',
            'email' => 'admin@default-tenant.local',
            'phone' => '+254700000000',
            'address' => 'Default Address',
            'is_active' => true,
            'is_suspended' => false,
            'settings' => [
                'timezone' => 'Africa/Nairobi',
                'currency' => 'KES',
                'language' => 'en',
            ],
        ]);

        $this->command->info('✅ Default tenant created successfully!');
        $this->command->info('   Name: Default Tenant');
        $this->command->info('   Slug: default');
        $this->command->info('   Email: admin@default-tenant.local');
    }
}
