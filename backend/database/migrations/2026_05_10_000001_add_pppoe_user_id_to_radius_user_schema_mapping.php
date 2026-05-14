<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.radius_user_schema_mapping ADD COLUMN IF NOT EXISTS pppoe_user_id uuid NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS radius_user_schema_mapping_pppoe_user_id_unique ON public.radius_user_schema_mapping (pppoe_user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS radius_user_schema_mapping_pppoe_user_id_idx ON public.radius_user_schema_mapping (pppoe_user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS public.radius_user_schema_mapping_pppoe_user_id_idx');
        DB::statement('DROP INDEX IF EXISTS public.radius_user_schema_mapping_pppoe_user_id_unique');
        DB::statement('ALTER TABLE public.radius_user_schema_mapping DROP COLUMN IF EXISTS pppoe_user_id');
    }
};
