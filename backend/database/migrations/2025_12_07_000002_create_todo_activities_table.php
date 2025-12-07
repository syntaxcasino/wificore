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
        Schema::create('todo_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('todo_id');
            $table->uuid('user_id')->comment('User who performed the action');
            $table->string('action')->comment('created, updated, completed, assigned, deleted');
            $table->json('old_value')->nullable()->comment('Previous state');
            $table->json('new_value')->nullable()->comment('New state');
            $table->text('description')->nullable()->comment('Human-readable description');
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('todo_id')->references('id')->on('todos')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
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
    }
};
