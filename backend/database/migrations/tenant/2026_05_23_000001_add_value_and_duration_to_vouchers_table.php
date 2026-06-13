<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (!Schema::hasColumn('vouchers', 'value')) {
                $table->decimal('value', 10, 2)->nullable()->after('code');
            }
            if (!Schema::hasColumn('vouchers', 'package_duration_days')) {
                $table->unsignedSmallInteger('package_duration_days')->nullable()->after('value');
            }
            if (!Schema::hasColumn('vouchers', 'used_by_type')) {
                $table->string('used_by_type', 30)->nullable()->after('used_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('vouchers', 'value') ? 'value' : null,
                Schema::hasColumn('vouchers', 'package_duration_days') ? 'package_duration_days' : null,
                Schema::hasColumn('vouchers', 'used_by_type') ? 'used_by_type' : null,
            ]));
        });
    }
};
