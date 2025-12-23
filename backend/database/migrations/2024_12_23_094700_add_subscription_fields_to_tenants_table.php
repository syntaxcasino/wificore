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
            // Only add columns that don't exist
            if (!Schema::hasColumn('tenants', 'subscription_status')) {
                $table->string('subscription_status')->default('trial')->after('is_suspended');
            }
            if (!Schema::hasColumn('tenants', 'subscription_plan')) {
                $table->string('subscription_plan')->nullable()->after('subscription_status');
            }
            if (!Schema::hasColumn('tenants', 'subscription_started_at')) {
                $table->timestamp('subscription_started_at')->nullable()->after('subscription_plan');
            }
            if (!Schema::hasColumn('tenants', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable()->after('subscription_started_at');
            }
            if (!Schema::hasColumn('tenants', 'last_payment_at')) {
                $table->timestamp('last_payment_at')->nullable()->after('subscription_ends_at');
            }
            if (!Schema::hasColumn('tenants', 'next_payment_due')) {
                $table->timestamp('next_payment_due')->nullable()->after('last_payment_at');
            }
        });
        
        // Add indexes if they don't exist
        if (!Schema::hasColumn('tenants', 'subscription_status')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->index('subscription_status');
            });
        }
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
