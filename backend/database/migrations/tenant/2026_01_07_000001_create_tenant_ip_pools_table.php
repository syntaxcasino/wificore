<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No-op: tenant_ip_pools has been moved to the public schema.
     * See: 2026_02_08_120000_create_tenant_ip_pools_in_public_schema.php
     * This file is kept so existing migration records don't break.
     */
    public function up(): void
    {
        // IP pools are now managed in the public schema with tenant_id FK.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_ip_pools');
    }
};
