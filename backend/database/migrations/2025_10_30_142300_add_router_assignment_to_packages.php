<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add is_global column to packages table
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_global')->default(true)->after('hide_from_client');
            $table->index('is_global');
        });

        // Create package_router pivot table for specific router assignments
        Schema::create('package_router', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('package_id');
            $table->uuid('router_id');
            $table->uuid('tenant_id'); // For faster queries and data integrity
            $table->timestamps();

            // Foreign keys
            $table->foreign('package_id')
                ->references('id')
                ->on('packages')
                ->onDelete('cascade');
            
            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');
            
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // Indexes
            $table->index('package_id');
            $table->index('router_id');
            $table->index('tenant_id');
            
            // Unique constraint - a package can only be assigned once to a router
            $table->unique(['package_id', 'router_id']);
        });

        // Note: router_id column already exists in payments table from previous migration
        // No need to add it again
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Don't drop router_id from payments table as it was added by a previous migration
        
        // Drop package_router pivot table
        Schema::dropIfExists('package_router');

        // Drop is_global column from packages
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });
    }
};
