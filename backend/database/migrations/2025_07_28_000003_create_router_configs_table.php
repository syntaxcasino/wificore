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
        Schema::create('router_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->string('config_type', 50);
            $table->json('config_data')->nullable();
            $table->text('config_content')->nullable();
            $table->timestamps();
            
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('cascade');
            
            $table->index('router_id');
            $table->index('config_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_configs');
    }
};
