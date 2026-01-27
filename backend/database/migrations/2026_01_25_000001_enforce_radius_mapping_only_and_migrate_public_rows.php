<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.get_user_schema(p_username VARCHAR)
RETURNS VARCHAR AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    SELECT schema_name INTO v_schema
    FROM public.radius_user_schema_mapping
    WHERE username = p_username
    AND is_active = true
    LIMIT 1;

    RETURN v_schema;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_authorize_check(p_username VARCHAR)
RETURNS TABLE(
    id BIGINT,
    username VARCHAR,
    attribute VARCHAR,
    value VARCHAR,
    op VARCHAR
) AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    v_schema := public.get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN;
    END IF;

    EXECUTE format('SET search_path TO %I, public', v_schema);

    RETURN QUERY EXECUTE format('
        SELECT
            id::bigint,
            username::varchar,
            attribute::varchar,
            value::varchar,
            op::varchar
        FROM %I.radcheck
        WHERE username = $1
        ORDER BY id
    ', v_schema)
    USING p_username;

    EXECUTE 'SET search_path TO public';
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_authorize_reply(p_username VARCHAR)
RETURNS TABLE(
    id BIGINT,
    username VARCHAR,
    attribute VARCHAR,
    value VARCHAR,
    op VARCHAR
) AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    v_schema := public.get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN;
    END IF;

    EXECUTE format('SET search_path TO %I, public', v_schema);

    RETURN QUERY EXECUTE format('
        SELECT
            id::bigint,
            username::varchar,
            attribute::varchar,
            value::varchar,
            op::varchar
        FROM %I.radreply
        WHERE username = $1
        ORDER BY id
    ', v_schema)
    USING p_username;

    EXECUTE 'SET search_path TO public';
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_post_auth_insert(
    p_username VARCHAR,
    p_pass VARCHAR,
    p_reply VARCHAR
)
RETURNS INTEGER AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    v_schema := public.get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('SET search_path TO %I, public', v_schema);

    EXECUTE format('
        INSERT INTO %I.radpostauth (username, pass, reply, authdate)
        VALUES ($1, $2, $3, NOW())
    ', v_schema)
    USING p_username, p_pass, p_reply;

    EXECUTE 'SET search_path TO public';

    RETURN 1;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_start(
    p_acct_session_id VARCHAR,
    p_acct_unique_session_id VARCHAR,
    p_username VARCHAR,
    p_realm VARCHAR,
    p_nas_ip VARCHAR,
    p_nas_port_id VARCHAR,
    p_event_timestamp INTEGER,
    p_acct_authentic VARCHAR,
    p_connect_info VARCHAR,
    p_called_station_id VARCHAR,
    p_calling_station_id VARCHAR,
    p_service_type VARCHAR,
    p_framed_protocol VARCHAR,
    p_framed_ip_address VARCHAR
)
RETURNS INTEGER AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    v_schema := public.get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('SET search_path TO %I, public', v_schema);

    EXECUTE format('
        INSERT INTO %I.radacct (
            acctsessionid,
            acctuniqueid,
            username,
            realm,
            nasipaddress,
            nasportid,
            acctstarttime,
            acctupdatetime,
            acctauthentic,
            connectinfo_start,
            calledstationid,
            callingstationid,
            servicetype,
            framedprotocol,
            framedipaddress,
            acctstartdelay
        )
        SELECT
            $1,
            $2,
            $3,
            NULLIF($4, ''''),
            NULLIF($5, '''')::inet,
            NULLIF($6, ''''),
            TO_TIMESTAMP($7),
            TO_TIMESTAMP($7),
            NULLIF($8, ''''),
            NULLIF($9, ''''),
            NULLIF($10, ''''),
            NULLIF($11, ''''),
            NULLIF($12, ''''),
            NULLIF($13, ''''),
            NULLIF($14, '''')::inet,
            0
        WHERE NOT EXISTS (
            SELECT 1
            FROM %I.radacct
            WHERE acctuniqueid = $2
        )
    ', v_schema, v_schema)
    USING
        p_acct_session_id,
        p_acct_unique_session_id,
        p_username,
        p_realm,
        p_nas_ip,
        p_nas_port_id,
        p_event_timestamp,
        p_acct_authentic,
        p_connect_info,
        p_called_station_id,
        p_calling_station_id,
        p_service_type,
        p_framed_protocol,
        p_framed_ip_address;

    EXECUTE 'SET search_path TO public';

    RETURN 1;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_update(
    p_username VARCHAR,
    p_acct_unique_session_id VARCHAR,
    p_framed_ip_address VARCHAR,
    p_acct_session_time INTEGER,
    p_acct_input_octets BIGINT,
    p_acct_output_octets BIGINT
)
RETURNS INTEGER AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    v_schema := public.get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('SET search_path TO %I, public', v_schema);

    EXECUTE format('
        UPDATE %I.radacct
        SET
            acctupdatetime = NOW(),
            acctsessiontime = $1,
            acctinputoctets = $2,
            acctoutputoctets = $3,
            framedipaddress = NULLIF($4, '''')::inet
        WHERE acctuniqueid = $5
        AND username = $6
    ', v_schema)
    USING
        p_acct_session_time,
        p_acct_input_octets,
        p_acct_output_octets,
        p_framed_ip_address,
        p_acct_unique_session_id,
        p_username;

    EXECUTE 'SET search_path TO public';

    RETURN 1;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.radius_accounting_stop(
    p_username VARCHAR,
    p_acct_unique_session_id VARCHAR,
    p_event_timestamp INTEGER,
    p_acct_session_time INTEGER,
    p_acct_input_octets BIGINT,
    p_acct_output_octets BIGINT,
    p_acct_terminate_cause VARCHAR,
    p_connect_info VARCHAR
)
RETURNS INTEGER AS $$
DECLARE
    v_schema VARCHAR;
BEGIN
    v_schema := public.get_user_schema(p_username);

    IF v_schema IS NULL OR v_schema = '' THEN
        RETURN 1;
    END IF;

    EXECUTE format('SET search_path TO %I, public', v_schema);

    EXECUTE format('
        UPDATE %I.radacct
        SET
            acctstoptime = TO_TIMESTAMP($1),
            acctsessiontime = $2,
            acctinputoctets = $3,
            acctoutputoctets = $4,
            acctterminatecause = NULLIF($5, ''''),
            connectinfo_stop = NULLIF($6, '''')
        WHERE acctuniqueid = $7
        AND username = $8
    ', v_schema)
    USING
        p_event_timestamp,
        p_acct_session_time,
        p_acct_input_octets,
        p_acct_output_octets,
        p_acct_terminate_cause,
        p_connect_info,
        p_acct_unique_session_id,
        p_username;

    EXECUTE 'SET search_path TO public';

    RETURN 1;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);

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

        EXECUTE format($sql$
            INSERT INTO %I.radacct (
                acctsessionid,
                acctuniqueid,
                username,
                groupname,
                realm,
                nasipaddress,
                nasportid,
                nasporttype,
                acctstarttime,
                acctupdatetime,
                acctstoptime,
                acctinterval,
                acctsessiontime,
                acctauthentic,
                connectinfo_start,
                connectinfo_stop,
                acctinputoctets,
                acctoutputoctets,
                calledstationid,
                callingstationid,
                acctterminatecause,
                servicetype,
                framedprotocol,
                framedipaddress
            )
            SELECT
                pa.acctsessionid,
                pa.acctuniqueid,
                pa.username,
                '',
                pa.realm,
                pa.nasipaddress,
                pa.nasportid,
                NULL,
                pa.acctstarttime,
                pa.acctupdatetime,
                pa.acctstoptime,
                pa.acctinterval,
                pa.acctsessiontime,
                pa.acctauthentic,
                pa.connectinfo_start,
                pa.connectinfo_stop,
                pa.acctinputoctets,
                pa.acctoutputoctets,
                COALESCE(pa.calledstationid, ''),
                COALESCE(pa.callingstationid, ''),
                COALESCE(pa.acctterminatecause, ''),
                pa.servicetype,
                pa.framedprotocol,
                pa.framedipaddress
            FROM public.radacct pa
            WHERE pa.username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
            ON CONFLICT (acctuniqueid) DO NOTHING
        $sql$, r.schema_name, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radcheck
            WHERE username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
        $sql$, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radreply
            WHERE username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
        $sql$, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radpostauth
            WHERE username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
        $sql$, r.schema_name);

        EXECUTE format($sql$
            DELETE FROM public.radacct
            WHERE username IN (
                SELECT username
                FROM public.radius_user_schema_mapping
                WHERE schema_name = %L
                AND is_active = true
            )
        $sql$, r.schema_name);
    END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
    }
};
