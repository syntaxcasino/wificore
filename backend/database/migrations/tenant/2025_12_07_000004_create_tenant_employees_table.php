<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT SCHEMA MIGRATION - Employees
 * 
 * This migration runs in TENANT SCHEMAS ONLY (ts_xxxxx), NOT in public schema.
 * Each tenant gets their own isolated employees table.
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
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NO tenant_id column - schema isolation provides tenancy
            $table->uuid('user_id')->nullable()->comment('Link to public.users for portal access');
            $table->string('employee_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            
            // Employment details
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'intern'])->default('full_time');
            $table->date('hire_date');
            $table->date('contract_end_date')->nullable();
            $table->enum('employment_status', ['active', 'on_leave', 'suspended', 'terminated'])->default('active');
            
            // Compensation
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('salary_currency', 3)->default('USD');
            $table->enum('payment_frequency', ['monthly', 'bi_weekly', 'weekly'])->default('monthly');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_branch')->nullable();
            
            // Emergency contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relationship')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('public.users')->onDelete('set null');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('position_id')->references('id')->on('positions')->onDelete('set null');

            // Indexes
            $table->index('employee_number');
            $table->index('user_id');
            $table->index('department_id');
            $table->index('position_id');
            $table->index('employment_status');
            $table->index('employment_type');
            $table->index('is_active');
            $table->index(['first_name', 'last_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
