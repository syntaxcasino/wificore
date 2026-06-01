<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routers')) {
            return;
        }

        Schema::table('routers', function (Blueprint $table) {
            if (! Schema::hasColumn('routers', 'architecture_name')) {
                $table->string('architecture_name', 100)->nullable()->after('os_version');
            }
            if (! Schema::hasColumn('routers', 'board_name')) {
                $table->string('board_name', 100)->nullable()->after('architecture_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('routers')) {
            return;
        }

        Schema::table('routers', function (Blueprint $table) {
            if (Schema::hasColumn('routers', 'board_name')) {
                $table->dropColumn('board_name');
            }
            if (Schema::hasColumn('routers', 'architecture_name')) {
                $table->dropColumn('architecture_name');
            }
        });
    }
};
