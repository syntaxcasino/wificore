<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::table('information_schema.schemata')
            ->whereRaw("schema_name NOT IN ('public','pg_catalog','information_schema','pg_toast','archive')")
            ->whereRaw("schema_name LIKE 'ts_%'")
            ->pluck('schema_name');

        foreach ($schemas as $schema) {
            $hasStart = DB::table('information_schema.columns')
                ->where('table_schema', $schema)
                ->where('table_name', 'radacct')
                ->where('column_name', 'acctstartdelay')
                ->exists();

            if (!$hasStart) {
                DB::statement("ALTER TABLE \"{$schema}\".radacct ADD COLUMN acctstartdelay BIGINT NOT NULL DEFAULT 0");
            }

            $hasStop = DB::table('information_schema.columns')
                ->where('table_schema', $schema)
                ->where('table_name', 'radacct')
                ->where('column_name', 'acctstopdelay')
                ->exists();

            if (!$hasStop) {
                DB::statement("ALTER TABLE \"{$schema}\".radacct ADD COLUMN acctstopdelay BIGINT NOT NULL DEFAULT 0");
            }

            DB::statement("ALTER TABLE \"{$schema}\".radacct ALTER COLUMN nasportid TYPE VARCHAR(64)");
        }

        // Also widen public.radacct if it exists
        $publicExists = (bool) (DB::selectOne("SELECT to_regclass('public.radacct') AS t")->t ?? null);
        if ($publicExists) {
            DB::statement("ALTER TABLE public.radacct ALTER COLUMN nasportid TYPE VARCHAR(64)");
        }
    }

    public function down(): void
    {
        $schemas = DB::table('information_schema.schemata')
            ->whereRaw("schema_name NOT IN ('public','pg_catalog','information_schema','pg_toast','archive')")
            ->whereRaw("schema_name LIKE 'ts_%'")
            ->pluck('schema_name');

        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".radacct DROP COLUMN IF EXISTS acctstartdelay");
            DB::statement("ALTER TABLE \"{$schema}\".radacct DROP COLUMN IF EXISTS acctstopdelay");
        }
    }
};
