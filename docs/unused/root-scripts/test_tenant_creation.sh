#!/bin/bash

echo "=== END-TO-END TENANT CREATION TEST ==="
echo ""

# Step 1: Login as system admin
echo "Step 1: Login as system admin..."
LOGIN_RESPONSE=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"sysadmin","password":"Admin@123!"}')

TOKEN=$(echo $LOGIN_RESPONSE | grep -o '"token":"[^"]*' | cut -d'"' -f4)

if [ -z "$TOKEN" ]; then
    echo "❌ Login failed!"
    echo "Response: $LOGIN_RESPONSE"
    exit 1
fi

echo "✅ Login successful! Token: ${TOKEN:0:20}..."
echo ""

# Step 2: Create new tenant
echo "Step 2: Creating new tenant..."
TENANT_RESPONSE=$(curl -s -X POST http://localhost/api/system/tenants \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "name": "Test Tenant E2E",
    "slug": "test-tenant-e2e",
    "subdomain": "teste2e",
    "email": "admin@teste2e.com",
    "phone": "+254700000000",
    "address": "Test Address"
  }')

echo "Response: $TENANT_RESPONSE"
echo ""

# Extract tenant ID
TENANT_ID=$(echo $TENANT_RESPONSE | grep -o '"id":"[^"]*' | cut -d'"' -f4)

if [ -z "$TENANT_ID" ]; then
    echo "❌ Tenant creation failed!"
    exit 1
fi

echo "✅ Tenant created! ID: $TENANT_ID"
echo ""

# Step 3: Wait for schema creation
echo "Step 3: Waiting for schema creation (5 seconds)..."
sleep 5
echo ""

# Step 4: Check if schema was created
echo "Step 4: Checking if tenant schema was created..."
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
SELECT 
    t.name,
    t.slug,
    t.schema_name,
    t.schema_created,
    (SELECT COUNT(*) FROM pg_tables WHERE schemaname = t.schema_name) as table_count
FROM tenants t
WHERE t.id = '$TENANT_ID';
"
echo ""

# Step 5: Check if RADIUS tables exist
echo "Step 5: Checking if RADIUS tables exist in tenant schema..."
SCHEMA_NAME=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT schema_name FROM tenants WHERE id = '$TENANT_ID';")
SCHEMA_NAME=$(echo $SCHEMA_NAME | xargs) # trim whitespace

if [ ! -z "$SCHEMA_NAME" ]; then
    echo "Schema name: $SCHEMA_NAME"
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT tablename 
    FROM pg_tables 
    WHERE schemaname = '$SCHEMA_NAME' 
    AND tablename LIKE 'rad%'
    ORDER BY tablename;
    "
else
    echo "❌ Schema name not found!"
fi
echo ""

# Step 6: Create tenant admin user
echo "Step 6: Creating tenant admin user..."
USER_RESPONSE=$(curl -s -X POST http://localhost/api/system/tenants/$TENANT_ID/users \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "name": "Test Admin",
    "username": "testadmin",
    "email": "testadmin@teste2e.com",
    "password": "Test@123!",
    "role": "admin"
  }')

echo "Response: $USER_RESPONSE"
echo ""

USER_ID=$(echo $USER_RESPONSE | grep -o '"id":"[^"]*' | cut -d'"' -f4)

if [ -z "$USER_ID" ]; then
    echo "❌ User creation failed!"
else
    echo "✅ User created! ID: $USER_ID"
fi
echo ""

# Step 7: Check RADIUS credentials
echo "Step 7: Checking if RADIUS credentials were created..."
if [ ! -z "$SCHEMA_NAME" ]; then
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SET search_path TO $SCHEMA_NAME, public;
    SELECT username, attribute, value FROM radcheck WHERE username = 'testadmin';
    "
fi
echo ""

# Step 8: Test tenant admin login
echo "Step 8: Testing tenant admin login..."
sleep 2
TENANT_LOGIN_RESPONSE=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"testadmin","password":"Test@123!"}')

echo "Response: $TENANT_LOGIN_RESPONSE"
echo ""

TENANT_TOKEN=$(echo $TENANT_LOGIN_RESPONSE | grep -o '"token":"[^"]*' | cut -d'"' -f4)

if [ -z "$TENANT_TOKEN" ]; then
    echo "❌ Tenant admin login failed!"
else
    echo "✅ Tenant admin login successful!"
fi
echo ""

echo "=== END-TO-END TEST COMPLETE ==="
