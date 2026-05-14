<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add optimized indexes for PPPoE portal dashboard queries
 * 
 * These indexes significantly improve the performance of:
 * - Current session lookups (active sessions without stop time)
 * - Session history with time range filtering
 * - Cursor pagination for large session histories
 */
return new class extends Migration
{
    public function up(): void
    {
        // Composite index for username + time range queries with sorting
        // Used by: getOptimizedRadiusData(), sessionHistory()
        DB::statement('
            CREATE INDEX IF NOT EXISTS radacct_username_starttime 
            ON radacct(username, acctstarttime DESC)
        ');

        // Partial index for active session lookups (where acctstoptime IS NULL)
        // Used by: getOptimizedRadiusData() for current session
        DB::statement('
            CREATE INDEX IF NOT EXISTS radacct_username_active 
            ON radacct(username, acctstoptime) 
            WHERE acctstoptime IS NULL
        ');

        // Index for RADIUS connection availability check
        DB::statement('
            CREATE INDEX IF NOT EXISTS radacct_username_simple 
            ON radacct(username)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS radacct_username_starttime');
        DB::statement('DROP INDEX IF EXISTS radacct_username_active');
        DB::statement('DROP INDEX IF EXISTS radacct_username_simple');
    }
};
