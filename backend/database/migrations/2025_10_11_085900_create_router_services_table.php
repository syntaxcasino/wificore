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
        Schema::create('router_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('router_id');
            $table->string('service_type', 50);
            $table->string('service_name', 100);
            $table->json('interfaces')->default('[]');
            $table->json('configuration')->default('{}');
            $table->string('status', 20)->default('inactive');
            $table->integer('active_users')->default(0);
            $table->integer('total_sessions')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('router_id')
                ->references('id')
                ->on('routers')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('router_id');
            $table->index('service_type');
            $table->index('status');
            $table->index('enabled');
        });

        // Note: CHECK constraints will be added by init.sql or manually if needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_services');
    }
};
