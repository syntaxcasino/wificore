<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Use a DO block so the entire conditional ADD COLUMN runs atomically
        // within the existing transaction on the write PDO. This avoids any
        // read-replica routing for the existence check.
        DB::statement("
            DO \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = 'vouchers'
                    AND n.nspname = current_schema()
                ) AND NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_attribute a
                    JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = 'vouchers'
                    AND a.attname = 'archived_at'
                    AND a.attnum > 0
                    AND NOT a.attisdropped
                    AND n.nspname = current_schema()
                ) THEN
                    ALTER TABLE vouchers ADD COLUMN archived_at TIMESTAMP NULL;
                    CREATE INDEX vouchers_archived_at_index ON vouchers (archived_at);
                END IF;
            END
            \$\$
        ");
    }

    public function down(): void
    {
        DB::statement("
            DO \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_catalog.pg_attribute a
                    JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relname = 'vouchers'
                    AND a.attname = 'archived_at'
                    AND a.attnum > 0
                    AND NOT a.attisdropped
                    AND n.nspname = current_schema()
                ) THEN
                    DROP INDEX IF EXISTS vouchers_archived_at_index;
                    ALTER TABLE vouchers DROP COLUMN IF EXISTS archived_at;
                END IF;
            END
            \$\$
        ");
    }
};
