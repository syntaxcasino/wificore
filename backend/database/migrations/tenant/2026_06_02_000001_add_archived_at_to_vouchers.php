<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tableExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'vouchers' AND table_schema = current_schema()) AS exists"
        );

        if (!$tableExists || !$tableExists->exists) {
            return;
        }

        $columnExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'vouchers' AND column_name = 'archived_at' AND table_schema = current_schema()) AS exists"
        );

        if ($columnExists && $columnExists->exists) {
            return;
        }

        DB::statement('ALTER TABLE vouchers ADD COLUMN archived_at TIMESTAMP NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS vouchers_archived_at_index ON vouchers (archived_at)');
    }

    public function down(): void
    {
        $columnExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'vouchers' AND column_name = 'archived_at' AND table_schema = current_schema()) AS exists"
        );

        if (!$columnExists || !$columnExists->exists) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS vouchers_archived_at_index');
        DB::statement('ALTER TABLE vouchers DROP COLUMN IF EXISTS archived_at');
    }
};
