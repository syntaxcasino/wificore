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
        if (Schema::hasColumn('tenants', 'is_default')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_landlord');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
