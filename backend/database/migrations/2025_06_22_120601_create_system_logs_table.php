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
        Schema::create('system_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('action');
            $table->json('details')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('level', 20)->default('info');
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('entity_type');
            $table->index('level');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
