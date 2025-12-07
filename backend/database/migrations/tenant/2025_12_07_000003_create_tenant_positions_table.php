<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT SCHEMA MIGRATION - Positions
 * 
 * This migration runs in TENANT SCHEMAS ONLY (ts_xxxxx), NOT in public schema.
 * Each tenant gets their own isolated positions table.
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
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NO tenant_id column - schema isolation provides tenancy
            $table->string('title');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->uuid('department_id')->nullable();
            $table->string('level')->nullable()->comment('Entry, Junior, Mid, Senior, Lead, Manager, Director');
            $table->decimal('min_salary', 12, 2)->nullable();
            $table->decimal('max_salary', 12, 2)->nullable();
            $table->text('requirements')->nullable();
            $table->text('responsibilities')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Foreign key to departments in SAME tenant schema
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');

            // Indexes
            $table->index('title');
            $table->index('code');
            $table->index('department_id');
            $table->index('level');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
