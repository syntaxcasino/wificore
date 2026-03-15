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
        // No-op: schema fields are created in 0001_01_01_000000_create_tenants_table.php.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: retained for migration history compatibility.
    }
};
