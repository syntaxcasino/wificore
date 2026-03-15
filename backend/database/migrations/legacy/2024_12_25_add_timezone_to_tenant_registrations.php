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
        // No-op: timezone is created in 2024_12_16_create_tenant_registrations_table.php.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }
};
