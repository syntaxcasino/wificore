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
        // Retired: consolidated into 2025_10_29_000001_create_radius_core_tables.php
        // to keep a single authoritative public RADIUS base migration.
        return;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: migration retired in favor of consolidated base migration.
        return;
    }
};
