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
            if (!$columnExists('pppoe_users', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('suspended_at');
            }
            if (!$columnExists('pppoe_users', 'pause_ends_at')) {
                $table->timestamp('pause_ends_at')->nullable()->after('paused_at');
            }
            if (!$columnExists('pppoe_users', 'pause_reason')) {
                $table->string('pause_reason', 100)->nullable()->after('pause_ends_at');
            }
            if (!$columnExists('pppoe_users', 'pending_package_id')) {
                $table->uuid('pending_package_id')->nullable()->after('package_id');
            }
            if (!$columnExists('pppoe_users', 'plan_switch_effective_date')) {
                $table->timestamp('plan_switch_effective_date')->nullable()->after('pending_package_id');
            }
            if (!$columnExists('pppoe_users', 'balance')) {
                $table->decimal('balance', 10, 2)->default(0)->after('amount_paid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pppoe_users', function (Blueprint $table) {
            $table->dropColumn([
                'paused_at',
                'pause_ends_at',
                'pause_reason',
                'pending_package_id',
                'plan_switch_effective_date',
            ]);
        });
    }
};
