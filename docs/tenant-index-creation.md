# Documentation: Creating Indexes in Existing Tenant Schemas

## Overview
This document outlines the complete process for creating database indexes in existing tenant schemas for the WiFiCore multi-tenant application.

## Prerequisites
- Docker Compose running with production configuration
- Access to the PostgreSQL database
- Tenant schema names (from `tenants` table)

## Step 1: Check Existing Tenant Schemas

```bash
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT id, name, slug, schema_name, schema_created 
FROM tenants 
ORDER BY name;
"
```

## Step 2: Check Tables in Tenant Schema

```bash
# Replace 'ts_f53823aac664' with your actual tenant schema name
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'ts_f53823aac664' 
AND table_type = 'BASE TABLE'
ORDER BY table_name;
"
```

## Step 3: Create Indexes in Tenant Schema

### Method 1: Direct SQL (Recommended for existing tenants)

```bash
# Set search path to tenant schema and create indexes
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SET search_path TO ts_f53823aac664, public;
CREATE INDEX IF NOT EXISTS routers_status_index ON routers(status);
CREATE INDEX IF NOT EXISTS router_services_router_id_index ON router_services(router_id);
CREATE INDEX IF NOT EXISTS router_services_service_type_index ON router_services(service_type);
CREATE INDEX IF NOT EXISTS access_points_router_id_index ON access_points(router_id);
CREATE INDEX IF NOT EXISTS access_points_status_index ON access_points(status);
"
```

### Method 2: For All Tenants (If they have router tables)

```bash
# Create indexes for all tenants that have router tables
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
DO \$\$
DECLARE
    tenant_record RECORD;
BEGIN
    FOR tenant_record IN 
        SELECT schema_name FROM tenants WHERE schema_created = true
    LOOP
        BEGIN
            EXECUTE format('SET search_path TO %I, public', tenant_record.schema_name);
            
            -- Only create if tables exist
            IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = tenant_record.schema_name AND table_name = 'routers') THEN
                EXECUTE 'CREATE INDEX IF NOT EXISTS routers_status_index ON routers(status)';
            END IF;
            
            IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = tenant_record.schema_name AND table_name = 'router_services') THEN
                EXECUTE 'CREATE INDEX IF NOT EXISTS router_services_router_id_index ON router_services(router_id)';
                EXECUTE 'CREATE INDEX IF NOT EXISTS router_services_service_type_index ON router_services(service_type)';
            END IF;
            
            IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = tenant_record.schema_name AND table_name = 'access_points') THEN
                EXECUTE 'CREATE INDEX IF NOT EXISTS access_points_router_id_index ON access_points(router_id)';
                EXECUTE 'CREATE INDEX IF NOT EXISTS access_points_status_index ON access_points(status)';
            END IF;
            
            RAISE NOTICE 'Indexes processed for schema: %', tenant_record.schema_name;
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'Error processing schema %: %', tenant_record.schema_name, SQLERRM;
        END;
    END LOOP;
END \$\$;
"
```

## Step 4: Verify Index Creation

```bash
# Check indexes in specific tenant schema
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT schemaname, tablename, indexname 
FROM pg_indexes 
WHERE schemaname = 'ts_f53823aac664' 
AND tablename IN ('routers', 'router_services', 'access_points')
ORDER BY tablename, indexname;
"
```

## Step 5: Check All Tenant Indexes (Optional)

```bash
# Check indexes across all tenant schemas
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT schemaname, tablename, indexname 
FROM pg_indexes 
WHERE schemaname LIKE 'ts_%' 
AND tablename IN ('routers', 'router_services', 'access_points')
ORDER BY schemaname, tablename, indexname;
"
```

## Migration File Approach (For New Tenants)

For new tenants, the migration system automatically handles index creation:

**File Location**: `backend/database/migrations/tenant/2026_01_17_000000_add_indexes_to_router_tables.php`

The migration will be executed automatically when new tenants are created via the `TenantMigrationManager`.

## Common Index Patterns

### Router Tables Indexes:
- `routers_status_index` - on `routers(status)`
- `router_services_router_id_index` - on `router_services(router_id)`
- `router_services_service_type_index` - on `router_services(service_type)`
- `access_points_router_id_index` - on `access_points(router_id)`
- `access_points_status_index` - on `access_points(status)`

### Custom Index Creation Template:
```bash
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SET search_path TO [TENANT_SCHEMA_NAME], public;
CREATE INDEX IF NOT EXISTS [INDEX_NAME] ON [TABLE_NAME]([COLUMN_NAME]);
"
```

## Troubleshooting

### Error: "relation does not exist"
- Check if the table exists in the tenant schema
- Verify the schema name is correct
- Ensure the tenant has been properly set up

### Error: "column does not exist"
- Verify the column name exists in the table
- Check table structure with `\d [table_name]` in psql

### Check Schema Setup:
```bash
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT schema_name 
FROM information_schema.schemata 
WHERE schema_name LIKE 'ts_%';
"
```

## Best Practices

1. **Always use `IF NOT EXISTS`** to prevent errors when indexes already exist
2. **Set search path** to tenant schema before creating indexes
3. **Verify table existence** before creating indexes
4. **Test on one tenant first** before applying to all tenants
5. **Document custom indexes** for future reference

## Performance Impact

These indexes optimize the following queries:
- Router status filtering
- Router-service relationship queries
- Access point filtering by router and status
- Eager loading of router relationships

The indexes significantly improve performance for the `/api/routers` endpoint and related operations.

## Example: Complete Workflow for Router Indexes

```bash
# 1. Check tenants
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "SELECT id, name, schema_name FROM tenants;"

# 2. Create indexes for specific tenant
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SET search_path TO ts_f53823aac664, public;
CREATE INDEX IF NOT EXISTS routers_status_index ON routers(status);
CREATE INDEX IF NOT EXISTS router_services_router_id_index ON router_services(router_id);
CREATE INDEX IF NOT EXISTS router_services_service_type_index ON router_services(service_type);
CREATE INDEX IF NOT EXISTS access_points_router_id_index ON access_points(router_id);
CREATE INDEX IF NOT EXISTS access_points_status_index ON access_points(status);
"

# 3. Verify indexes
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT schemaname, tablename, indexname 
FROM pg_indexes 
WHERE schemaname = 'ts_f53823aac664' 
AND tablename IN ('routers', 'router_services', 'access_points')
ORDER BY tablename, indexname;"
```

## Notes

- This documentation applies to schema-based multi-tenancy setup
- Indexes are created per-tenant schema for data isolation
- New tenants automatically get indexes via the migration system
- Always test in development environment before production deployment
