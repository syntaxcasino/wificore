<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vouchers') && !Schema::hasColumn('vouchers', 'archived_at')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vouchers') && Schema::hasColumn('vouchers', 'archived_at')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            });
        }
    }
};
