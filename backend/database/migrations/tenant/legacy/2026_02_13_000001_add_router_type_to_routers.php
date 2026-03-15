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
        Schema::table('routers', function (Blueprint $table) {
            $table->string('router_type', 20)->default('physical')->after('model');
            $table->index('router_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropIndex(['router_type']);
            $table->dropColumn('router_type');
        });
    }
};
