<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add optimized indexes for RADIUS radacct table - Session History queries
 * 
 * CRITICAL for PPPoE portal session history endpoint performance
 */
return new class extends Migration
{
    public function up(): void
    {
        // OPTIMIZATION: Composite index for session history queries
        // Used by: sessionHistory() - filters by username + start time range
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_radacct_username_starttime 
            ON radacct(username, acctstarttime DESC)
        ');

        // OPTIMIZATION: Partial index for active sessions (no stop time)
        // Used by: getOptimizedRadiusData() for current session lookup
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_radacct_username_active 
            ON radacct(username, acctstarttime DESC) 
            WHERE acctstoptime IS NULL
        ');

        // OPTIMIZATION: Index for stats aggregation queries
        // Used by: getOptimizedRadiusData() for 30-day stats
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_radacct_username_stats 
            ON radacct(username, acctstarttime) 
            INCLUDE (acctsessiontime, acctinputoctets, acctoutputoctets)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_radacct_username_starttime');
        DB::statement('DROP INDEX IF EXISTS idx_radacct_username_active');
        DB::statement('DROP INDEX IF EXISTS idx_radacct_username_stats');
    }
};
