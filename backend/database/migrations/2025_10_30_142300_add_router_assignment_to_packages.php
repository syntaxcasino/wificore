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
        // Add is_global column to packages table
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_global')->default(true)->after('hide_from_client');
            $table->index('is_global');
        });

        // package_router table creation removed - now in tenant schema
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop is_global column from packages
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });
    }
};
