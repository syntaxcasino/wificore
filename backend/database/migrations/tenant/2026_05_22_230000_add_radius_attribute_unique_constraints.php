<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tableExists = fn(string $table): bool => (bool) DB::selectOne(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = current_schema()
               AND table_name = ?
               AND table_type = 'BASE TABLE'",
            [$table]
        );

        if (!$tableExists('radcheck') || !$tableExists('radreply')) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("
                DELETE FROM radcheck older
                USING radcheck newer
                WHERE older.username = newer.username
                  AND older.attribute = newer.attribute
                  AND older.id < newer.id
            ");

            DB::statement("
                DELETE FROM radreply older
                USING radreply newer
                WHERE older.username = newer.username
                  AND older.attribute = newer.attribute
                  AND older.id < newer.id
            ");

            DB::unprepared("
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'radcheck_username_attribute_unique'
                          AND connamespace = current_schema()::regnamespace
                    ) THEN
                        ALTER TABLE radcheck
                        ADD CONSTRAINT radcheck_username_attribute_unique
                        UNIQUE (username, attribute);
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'radreply_username_attribute_unique'
                          AND connamespace = current_schema()::regnamespace
                    ) THEN
                        ALTER TABLE radreply
                        ADD CONSTRAINT radreply_username_attribute_unique
                        UNIQUE (username, attribute);
                    END IF;
                END
                $$;
            ");
        });
    }

    public function down(): void
    {
        DB::unprepared("
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'radcheck_username_attribute_unique'
                      AND connamespace = current_schema()::regnamespace
                ) THEN
                    ALTER TABLE radcheck
                    DROP CONSTRAINT radcheck_username_attribute_unique;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'radreply_username_attribute_unique'
                      AND connamespace = current_schema()::regnamespace
                ) THEN
                    ALTER TABLE radreply
                    DROP CONSTRAINT radreply_username_attribute_unique;
                END IF;
            END
            $$;
        ");
    }
};
