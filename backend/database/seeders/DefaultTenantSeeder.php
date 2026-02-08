<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DefaultTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * No separate default tenant is created.
     * The System Landlord tenant (created by SystemLandlordSeeder) serves as the
     * default tenant with is_landlord=true and is_default=true.
     * All tenants must register through the system.
     */
    public function run(): void
    {
        $this->command->info('ℹ️  No separate default tenant — the System Landlord is the default tenant.');
    }
}
