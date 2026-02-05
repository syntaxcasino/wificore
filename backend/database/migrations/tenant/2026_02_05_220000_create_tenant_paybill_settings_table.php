<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Paybill Settings Table
 * 
 * Stores MPesa Paybill configuration per tenant with encrypted credentials.
 * Supports landlord fallback when tenant has no own Paybill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_paybill_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Paybill Configuration (all sensitive fields are encrypted)
            $table->string('business_shortcode', 20)->nullable();
            $table->text('consumer_key')->nullable(); // Encrypted
            $table->text('consumer_secret')->nullable(); // Encrypted
            $table->text('passkey')->nullable(); // Encrypted
            $table->string('account_reference', 50)->nullable(); // Default account reference prefix
            
            // Environment
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            
            // Callback URLs (auto-generated based on tenant)
            $table->string('validation_url', 500)->nullable();
            $table->string('confirmation_url', 500)->nullable();
            $table->timestamp('urls_registered_at')->nullable();
            
            // Landlord Fallback Settings
            $table->boolean('use_landlord_paybill')->default(true);
            $table->decimal('landlord_commission_percent', 5, 2)->default(0); // Commission landlord takes
            
            // Status
            $table->boolean('is_active')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_transaction_at')->nullable();
            
            // Audit
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('business_shortcode');
            $table->index('is_active');
            $table->index('use_landlord_paybill');
        });

        // Payment transactions table for audit trail
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Transaction details from MPesa
            $table->string('transaction_id', 50)->unique();
            $table->string('transaction_type', 50)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('msisdn', 20); // Phone number
            $table->string('bill_ref_number', 100)->nullable(); // Account reference
            $table->string('business_shortcode', 20);
            $table->timestamp('transaction_time');
            
            // Matching
            $table->uuid('pppoe_user_id')->nullable();
            $table->uuid('pppoe_payment_id')->nullable();
            $table->boolean('is_matched')->default(false);
            $table->timestamp('matched_at')->nullable();
            $table->string('match_method', 50)->nullable(); // account_number, username, phone
            
            // Source tracking (own paybill or landlord)
            $table->boolean('is_landlord_paybill')->default(false);
            $table->uuid('source_tenant_id')->nullable(); // For landlord paybill, which tenant's user
            
            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'refunded'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            
            // Full payload for debugging
            $table->json('raw_payload')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('msisdn');
            $table->index('bill_ref_number');
            $table->index('business_shortcode');
            $table->index('is_matched');
            $table->index('status');
            $table->index('transaction_time');
            $table->index(['pppoe_user_id', 'status']);
        });

        // Payment check logs for auditing
        Schema::create('payment_check_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->enum('check_type', ['automatic', 'manual', 'webhook']);
            $table->string('paybill_shortcode', 20)->nullable();
            $table->boolean('is_landlord_paybill')->default(false);
            
            $table->integer('transactions_found')->default(0);
            $table->integer('transactions_matched')->default(0);
            $table->integer('users_activated')->default(0);
            $table->integer('users_disconnected')->default(0);
            
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->text('error_message')->nullable();
            $table->json('details')->nullable();
            
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('check_type');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_check_logs');
        Schema::dropIfExists('mpesa_transactions');
        Schema::dropIfExists('tenant_paybill_settings');
    }
};
