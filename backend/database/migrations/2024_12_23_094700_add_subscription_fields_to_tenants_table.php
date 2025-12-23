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
            $table->string('subscription_status')->default('trial')->after('is_suspended');
            $table->string('subscription_plan')->nullable()->after('subscription_status');
            $table->timestamp('subscription_started_at')->nullable()->after('subscription_plan');
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_started_at');
            $table->timestamp('last_payment_at')->nullable()->after('subscription_ends_at');
            $table->timestamp('next_payment_due')->nullable()->after('last_payment_at');
            $table->string('subdomain')->nullable()->after('slug');
            $table->string('custom_domain')->nullable()->after('subdomain');
            $table->string('schema_name')->nullable()->after('custom_domain');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->boolean('schema_created')->default(false)->after('schema_name');
            $table->timestamp('schema_created_at')->nullable()->after('schema_created');
            $table->json('branding')->nullable()->after('settings');
            $table->boolean('public_packages_enabled')->default(false)->after('branding');
            $table->boolean('public_registration_enabled')->default(false)->after('public_packages_enabled');
            
            // Add indexes
            $table->index('subscription_status');
            $table->index('subdomain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_status',
                'subscription_plan',
                'subscription_started_at',
                'subscription_ends_at',
                'last_payment_at',
                'next_payment_due',
                'subdomain',
                'custom_domain',
                'schema_name',
                'email_verified_at',
                'schema_created',
                'schema_created_at',
                'branding',
                'public_packages_enabled',
                'public_registration_enabled',
            ]);
        });
    }
};
