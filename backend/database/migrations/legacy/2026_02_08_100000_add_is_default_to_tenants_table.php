<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds is_default flag to tenants table.
     * Default tenants are excluded from subscription payment enforcement.
     * Only one default tenant should exist at any time.
     */
    public function up(): void
    {
        // No-op: is_default is created in 0001_01_01_000000_create_tenants_table.php.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }
};
