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
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('schema_name', 63)->unique()->nullable();
            $table->boolean('schema_created')->default(false);
            $table->timestamp('schema_created_at')->nullable();
            $table->string('subdomain')->unique()->nullable();
            $table->string('custom_domain')->unique()->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_landlord')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_suspended')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->string('subscription_status')->default('trial');
            $table->string('subscription_plan')->nullable();
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('next_payment_due')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->decimal('pppoe_rate', 10, 2)->nullable()->comment('Custom PPPoE user rate (KES per user)');
            $table->decimal('hotspot_revenue_pct', 5, 2)->nullable()->comment('Custom hotspot revenue percentage');
            $table->decimal('router_rate', 10, 2)->nullable()->comment('Custom router rate (KES per router)');
            $table->boolean('landlord_override')->default(false)->comment('If true, landlord prevents automatic service disconnection');
            $table->string('landlord_override_reason')->nullable();
            $table->timestamp('landlord_override_until')->nullable();
            $table->timestamp('last_invoice_at')->nullable();
            $table->decimal('last_invoice_amount', 12, 2)->nullable();
            $table->timestamp('subscription_warning_sent_at')->nullable()->comment('When the 5-day pre-expiry warning was sent');
            $table->string('custom_paybill')->nullable()->comment('Tenant custom paybill, null = use landlord default');
            $table->json('settings')->default('{}');
            $table->json('branding')->nullable();
            $table->boolean('public_packages_enabled')->default(true);
            $table->boolean('public_registration_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('slug');
            $table->index('schema_name');
            $table->index('schema_created');
            $table->index('is_active');
        });

        // No default tenant - all tenants must register through the system
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
