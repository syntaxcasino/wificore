<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT SCHEMA MIGRATION - Expenses
 * 
 * This migration runs in TENANT SCHEMAS ONLY (ts_xxxxx), NOT in public schema.
 * Each tenant gets their own isolated expenses table.
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
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NO tenant_id column - schema isolation provides tenancy
            $table->string('expense_number')->unique();
            $table->string('category');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'credit_card', 'mobile_money', 'check'])->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('receipt_file')->nullable()->comment('Path to uploaded receipt');
            $table->uuid('submitted_by')->comment('User who submitted the expense');
            $table->uuid('approved_by')->nullable()->comment('User who approved the expense');
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys to public.users
            $table->foreign('submitted_by')->references('id')->on('public.users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('public.users')->onDelete('set null');

            // Indexes
            $table->index('expense_number');
            $table->index('category');
            $table->index('expense_date');
            $table->index('status');
            $table->index('submitted_by');
            $table->index('approved_by');
            $table->index('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
