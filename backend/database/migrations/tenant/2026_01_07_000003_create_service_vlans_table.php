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
        Schema::create('service_vlans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_service_id');
            $table->integer('vlan_id');
            $table->string('vlan_name', 100)->nullable();
            $table->string('parent_interface', 100);
            $table->enum('service_type', ['hotspot', 'pppoe']);
            $table->boolean('auto_generated')->default(true);
            $table->timestamps();

            $table->foreign('router_service_id')->references('id')->on('router_services')->onDelete('cascade');
            
            $table->unique(['router_service_id', 'vlan_id']);
            $table->index(['router_service_id', 'service_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_vlans');
    }
};
