<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $columnExists = function (string $table, string $column): bool {
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT FROM information_schema.columns
                    WHERE table_schema = CURRENT_SCHEMA()
                    AND table_name = ?
                    AND column_name = ?
                ) as exists
            ", [$table, $column]);
            return (bool) ($result->exists ?? false);
        };

        Schema::table('pppoe_users', function (Blueprint $table) use ($columnExists) {
            if (!$columnExists('pppoe_users', 'pause_count')) {
                $table->unsignedSmallInteger('pause_count')->default(0)->after('pause_reason');
            }
            if (!$columnExists('pppoe_users', 'pause_billing_cycle_start')) {
                $table->date('pause_billing_cycle_start')->nullable()->after('pause_count');
            }
            if (!$columnExists('pppoe_users', 'pause_duration_days')) {
                $table->unsignedSmallInteger('pause_duration_days')->nullable()->after('pause_billing_cycle_start');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pppoe_users', function (Blueprint $table) {
            $table->dropColumn([
                'pause_count',
                'pause_billing_cycle_start',
                'pause_duration_days',
            ]);
        });
    }
};
