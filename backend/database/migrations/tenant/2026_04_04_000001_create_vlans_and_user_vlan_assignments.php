<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: VLANs are tenant-specific network segments
     * Each tenant manages their own VLAN assignments for user isolation
     */
    public function up(): void
    {
        Schema::create('vlans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('vlan_id'); // 1-4094 per IEEE 802.1Q
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('network_range', 50)->nullable(); // e.g., "192.168.10.0/24"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            // Indexes for performance
            $table->unique(['vlan_id']);
            $table->index(['is_active', 'is_available']);
            $table->index('name');
        });

        // User VLAN assignments - tracks which users are assigned to which VLANs
        Schema::create('user_vlan_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('vlan_id');
            $table->timestamp('assigned_at');
            $table->string('reason', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('vlan_id')->references('id')->on('vlans')->onDelete('cascade');

            // Indexes
            $table->unique(['user_id', 'is_active']);
            $table->index(['vlan_id', 'is_active']);
            $table->index('assigned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_vlan_assignments');
        Schema::dropIfExists('vlans');
    }
};
