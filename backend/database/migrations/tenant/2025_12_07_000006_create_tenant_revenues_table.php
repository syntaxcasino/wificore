<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT SCHEMA MIGRATION - Revenues
 * 
 * This migration runs in TENANT SCHEMAS ONLY (ts_xxxxx), NOT in public schema.
 * Each tenant gets their own isolated revenues table.
 * 
 * Multi-Tenancy: STRICT DATA ISOLATION via schema separation
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('revenues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NO tenant_id column - schema isolation provides tenancy
            $table->string('revenue_number')->unique();
            $table->string('source')->comment('e.g., Package Sales, Installation Fees, etc.');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('revenue_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'credit_card', 'mobile_money', 'check'])->nullable();
            $table->string('reference_number')->nullable()->comment('Invoice, receipt, or transaction reference');
            $table->uuid('customer_id')->nullable()->comment('References public.users (hotspot_user)');
            $table->uuid('recorded_by')->comment('User who recorded the revenue');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys to public.users
            $table->foreign('customer_id')->references('id')->on('public.users')->onDelete('set null');
            $table->foreign('recorded_by')->references('id')->on('public.users')->onDelete('cascade');

            // Indexes
            $table->index('revenue_number');
            $table->index('source');
            $table->index('revenue_date');
            $table->index('status');
            $table->index('customer_id');
            $table->index('recorded_by');
            $table->index('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenues');
    }
};
