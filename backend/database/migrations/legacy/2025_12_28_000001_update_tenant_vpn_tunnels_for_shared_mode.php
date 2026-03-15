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
        // Retired: consolidated into 2025_12_06_000004_create_tenant_vpn_tunnels_table.php
        // to keep a single authoritative public tenant_vpn_tunnels migration.
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
