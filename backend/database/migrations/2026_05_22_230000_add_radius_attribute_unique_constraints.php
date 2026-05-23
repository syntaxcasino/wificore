<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('radcheck') || !Schema::hasTable('radreply')) {
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
                    ) THEN
                        ALTER TABLE radcheck
                        ADD CONSTRAINT radcheck_username_attribute_unique
                        UNIQUE (username, attribute);
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'radreply_username_attribute_unique'
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
        if (!Schema::hasTable('radcheck') || !Schema::hasTable('radreply')) {
            return;
        }

        DB::unprepared("
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'radcheck_username_attribute_unique'
                ) THEN
                    ALTER TABLE radcheck
                    DROP CONSTRAINT radcheck_username_attribute_unique;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'radreply_username_attribute_unique'
                ) THEN
                    ALTER TABLE radreply
                    DROP CONSTRAINT radreply_username_attribute_unique;
                END IF;
            END
            $$;
        ");
    }
};
