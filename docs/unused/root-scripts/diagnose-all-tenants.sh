#!/bin/bash

echo "=== Checking ALL Tenants for Routers Table ==="
echo ""

# Get all tenants
docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT id, name, schema_name FROM tenants;" | while IFS='|' read -r tenant_id name schema_name; do
    tenant_id=$(echo $tenant_id | xargs)
    name=$(echo $name | xargs)
    schema_name=$(echo $schema_name | xargs)
    
    if [ -z "$tenant_id" ]; then
        continue
    fi
    
    echo "==================================================================="
    echo "Tenant: $name"
    echo "ID: $tenant_id"
    echo "Schema: $schema_name"
    echo "-------------------------------------------------------------------"
    
    # Check if schema exists
    schema_exists=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$schema_name';" | xargs)
    
    if [ "$schema_exists" = "0" ]; then
        echo "❌ Schema does NOT exist!"
    else
        echo "✅ Schema exists"
        
        # Check if routers table exists
        table_exists=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$schema_name' AND table_name = 'routers';" | xargs)
        
        if [ "$table_exists" = "0" ]; then
            echo "❌ Routers table does NOT exist in schema!"
        else
            echo "✅ Routers table exists"
            
            # Check if last_checked column exists
            column_exists=$(docker compose -f docker-compose.production.yml exec -T wificore-postgres psql -U wificore -d wificore -t -c "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$schema_name' AND table_name = 'routers' AND column_name = 'last_checked';" | xargs)
            
            if [ "$column_exists" = "0" ]; then
                echo "❌ last_checked column does NOT exist!"
            else
                echo "✅ last_checked column exists"
            fi
        fi
    fi
    echo ""
done

echo "==================================================================="
echo "Running tenant:fix-schemas to create missing schemas/tables..."
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tenant:fix-schemas

echo ""
echo "Running tenant:migrate-routers-column to add missing column..."
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tenant:migrate-routers-column

echo ""
echo "Clearing failed jobs..."
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan queue:flush

echo ""
echo "Restarting queue worker..."
docker compose -f docker-compose.production.yml restart wificore-queue-worker

echo ""
echo "=== DONE ==="
