<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Add subdomain column
            $table->string('subdomain')->unique()->nullable()->after('slug');
            
            // Add custom domain column (for premium tenants)
            $table->string('custom_domain')->unique()->nullable()->after('subdomain');
            
            // Add branding settings
            $table->json('branding')->nullable()->after('settings');
            
            // Add public settings
            $table->boolean('public_packages_enabled')->default(true)->after('branding');
            $table->boolean('public_registration_enabled')->default(true)->after('public_packages_enabled');
        });

        // Update existing tenants to have subdomains based on their slugs
        DB::table('tenants')->get()->each(function ($tenant) {
            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([
                    'subdomain' => $tenant->slug,
                    'branding' => json_encode([
                        'logo_url' => null,
                        'primary_color' => '#3b82f6',
                        'secondary_color' => '#10b981',
                        'company_name' => $tenant->name,
                        'tagline' => null,
                        'support_email' => $tenant->email,
                        'support_phone' => $tenant->phone,
                    ]),
                ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'subdomain',
                'custom_domain',
                'branding',
                'public_packages_enabled',
                'public_registration_enabled',
            ]);
        });
    }
};
