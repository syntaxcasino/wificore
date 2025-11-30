<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table is CRITICAL for schema-based multi-tenant RADIUS authentication.
     * It maps usernames to tenant schemas BEFORE tenant context is established.
     */
    public function up(): void
    {
        Schema::create('radius_user_schema_mapping', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->unique();
            $table->string('schema_name', 64);
            $table->uuid('tenant_id')->nullable();
            $table->string('user_role', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Foreign key
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onDelete('cascade');
            
            // Critical indexes for RADIUS performance
            $table->index('username');
            $table->index('schema_name');
            $table->index(['username', 'is_active']);
            $table->index('tenant_id');
        });
        
        // Add comment to table
        DB::statement("
            COMMENT ON TABLE radius_user_schema_mapping IS 
            'Maps RADIUS usernames to tenant schemas for multi-tenant authentication. 
            Queried by FreeRADIUS BEFORE tenant context is established.'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('radius_user_schema_mapping');
    }
};
