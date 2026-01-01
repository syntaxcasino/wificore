-- =====================================================================
-- Partition Lifecycle Management - Advanced Archive Strategies
-- =====================================================================
-- This script provides tools to manage archived partition growth
-- Strategies: Compression, Tiered Storage, External Archival, Cleanup
-- =====================================================================

-- =====================================================================
-- 1. COMPRESSION - Reduce storage for archived partitions
-- =====================================================================

-- Function: Compress archived partitions older than specified days
CREATE OR REPLACE FUNCTION compress_archived_partitions(
    p_older_than_days INTEGER DEFAULT 180  -- Compress partitions older than 6 months
) RETURNS TABLE(
    schema_name TEXT,
    partition_name TEXT,
    size_before TEXT,
    size_after TEXT,
    compression_ratio NUMERIC
) AS $$
DECLARE
    v_partition RECORD;
    v_size_before BIGINT;
    v_size_after BIGINT;
BEGIN
    -- Loop through archived partitions
    FOR v_partition IN
        SELECT 
            schemaname,
            tablename,
            pg_total_relation_size(schemaname||'.'||tablename) as current_size
        FROM pg_tables
        WHERE schemaname LIKE 'ts_%'
          AND tablename ~ '_p\d{4}_\d{2}_\d{2}'
          AND NOT EXISTS (
              SELECT 1 FROM pg_inherits i
              JOIN pg_class c ON i.inhrelid = c.oid
              WHERE c.relname = pg_tables.tablename
              AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = pg_tables.schemaname)
          )
          -- Only compress partitions older than specified days
          AND to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD') 
              < CURRENT_DATE - p_older_than_days
    LOOP
        v_size_before := v_partition.current_size;
        
        -- Apply compression
        EXECUTE format('ALTER TABLE %I.%I SET (toast_compression = ''lz4'')', 
            v_partition.schemaname, v_partition.tablename);
        
        -- Rewrite table to apply compression
        EXECUTE format('VACUUM FULL %I.%I', 
            v_partition.schemaname, v_partition.tablename);
        
        -- Get new size
        SELECT pg_total_relation_size(v_partition.schemaname||'.'||v_partition.tablename) 
        INTO v_size_after;
        
        -- Return results
        schema_name := v_partition.schemaname;
        partition_name := v_partition.tablename;
        size_before := pg_size_pretty(v_size_before);
        size_after := pg_size_pretty(v_size_after);
        compression_ratio := ROUND((1 - (v_size_after::NUMERIC / v_size_before::NUMERIC)) * 100, 2);
        
        RETURN NEXT;
        
        RAISE NOTICE 'Compressed %.% from % to % (%.2f%% reduction)',
            v_partition.schemaname, v_partition.tablename, 
            pg_size_pretty(v_size_before), pg_size_pretty(v_size_after),
            compression_ratio;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION compress_archived_partitions(INTEGER) TO admin;
COMMENT ON FUNCTION compress_archived_partitions(INTEGER) IS 'Compress archived partitions older than specified days (default 180) - reduces storage by 40-60%';

-- =====================================================================
-- 2. TIERED STORAGE - Move old archives to cheaper storage
-- =====================================================================

-- Function: Create archive tablespace (run once, manually configure path)
CREATE OR REPLACE FUNCTION create_archive_tablespace(
    p_tablespace_name TEXT DEFAULT 'archive_storage',
    p_location TEXT DEFAULT '/var/lib/postgresql/archive'
) RETURNS TEXT AS $$
BEGIN
    -- Check if tablespace already exists
    IF EXISTS (SELECT 1 FROM pg_tablespace WHERE spcname = p_tablespace_name) THEN
        RETURN 'Tablespace ' || p_tablespace_name || ' already exists';
    END IF;
    
    -- Create tablespace (requires superuser)
    EXECUTE format('CREATE TABLESPACE %I LOCATION %L', p_tablespace_name, p_location);
    
    RETURN 'Tablespace ' || p_tablespace_name || ' created at ' || p_location;
EXCEPTION
    WHEN OTHERS THEN
        RETURN 'Error creating tablespace: ' || SQLERRM;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION create_archive_tablespace(TEXT, TEXT) TO admin;

-- Function: Move archived partitions to archive tablespace
CREATE OR REPLACE FUNCTION move_to_archive_storage(
    p_older_than_days INTEGER DEFAULT 365,  -- Move partitions older than 1 year
    p_tablespace_name TEXT DEFAULT 'archive_storage'
) RETURNS TABLE(
    schema_name TEXT,
    partition_name TEXT,
    status TEXT
) AS $$
DECLARE
    v_partition RECORD;
BEGIN
    -- Check if tablespace exists
    IF NOT EXISTS (SELECT 1 FROM pg_tablespace WHERE spcname = p_tablespace_name) THEN
        RAISE EXCEPTION 'Tablespace % does not exist. Create it first using create_archive_tablespace()', p_tablespace_name;
    END IF;
    
    -- Loop through old archived partitions
    FOR v_partition IN
        SELECT 
            schemaname,
            tablename
        FROM pg_tables
        WHERE schemaname LIKE 'ts_%'
          AND tablename ~ '_p\d{4}_\d{2}_\d{2}'
          AND NOT EXISTS (
              SELECT 1 FROM pg_inherits i
              JOIN pg_class c ON i.inhrelid = c.oid
              WHERE c.relname = pg_tables.tablename
              AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = pg_tables.schemaname)
          )
          AND to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD') 
              < CURRENT_DATE - p_older_than_days
    LOOP
        BEGIN
            -- Move partition to archive tablespace
            EXECUTE format('ALTER TABLE %I.%I SET TABLESPACE %I', 
                v_partition.schemaname, v_partition.tablename, p_tablespace_name);
            
            schema_name := v_partition.schemaname;
            partition_name := v_partition.tablename;
            status := 'Moved to ' || p_tablespace_name;
            
            RETURN NEXT;
            
            RAISE NOTICE 'Moved %.% to tablespace %',
                v_partition.schemaname, v_partition.tablename, p_tablespace_name;
        EXCEPTION
            WHEN OTHERS THEN
                schema_name := v_partition.schemaname;
                partition_name := v_partition.tablename;
                status := 'Error: ' || SQLERRM;
                RETURN NEXT;
        END;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION move_to_archive_storage(INTEGER, TEXT) TO admin;
COMMENT ON FUNCTION move_to_archive_storage(INTEGER, TEXT) IS 'Move archived partitions older than specified days to cheaper tablespace';

-- =====================================================================
-- 3. EXTERNAL ARCHIVAL - Export very old data to external storage
-- =====================================================================

-- Function: Generate export commands for very old partitions
CREATE OR REPLACE FUNCTION generate_export_commands(
    p_older_than_days INTEGER DEFAULT 730,  -- Export partitions older than 2 years
    p_export_path TEXT DEFAULT '/var/lib/postgresql/exports'
) RETURNS TABLE(
    schema_name TEXT,
    partition_name TEXT,
    export_command TEXT,
    drop_command TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        schemaname::TEXT,
        tablename::TEXT,
        format('COPY %I.%I TO ''%s/%s_%s.csv'' CSV HEADER',
            schemaname, tablename, p_export_path, schemaname, tablename)::TEXT as export_cmd,
        format('DROP TABLE %I.%I',
            schemaname, tablename)::TEXT as drop_cmd
    FROM pg_tables
    WHERE schemaname LIKE 'ts_%'
      AND tablename ~ '_p\d{4}_\d{2}_\d{2}'
      AND NOT EXISTS (
          SELECT 1 FROM pg_inherits i
          JOIN pg_class c ON i.inhrelid = c.oid
          WHERE c.relname = pg_tables.tablename
          AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = pg_tables.schemaname)
      )
      AND to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD') 
          < CURRENT_DATE - p_older_than_days
    ORDER BY schemaname, tablename;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION generate_export_commands(INTEGER, TEXT) TO admin;
COMMENT ON FUNCTION generate_export_commands(INTEGER, TEXT) IS 'Generate CSV export commands for very old partitions (for external archival to S3/Glacier)';

-- =====================================================================
-- 4. CONFIGURABLE RETENTION - Optional deletion of very old archives
-- =====================================================================

-- Function: Delete archived partitions older than specified days
CREATE OR REPLACE FUNCTION delete_old_archives(
    p_older_than_days INTEGER,  -- No default - must be explicit
    p_dry_run BOOLEAN DEFAULT TRUE  -- Safety: dry run by default
) RETURNS TABLE(
    schema_name TEXT,
    partition_name TEXT,
    partition_date DATE,
    size TEXT,
    action TEXT
) AS $$
DECLARE
    v_partition RECORD;
    v_total_deleted BIGINT := 0;
    v_count INTEGER := 0;
BEGIN
    -- Safety check
    IF p_older_than_days < 365 THEN
        RAISE EXCEPTION 'Safety check: Cannot delete partitions newer than 365 days. Specified: % days', p_older_than_days;
    END IF;
    
    -- Loop through old archived partitions
    FOR v_partition IN
        SELECT 
            schemaname,
            tablename,
            to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD') as partition_date,
            pg_total_relation_size(schemaname||'.'||tablename) as partition_size
        FROM pg_tables
        WHERE schemaname LIKE 'ts_%'
          AND tablename ~ '_p\d{4}_\d{2}_\d{2}'
          AND NOT EXISTS (
              SELECT 1 FROM pg_inherits i
              JOIN pg_class c ON i.inhrelid = c.oid
              WHERE c.relname = pg_tables.tablename
              AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = pg_tables.schemaname)
          )
          AND to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD') 
              < CURRENT_DATE - p_older_than_days
        ORDER BY schemaname, tablename
    LOOP
        schema_name := v_partition.schemaname;
        partition_name := v_partition.tablename;
        partition_date := v_partition.partition_date;
        size := pg_size_pretty(v_partition.partition_size);
        
        IF p_dry_run THEN
            action := 'Would delete (dry run)';
        ELSE
            -- Actually delete the partition
            EXECUTE format('DROP TABLE %I.%I', v_partition.schemaname, v_partition.tablename);
            action := 'Deleted';
            v_total_deleted := v_total_deleted + v_partition.partition_size;
            v_count := v_count + 1;
        END IF;
        
        RETURN NEXT;
    END LOOP;
    
    IF p_dry_run THEN
        RAISE NOTICE 'DRY RUN: Would delete % partitions', v_count;
    ELSE
        RAISE NOTICE 'Deleted % partitions, freed %', v_count, pg_size_pretty(v_total_deleted);
    END IF;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION delete_old_archives(INTEGER, BOOLEAN) TO admin;
COMMENT ON FUNCTION delete_old_archives(INTEGER, BOOLEAN) IS 'Delete archived partitions older than specified days (minimum 365). Use dry_run=TRUE to preview first.';

-- =====================================================================
-- 5. MONITORING - Track archive growth and health
-- =====================================================================

-- View: Archive growth statistics
CREATE OR REPLACE VIEW archive_growth_stats AS
SELECT 
    schemaname as tenant_schema,
    COUNT(*) as archived_partition_count,
    pg_size_pretty(SUM(pg_total_relation_size(schemaname||'.'||tablename))) as total_archived_size,
    MIN(to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD')) as oldest_partition,
    MAX(to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD')) as newest_archived_partition,
    CURRENT_DATE - MIN(to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD')) as retention_days
FROM pg_tables
WHERE schemaname LIKE 'ts_%'
  AND tablename ~ '_p\d{4}_\d{2}_\d{2}'
  AND NOT EXISTS (
      SELECT 1 FROM pg_inherits i
      JOIN pg_class c ON i.inhrelid = c.oid
      WHERE c.relname = pg_tables.tablename
      AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = pg_tables.schemaname)
  )
GROUP BY schemaname
ORDER BY SUM(pg_total_relation_size(schemaname||'.'||tablename)) DESC;

GRANT SELECT ON archive_growth_stats TO admin;
COMMENT ON VIEW archive_growth_stats IS 'Monitor archived partition growth per tenant';

-- Function: Get archive recommendations
CREATE OR REPLACE FUNCTION get_archive_recommendations()
RETURNS TABLE(
    tenant_schema TEXT,
    recommendation TEXT,
    potential_savings TEXT,
    action_command TEXT
) AS $$
BEGIN
    RETURN QUERY
    WITH archive_stats AS (
        SELECT 
            schemaname,
            COUNT(*) as partition_count,
            SUM(pg_total_relation_size(schemaname||'.'||tablename)) as total_size,
            MIN(to_date(substring(tablename from '_p(\d{4}_\d{2}_\d{2})'), 'YYYY_MM_DD')) as oldest_date
        FROM pg_tables
        WHERE schemaname LIKE 'ts_%'
          AND tablename ~ '_p\d{4}_\d{2}_\d{2}'
          AND NOT EXISTS (
              SELECT 1 FROM pg_inherits i
              JOIN pg_class c ON i.inhrelid = c.oid
              WHERE c.relname = pg_tables.tablename
              AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = pg_tables.schemaname)
          )
        GROUP BY schemaname
    )
    SELECT 
        schemaname::TEXT,
        CASE 
            WHEN partition_count > 365 AND oldest_date < CURRENT_DATE - 730 THEN
                'Consider external archival for partitions older than 2 years'
            WHEN partition_count > 180 AND oldest_date < CURRENT_DATE - 180 THEN
                'Consider compression for partitions older than 6 months'
            WHEN total_size > 10737418240 THEN  -- 10GB
                'Large archive size - consider tiered storage'
            ELSE
                'Archive size is manageable'
        END::TEXT as recommendation,
        CASE 
            WHEN partition_count > 365 THEN pg_size_pretty((total_size * 0.5)::BIGINT)
            WHEN partition_count > 180 THEN pg_size_pretty((total_size * 0.4)::BIGINT)
            ELSE '0 bytes'
        END::TEXT as potential_savings,
        CASE 
            WHEN partition_count > 365 THEN 
                format('SELECT * FROM generate_export_commands(730) WHERE schema_name = ''%s''', schemaname)
            WHEN partition_count > 180 THEN 
                format('SELECT * FROM compress_archived_partitions(180)', schemaname)
            ELSE
                'No action needed'
        END::TEXT as action_command
    FROM archive_stats
    ORDER BY total_size DESC;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION get_archive_recommendations() TO admin;
COMMENT ON FUNCTION get_archive_recommendations() IS 'Get automated recommendations for managing archived partition growth';

-- =====================================================================
-- 6. AUTOMATED MAINTENANCE - Schedule archive management
-- =====================================================================

-- Function: Run automated archive maintenance
CREATE OR REPLACE FUNCTION run_archive_maintenance() RETURNS TEXT AS $$
DECLARE
    v_result TEXT := '';
    v_compressed INTEGER := 0;
BEGIN
    -- Compress partitions older than 6 months
    SELECT COUNT(*) INTO v_compressed
    FROM compress_archived_partitions(180);
    
    v_result := format('Archive maintenance completed: %s partitions compressed', v_compressed);
    
    RAISE NOTICE '%', v_result;
    RETURN v_result;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION run_archive_maintenance() TO admin;

-- Schedule monthly archive maintenance (runs on 1st of each month at 3 AM)
DO $outer$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_cron') THEN
        -- Remove existing job if it exists
        PERFORM cron.unschedule('archive_maintenance');
        
        -- Schedule monthly archive maintenance
        PERFORM cron.schedule(
            'archive_maintenance',
            '0 3 1 * *',  -- Run at 3 AM on 1st of each month
            'SELECT run_archive_maintenance()'
        );
        
        RAISE NOTICE 'Archive maintenance scheduled for 1st of each month at 3 AM';
    ELSE
        RAISE NOTICE 'pg_cron not available - schedule archive maintenance manually';
    END IF;
END $outer$;

-- =====================================================================
-- Summary and Usage Instructions
-- =====================================================================
DO $$
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Partition Lifecycle Management Installed';
    RAISE NOTICE '========================================';
    RAISE NOTICE '';
    RAISE NOTICE 'Available Strategies:';
    RAISE NOTICE '1. COMPRESSION (40-60%% reduction):';
    RAISE NOTICE '   SELECT * FROM compress_archived_partitions(180);';
    RAISE NOTICE '';
    RAISE NOTICE '2. TIERED STORAGE (move to cheaper disks):';
    RAISE NOTICE '   SELECT create_archive_tablespace();';
    RAISE NOTICE '   SELECT * FROM move_to_archive_storage(365);';
    RAISE NOTICE '';
    RAISE NOTICE '3. EXTERNAL ARCHIVAL (export to S3/Glacier):';
    RAISE NOTICE '   SELECT * FROM generate_export_commands(730);';
    RAISE NOTICE '';
    RAISE NOTICE '4. OPTIONAL DELETION (if needed):';
    RAISE NOTICE '   SELECT * FROM delete_old_archives(1095, TRUE);  -- Dry run';
    RAISE NOTICE '   SELECT * FROM delete_old_archives(1095, FALSE); -- Execute';
    RAISE NOTICE '';
    RAISE NOTICE '5. MONITORING:';
    RAISE NOTICE '   SELECT * FROM archive_growth_stats;';
    RAISE NOTICE '   SELECT * FROM get_archive_recommendations();';
    RAISE NOTICE '';
    RAISE NOTICE 'Automated: Compression runs monthly at 3 AM';
    RAISE NOTICE '========================================';
END $$;
