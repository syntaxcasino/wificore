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
        Schema::create('tenant_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->decimal('amount', 10, 2);
            $table->string('transaction_id')->unique();
            $table->string('status', 20)->default('pending'); // pending, completed, failed
            $table->string('payment_method')->default('mpesa');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->index('tenant_id');
            $table->index('status');
            $table->index('transaction_id');
        });

        Schema::create('mpesa_transaction_maps', function (Blueprint $table) {
            $table->id();
            $table->string('checkout_request_id')->unique();
            $table->string('merchant_request_id')->nullable();
            $table->uuid('tenant_id');
            $table->string('payment_type')->default('hotspot'); // hotspot, pppoe, tenant_subscription
            $table->uuid('related_id')->nullable(); // ID of the payment record in the respective schema
            $table->timestamps();

            $table->index('checkout_request_id');
            $table->index('tenant_id');
        });

        Schema::create('system_payment_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Paybill Configuration
            $table->string('default_paybill_number', 20)->nullable();
            $table->string('shortcode', 20)->nullable();
            $table->text('passkey')->nullable();
            $table->text('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();

            // Environment
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');

            // Callback URLs
            $table->string('validation_url', 500)->nullable();
            $table->string('confirmation_url', 500)->nullable();
            $table->timestamp('urls_registered_at')->nullable();

            // Status
            $table->boolean('is_active')->default(false);

            // Payment trace logging mode
            $table->string('payment_trace_mode', 20)->default('stdout');

            // Account reference prefix for landlord paybill
            $table->string('account_reference_prefix', 50)->nullable();

            // Audit
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_payment_settings');
        Schema::dropIfExists('mpesa_transaction_maps');
        Schema::dropIfExists('tenant_payments');
    }
};
