<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT SCHEMA MIGRATION - Departments
 * 
 * This migration runs in TENANT SCHEMAS ONLY (ts_xxxxx), NOT in public schema.
 * Each tenant gets their own isolated departments table.
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
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NO tenant_id column - schema isolation provides tenancy
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->uuid('manager_id')->nullable()->comment('References employees.id in same schema');
            $table->decimal('budget', 15, 2)->nullable();
            $table->string('location')->nullable();
            $table->enum('status', ['active', 'pending_approval', 'inactive'])->default('pending_approval');
            $table->boolean('is_active')->default(true);
            $table->integer('employee_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('name');
            $table->index('code');
            $table->index('manager_id');
            $table->index('status');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
