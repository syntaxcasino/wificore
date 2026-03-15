<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable()->after('os_version');
            $table->string('firmware', 100)->nullable()->after('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['serial_number', 'firmware']);
        });
    }
};
