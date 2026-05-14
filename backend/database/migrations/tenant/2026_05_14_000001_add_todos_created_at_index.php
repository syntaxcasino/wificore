<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::selectOne('SELECT current_schema()')?->current_schema ?? 'public';

        // Index for ORDER BY created_at DESC (tenant-admin full-list queries)
        DB::statement("
            DO \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = 'todos_created_at_idx' AND n.nspname = '{$schema}'
                ) THEN
                    CREATE INDEX \"todos_created_at_idx\" ON \"{$schema}\".\"todos\" (created_at DESC);
                END IF;
            EXCEPTION WHEN duplicate_table THEN NULL;
            END;
            \$\$;
        ");

        // Partial composite index for per-user queries: WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC
        DB::statement("
            DO \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = 'todos_user_created_at_idx' AND n.nspname = '{$schema}'
                ) THEN
                    CREATE INDEX \"todos_user_created_at_idx\" ON \"{$schema}\".\"todos\" (user_id, created_at DESC) WHERE deleted_at IS NULL;
                END IF;
            EXCEPTION WHEN duplicate_table THEN NULL;
            END;
            \$\$;
        ");
    }

    public function down(): void
    {
        $schema = DB::selectOne('SELECT current_schema()')?->current_schema ?? 'public';

        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".\"todos_created_at_idx\"");
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".\"todos_user_created_at_idx\"");
    }
};
