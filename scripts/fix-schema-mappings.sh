#!/bin/bash

# Fix Schema Mappings Script
# This script fixes missing schema mappings for tenant users

echo "========================================="
echo "Schema Mapping Fix Script"
echo "========================================="
echo ""

# Check if docker is running
if ! docker ps > /dev/null 2>&1; then
    echo "Error: Docker is not running or you don't have permission to access it."
    exit 1
fi

# Check if backend container is running
if ! docker ps | grep -q wificore-backend; then
    echo "Error: wificore-backend container is not running."
    echo "Please start the containers first:"
    echo "  cd backend && docker-compose up -d"
    exit 1
fi

echo "Checking current schema mappings..."
echo ""

# Show current count
CURRENT_COUNT=$(docker exec wificore-backend php artisan tinker --execute="echo DB::table('radius_user_schema_mapping')->count();")
echo "Current schema mappings: $CURRENT_COUNT"
echo ""

# Show users without mappings
echo "Checking for users without schema mappings..."
docker exec wificore-backend php artisan tinker --execute="
\$users = DB::select('
    SELECT 
        u.id,
        u.username,
        u.tenant_id,
        t.schema_name,
        CASE 
            WHEN m.id IS NULL THEN \'MISSING\'
            ELSE \'EXISTS\'
        END as mapping_status
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    LEFT JOIN radius_user_schema_mapping m ON u.username = m.username
    WHERE u.tenant_id IS NOT NULL
    ORDER BY mapping_status DESC
');
foreach (\$users as \$user) {
    if (\$user->mapping_status === 'MISSING') {
        echo \"❌ MISSING: {$user->username} (Tenant ID: {$user->tenant_id})\n\";
    }
}
"
echo ""

# Ask for confirmation
read -p "Do you want to fix missing schema mappings? (y/n) " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo ""
echo "Running fix command..."
echo ""

# Run the fix command
docker exec wificore-backend php artisan tenants:fix-schema-mappings

echo ""
echo "========================================="
echo "Fix completed!"
echo "========================================="
echo ""

# Show new count
NEW_COUNT=$(docker exec wificore-backend php artisan tinker --execute="echo DB::table('radius_user_schema_mapping')->count();")
echo "Schema mappings after fix: $NEW_COUNT"
echo "Mappings created: $((NEW_COUNT - CURRENT_COUNT))"
echo ""

echo "You can now test login at: https://wificore.traidsolutions.com/login"
echo ""
