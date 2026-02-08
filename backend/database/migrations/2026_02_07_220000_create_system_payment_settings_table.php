<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Payment Settings Table (Landlord/Public DB)
 * 
 * Stores the landlord's default MPesa Paybill configuration.
 * Used as fallback when tenants don't have their own Paybill.
 * This replaces .env-based Paybill configuration for the landlord.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_payment_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Paybill Configuration
            $table->string('default_paybill_number', 20)->nullable();
            $table->string('shortcode', 20)->nullable();
            $table->text('passkey')->nullable(); // Encrypted
            $table->text('consumer_key')->nullable(); // Encrypted
            $table->text('consumer_secret')->nullable(); // Encrypted
            
            // Environment
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            
            // Callback URLs
            $table->string('validation_url', 500)->nullable();
            $table->string('confirmation_url', 500)->nullable();
            $table->timestamp('urls_registered_at')->nullable();
            
            // Status
            $table->boolean('is_active')->default(false);
            
            // Account reference prefix for landlord paybill
            $table->string('account_reference_prefix', 50)->nullable();
            
            // Audit
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_payment_settings');
    }
};
