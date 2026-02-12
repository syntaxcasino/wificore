<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add column if it doesn't exist
        $exists = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name='routers' AND column_name='router_type'");
        
        if (empty($exists)) {
            DB::statement("ALTER TABLE routers ADD COLUMN router_type VARCHAR(20) DEFAULT 'physical' NOT NULL");
            DB::statement("CREATE INDEX routers_router_type_index ON routers(router_type)");
        }
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS routers_router_type_index");
        DB::statement("ALTER TABLE routers DROP COLUMN IF EXISTS router_type");
    }
};
