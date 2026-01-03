<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DefaultTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * No default tenant - all tenants must register through the system
     */
    public function run(): void
    {
        $this->command->info('⚠️  No default tenant created - tenants must register through the system.');
    }
}
