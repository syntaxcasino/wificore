<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate RADIUS data from public schema to tenant schemas.
 * 
 * This migration moves existing radcheck, radreply, radpostauth, and radacct
 * entries from the public schema to their respective tenant schemas based on
 * the radius_user_schema_mapping table. After migration, it cleans up the
 * public schema by removing the migrated entries.
 * 
 * NOTE: Function definitions have been consolidated into:
 * 2026_01_31_100000_consolidate_radius_functions_final.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migrate RADIUS data from public to tenant schemas
        DB::statement(<<<'SQL'
DO $$
DECLARE
    r record;
BEGIN
    FOR r IN (
        SELECT DISTINCT schema_name
        FROM public.radius_user_schema_mapping
        WHERE is_active = true
        AND schema_name IS NOT NULL
        AND schema_name <> ''
        AND schema_name <> 'public'
    ) LOOP
        -- Migrate radcheck entries
        EXECUTE format($sql$
            INSERT INTO %I.radcheck (username, attribute, op, value, created_at, updated_at)
            SELECT prc.username, prc.attribute, prc.op, prc.value, NOW(), NOW()
            FROM public.radcheck prc
            WHERE prc.username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
            AND NOT EXISTS (
                SELECT 1
                FROM %I.radcheck trc
                WHERE trc.username = prc.username
                AND trc.attribute = prc.attribute
                AND trc.op = prc.op
                AND trc.value = prc.value
            )
        $sql$, r.schema_name, r.schema_name, r.schema_name);

        -- Migrate radreply entries
        EXECUTE format($sql$
            INSERT INTO %I.radreply (username, attribute, op, value, created_at, updated_at)
            SELECT prr.username, prr.attribute, prr.op, prr.value, NOW(), NOW()
            FROM public.radreply prr
            WHERE prr.username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
            AND NOT EXISTS (
                SELECT 1
                FROM %I.radreply trr
                WHERE trr.username = prr.username
                AND trr.attribute = prr.attribute
                AND trr.op = prr.op
                AND trr.value = prr.value
            )
        $sql$, r.schema_name, r.schema_name, r.schema_name);

        -- Migrate radpostauth entries
        EXECUTE format($sql$
            INSERT INTO %I.radpostauth (username, pass, reply, authdate)
            SELECT ppa.username, COALESCE(ppa.pass, ''), COALESCE(ppa.reply, ''), COALESCE(ppa.authdate, NOW())
            FROM public.radpostauth ppa
            WHERE ppa.username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
            AND NOT EXISTS (
                SELECT 1
                FROM %I.radpostauth tpa
                WHERE tpa.username = ppa.username
                AND tpa.pass = ppa.pass
                AND tpa.reply = ppa.reply
                AND tpa.authdate = ppa.authdate
            )
        $sql$, r.schema_name, r.schema_name, r.schema_name);

        -- Migrate radacct entries
        EXECUTE format($sql$
            INSERT INTO %I.radacct (
                acctsessionid, acctuniqueid, username, groupname, realm,
                nasipaddress, nasportid, nasporttype, acctstarttime, acctupdatetime,
                acctstoptime, acctinterval, acctsessiontime, acctauthentic,
                connectinfo_start, connectinfo_stop, acctinputoctets, acctoutputoctets,
                calledstationid, callingstationid, acctterminatecause,
                servicetype, framedprotocol, framedipaddress
            )
            SELECT
                pa.acctsessionid, pa.acctuniqueid, pa.username, '',
                pa.realm, pa.nasipaddress, pa.nasportid, NULL,
                pa.acctstarttime, pa.acctupdatetime, pa.acctstoptime,
                pa.acctinterval, pa.acctsessiontime, pa.acctauthentic,
                pa.connectinfo_start, pa.connectinfo_stop,
                pa.acctinputoctets, pa.acctoutputoctets,
                COALESCE(pa.calledstationid, ''), COALESCE(pa.callingstationid, ''),
                COALESCE(pa.acctterminatecause, ''),
                pa.servicetype, pa.framedprotocol, pa.framedipaddress
            FROM public.radacct pa
            WHERE pa.username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
            ON CONFLICT (acctuniqueid) DO NOTHING
        $sql$, r.schema_name, r.schema_name);

        -- Clean up public schema after migration
        EXECUTE format($sql$
            DELETE FROM public.radcheck
            WHERE username IN (
                SELECT username FROM public.radius_user_schema_mapping
                WHERE schema_name = %L AND is_active = true
            )
        $sql$, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radreply
            WHERE username IN (
                SELECT username FROM public.radius_user_schema_mapping
                WHERE schema_name = %L AND is_active = true
            )
        $sql$, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radpostauth
            WHERE username IN (
                SELECT username FROM public.radius_user_schema_mapping
                WHERE schema_name = %L AND is_active = true
            )
        $sql$, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radacct
            WHERE username IN (
                SELECT username FROM public.radius_user_schema_mapping
                WHERE schema_name = %L AND is_active = true
            )
        $sql$, r.schema_name);
    END LOOP;
END $$;
SQL);

        \Log::info("Migrated RADIUS data from public to tenant schemas");
    }

    public function down(): void
    {
        // Data migration is not reversible - entries have been moved
        \Log::warning("RADIUS data migration rollback not supported - data has been moved to tenant schemas");
    }
};
