<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT SCHEMA MIGRATION
 * 
 * This migration runs in TENANT SCHEMAS ONLY (ts_xxxxx), NOT in public schema.
 * Each tenant gets their own isolated todos and todo_activities tables.
 * 
 * Multi-Tenancy: STRICT DATA ISOLATION
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create todos table in TENANT SCHEMA
        Schema::create('todos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NO tenant_id column needed - schema isolation provides tenancy
            $table->uuid('user_id')->nullable()->comment('Assigned to user');
            $table->uuid('created_by')->comment('User who created the todo');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('related_type')->nullable()->comment('Polymorphic relation type');
            $table->uuid('related_id')->nullable()->comment('Polymorphic relation id');
            $table->json('metadata')->nullable()->comment('Additional task metadata');
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign keys reference users in PUBLIC schema
            $table->foreign('user_id')->references('id')->on('public.users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('public.users')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['user_id', 'status']);
            $table->index(['created_by']);
            $table->index(['due_date']);
            $table->index(['related_type', 'related_id']);
            $table->index(['status']);
        });

        // Create todo_activities table in TENANT SCHEMA
        Schema::create('todo_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('todo_id');
            $table->uuid('user_id')->comment('User who performed the action');
            $table->string('action')->comment('created, updated, completed, assigned, deleted');
            $table->json('old_value')->nullable()->comment('Previous state');
            $table->json('new_value')->nullable()->comment('New state');
            $table->text('description')->nullable()->comment('Human-readable description');
            $table->timestamps();
            
            // Foreign key to todos in SAME tenant schema
            $table->foreign('todo_id')->references('id')->on('todos')->onDelete('cascade');
            // Foreign key to users in PUBLIC schema
            $table->foreign('user_id')->references('id')->on('public.users')->onDelete('cascade');
            
            // Indexes
            $table->index(['todo_id', 'created_at']);
            $table->index(['user_id']);
            $table->index(['action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todo_activities');
        Schema::dropIfExists('todos');
    }
};
