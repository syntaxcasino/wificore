<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Increase interface_name column from varchar(100) to TEXT to handle JSON arrays
        Schema::table('router_services', function (Blueprint $table) {
            $table->text('interface_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('router_services', function (Blueprint $table) {
            $table->string('interface_name', 100)->nullable()->change();
        });
    }
};
