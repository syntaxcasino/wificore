<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Retired: consolidated into 0001_01_01_000001_create_users_table.php
        // to keep a single authoritative public users migration.
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
