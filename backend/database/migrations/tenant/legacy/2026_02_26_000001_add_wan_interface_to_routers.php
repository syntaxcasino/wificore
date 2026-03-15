<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('routers', 'wan_interface')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->string('wan_interface', 64)->nullable()->after('port');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('routers', 'wan_interface')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->dropColumn('wan_interface');
            });
        }
    }
};
