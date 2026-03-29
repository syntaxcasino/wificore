#!/bin/bash

echo "=== Tenant Routers Table Diagnostic & Fix ==="
echo ""

# Get tenant ID from logs
TENANT_ID="51d61f4b-6ab4-4675-b6da-36efed6dfc93"

echo "1. Checking tenant record..."
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore -c "SELECT id, name, schema_name FROM tenants WHERE id = '$TENANT_ID';"

echo ""
echo "2. Getting schema name..."
SCHEMA_NAME=$(docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore -t -c "SELECT schema_name FROM tenants WHERE id = '$TENANT_ID';" | xargs)

echo "Schema name: $SCHEMA_NAME"

echo ""
echo "3. Checking if schema exists..."
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore -c "SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$SCHEMA_NAME';"

echo ""
echo "4. Checking if routers table exists in schema..."
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore -c "SELECT table_name FROM information_schema.tables WHERE table_schema = '$SCHEMA_NAME' AND table_name = 'routers';"

echo ""
echo "5. If table doesn't exist, running tenant migrations..."
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tenant:fix-schemas

echo ""
echo "6. Adding last_checked column to all tenant routers tables..."
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tenant:migrate-routers-column

echo ""
echo "7. Verifying routers table structure..."
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore -c "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = '$SCHEMA_NAME' AND table_name = 'routers' ORDER BY ordinal_position;"

echo ""
echo "8. Clearing failed jobs..."
docker compose -f docker-compose.production.yml exec wificore-backend php artisan queue:flush

echo ""
echo "9. Restarting queue worker..."
docker compose -f docker-compose.production.yml restart wificore-queue-worker

echo ""
echo "=== Fix Complete ==="
echo "Monitor logs: docker compose -f docker-compose.production.yml logs -f wificore-backend wificore-queue-worker | grep -E 'CheckRouters|routers|SQLSTATE'"
