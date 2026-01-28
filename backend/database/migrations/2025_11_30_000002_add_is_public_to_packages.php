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
        Schema::table('packages', function (Blueprint $table) {
            // Add is_public column to control package visibility
            $table->boolean('is_public')->default(true)->after('is_active');
        });

        // Update existing packages to be public by default
        // Use DB::raw for PostgreSQL boolean compatibility
        DB::table('packages')->update(['is_public' => DB::raw('true')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
