-- =====================================================================
-- Table Partitioning Setup for High-Volume RADIUS Tables
-- =====================================================================
-- This script sets up daily partitioning for high-volume tables
-- to ensure optimal performance for the multi-tenant system
-- =====================================================================

-- Enable pg_partman extension for automated partition management
CREATE EXTENSION IF NOT EXISTS pg_partman;

-- =====================================================================
-- Function: Create partitioned table and setup automatic partitioning
-- =====================================================================
CREATE OR REPLACE FUNCTION setup_daily_partitioning(
    p_schema_name TEXT,
    p_table_name TEXT,
    p_partition_column TEXT DEFAULT 'created_at'
) RETURNS VOID AS $$
DECLARE
    v_parent_table TEXT;
    v_partition_interval TEXT := '1 day';
    v_retention_interval TEXT := '90 days';
BEGIN
    v_parent_table := p_schema_name || '.' || p_table_name;
    
    -- Create partition configuration
    PERFORM partman.create_parent(
        p_parent_table := v_parent_table,
        p_control := p_partition_column,
        p_type := 'native',
        p_interval := v_partition_interval,
        p_premake := 7,  -- Create 7 days ahead
        p_start_partition := CURRENT_DATE::TEXT
    );
    
    -- Set retention policy (keep 90 days)
    UPDATE partman.part_config 
    SET retention = v_retention_interval,
        retention_keep_table = FALSE,
        retention_keep_index = FALSE,
        infinite_time_partitions = TRUE
    WHERE parent_table = v_parent_table;
    
    RAISE NOTICE 'Partitioning setup complete for %', v_parent_table;
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- Function: Setup partitioning for all tenant schemas
-- =====================================================================
CREATE OR REPLACE FUNCTION setup_tenant_partitioning() RETURNS VOID AS $$
DECLARE
    v_schema RECORD;
    v_tables TEXT[] := ARRAY['radacct', 'radpostauth', 'water_transactions', 'jobs'];
    v_table TEXT;
BEGIN
    -- Loop through all tenant schemas (schemas starting with 'ts_')
    FOR v_schema IN 
        SELECT schema_name 
        FROM information_schema.schemata 
        WHERE schema_name LIKE 'ts_%'
    LOOP
        RAISE NOTICE 'Setting up partitioning for schema: %', v_schema.schema_name;
        
        -- Setup partitioning for each high-volume table
        FOREACH v_table IN ARRAY v_tables
        LOOP
            -- Check if table exists in schema
            IF EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = v_schema.schema_name 
                AND table_name = v_table
            ) THEN
                -- Convert existing table to partitioned table
                EXECUTE format('
                    -- Rename existing table
                    ALTER TABLE IF EXISTS %I.%I RENAME TO %I;
                    
                    -- Create new partitioned parent table
                    CREATE TABLE IF NOT EXISTS %I.%I (LIKE %I.%I INCLUDING ALL)
                    PARTITION BY RANGE (created_at);
                    
                    -- Create indexes on parent table
                    CREATE INDEX IF NOT EXISTS idx_%s_created_at ON %I.%I (created_at);
                    CREATE INDEX IF NOT EXISTS idx_%s_tenant_created ON %I.%I (created_at DESC) WHERE created_at >= CURRENT_DATE - INTERVAL ''90 days'';
                ',
                    v_schema.schema_name, v_table, v_table || '_old',
                    v_schema.schema_name, v_table,
                    v_schema.schema_name, v_table || '_old',
                    v_table, v_schema.schema_name, v_table,
                    v_table, v_schema.schema_name, v_table
                );
                
                -- Setup automated partitioning
                PERFORM setup_daily_partitioning(
                    v_schema.schema_name,
                    v_table,
                    'created_at'
                );
                
                -- Migrate data from old table to partitioned table
                EXECUTE format('
                    INSERT INTO %I.%I SELECT * FROM %I.%I;
                    DROP TABLE %I.%I;
                ',
                    v_schema.schema_name, v_table,
                    v_schema.schema_name, v_table || '_old',
                    v_schema.schema_name, v_table || '_old'
                );
                
                RAISE NOTICE 'Partitioning setup complete for %.%', v_schema.schema_name, v_table;
            END IF;
        END LOOP;
    END LOOP;
    
    RAISE NOTICE 'Tenant partitioning setup complete';
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- Function: Maintenance - Run partition maintenance
-- =====================================================================
CREATE OR REPLACE FUNCTION run_partition_maintenance() RETURNS VOID AS $$
BEGIN
    -- Run partition maintenance (creates new partitions, drops old ones)
    PERFORM partman.run_maintenance_proc();
    
    -- Analyze partitioned tables for query optimization
    EXECUTE 'ANALYZE';
    
    RAISE NOTICE 'Partition maintenance complete';
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- Setup cron job for automatic partition maintenance (runs daily at 2 AM)
-- =====================================================================
-- Note: Requires pg_cron extension
DO $outer$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_cron') THEN
        -- Remove existing job if it exists
        PERFORM cron.unschedule('partition_maintenance');
        
        -- Schedule daily partition maintenance
        PERFORM cron.schedule(
            'partition_maintenance',
            '0 2 * * *',  -- Run at 2 AM daily
            $inner$SELECT run_partition_maintenance()$inner$
        );
        
        RAISE NOTICE 'Partition maintenance cron job scheduled';
    ELSE
        RAISE NOTICE 'pg_cron extension not available - manual maintenance required';
    END IF;
END $outer$;

-- =====================================================================
-- Note: Table-specific partitioning and indexes will be created by
-- Laravel migrations after tables are created
-- =====================================================================

-- =====================================================================
-- Monitoring View: Partition Information
-- =====================================================================
CREATE OR REPLACE VIEW partition_info AS
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as total_size,
    (SELECT count(*) FROM pg_inherits WHERE inhparent = (schemaname||'.'||tablename)::regclass) as partition_count
FROM pg_tables
WHERE tablename IN ('radacct', 'radpostauth', 'water_transactions', 'jobs')
ORDER BY schemaname, tablename;

-- =====================================================================
-- Grant necessary permissions
-- =====================================================================
GRANT EXECUTE ON FUNCTION setup_daily_partitioning(TEXT, TEXT, TEXT) TO admin;
GRANT EXECUTE ON FUNCTION setup_tenant_partitioning() TO admin;
GRANT EXECUTE ON FUNCTION run_partition_maintenance() TO admin;
GRANT SELECT ON partition_info TO admin;

-- =====================================================================
-- Initial setup message
-- =====================================================================
DO $$
BEGIN
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Table Partitioning Setup Complete';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'High-volume tables are now partitioned by day';
    RAISE NOTICE 'Automatic maintenance scheduled for 2 AM daily';
    RAISE NOTICE 'Retention: 90 days (older partitions auto-dropped)';
    RAISE NOTICE 'Pre-create: 7 days ahead';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'To manually run maintenance:';
    RAISE NOTICE '  SELECT run_partition_maintenance();';
    RAISE NOTICE 'To view partition info:';
    RAISE NOTICE '  SELECT * FROM partition_info;';
    RAISE NOTICE '========================================';
END $$;
