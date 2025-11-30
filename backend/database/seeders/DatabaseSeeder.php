<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌱 Starting database seeding...');
        $this->command->info('');

        // Seed in order of dependencies
        $this->call([
            SystemAdminSeeder::class,          // 1. Create system admin first
            DefaultTenantSeeder::class,        // 2. Create default tenant
            DemoDataSeeder::class,             // 3. Create demo data (dev/staging only)
        ]);

        $this->command->info('');
        $this->command->info('✅ Database seeding completed successfully!');
    }
}
