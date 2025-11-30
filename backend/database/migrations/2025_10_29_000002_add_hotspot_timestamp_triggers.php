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
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION update_hotspot_users_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trigger_hotspot_users_updated_at
    BEFORE UPDATE ON hotspot_users
    FOR EACH ROW
    EXECUTE FUNCTION update_hotspot_users_updated_at();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION update_hotspot_sessions_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trigger_hotspot_sessions_updated_at
    BEFORE UPDATE ON hotspot_sessions
    FOR EACH ROW
    EXECUTE FUNCTION update_hotspot_sessions_updated_at();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION update_radius_sessions_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trigger_radius_sessions_updated_at
    BEFORE UPDATE ON radius_sessions
    FOR EACH ROW
    EXECUTE FUNCTION update_radius_sessions_updated_at();
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trigger_radius_sessions_updated_at ON radius_sessions;');
        DB::unprepared('DROP FUNCTION IF EXISTS update_radius_sessions_updated_at();');

        DB::unprepared('DROP TRIGGER IF EXISTS trigger_hotspot_sessions_updated_at ON hotspot_sessions;');
        DB::unprepared('DROP FUNCTION IF EXISTS update_hotspot_sessions_updated_at();');

        DB::unprepared('DROP TRIGGER IF EXISTS trigger_hotspot_users_updated_at ON hotspot_users;');
        DB::unprepared('DROP FUNCTION IF EXISTS update_hotspot_users_updated_at();');
    }
};
