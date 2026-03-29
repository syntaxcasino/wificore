#!/bin/bash

# CRITICAL FIX: Routers table not found in tenant schema
# This script diagnoses and fixes the issue

set -e

echo "==================================================================="
echo "CRITICAL FIX: Tenant Schema Routers Table Issue"
echo "==================================================================="
echo ""

TENANT_ID="51d61f4b-6ab4-4675-b6da-36efed6dfc93"

echo "Step 1: Check tenant record and schema name"
echo "-------------------------------------------------------------------"
SCHEMA_NAME=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT schema_name FROM tenants WHERE id = '$TENANT_ID';" | xargs)

if [ -z "$SCHEMA_NAME" ]; then
    echo "ERROR: Tenant not found!"
    exit 1
fi

echo "Tenant ID: $TENANT_ID"
echo "Schema Name: $SCHEMA_NAME"
echo ""

echo "Step 2: Check if schema exists"
echo "-------------------------------------------------------------------"
SCHEMA_EXISTS=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$SCHEMA_NAME';" | xargs)

if [ "$SCHEMA_EXISTS" = "0" ]; then
    echo "ERROR: Schema does not exist! Creating schema..."
    docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tenant:fix-schemas
else
    echo "✓ Schema exists"
fi
echo ""

echo "Step 3: Check if routers table exists in tenant schema"
echo "-------------------------------------------------------------------"
TABLE_EXISTS=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$SCHEMA_NAME' AND table_name = 'routers';" | xargs)

if [ "$TABLE_EXISTS" = "0" ]; then
    echo "ERROR: Routers table does not exist in schema $SCHEMA_NAME!"
    echo "Running tenant migrations..."
    docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tenant:fix-schemas
else
    echo "✓ Routers table exists"
fi
echo ""

echo "Step 4: Check routers table structure"
echo "-------------------------------------------------------------------"
docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -c "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = '$SCHEMA_NAME' AND table_name = 'routers' ORDER BY ordinal_position;"
echo ""

echo "Step 5: Check if last_checked column exists"
echo "-------------------------------------------------------------------"
COLUMN_EXISTS=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$SCHEMA_NAME' AND table_name = 'routers' AND column_name = 'last_checked';" | xargs)

if [ "$COLUMN_EXISTS" = "0" ]; then
    echo "ERROR: last_checked column missing! Adding column..."
    docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tenant:migrate-routers-column
else
    echo "✓ last_checked column exists"
fi
echo ""

echo "Step 6: Clear failed jobs and restart queue worker"
echo "-------------------------------------------------------------------"
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan queue:flush
docker compose -f docker-compose.production.yml restart wificore-queue-worker
echo "✓ Queue cleared and worker restarted"
echo ""

echo "Step 7: Verify fix by checking current search_path"
echo "-------------------------------------------------------------------"
docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -c "SHOW search_path;"
echo ""

echo "==================================================================="
echo "FIX COMPLETE"
echo "==================================================================="
echo ""
echo "Monitor logs with:"
echo "docker compose -f docker-compose.production.yml logs -f wificore-backend wificore-queue-worker | grep -E 'CheckRouters|routers|SQLSTATE'"
echo ""
