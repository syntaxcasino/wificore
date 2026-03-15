<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements CASCADE');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm CASCADE');
        DB::statement('DROP EXTENSION IF EXISTS "pgcrypto"');
        DB::statement('DROP EXTENSION IF EXISTS "uuid-ossp"');
    }
};
