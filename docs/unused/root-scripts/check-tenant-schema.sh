#!/bin/bash

# Script to check tenant schema and tables
# Run on production server

TENANT_ID="3d5a0868-7b7b-4ee6-bc64-4703fd04c393"

echo "=== Checking Tenant Schema ==="
echo ""

# Get tenant info
echo "1. Getting tenant information..."
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tinker <<EOF
\$tenant = \App\Models\Tenant::find('${TENANT_ID}');
if (\$tenant) {
    echo "Tenant: " . \$tenant->name . "\n";
    echo "Schema: " . \$tenant->schema_name . "\n";
    echo "Schema Created: " . (\$tenant->schema_created ? 'Yes' : 'No') . "\n";
    echo "Active: " . (\$tenant->is_active ? 'Yes' : 'No') . "\n";
} else {
    echo "Tenant not found!\n";
}
exit
EOF

echo ""
echo "2. Checking if schema exists in database..."
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tinker <<EOF
\$schemas = DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'tenant_%'");
foreach (\$schemas as \$schema) {
    echo "Found schema: " . \$schema->schema_name . "\n";
}
exit
EOF

echo ""
echo "3. Checking for routers table in tenant schema..."
docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tinker <<EOF
\$tenant = \App\Models\Tenant::find('${TENANT_ID}');
if (\$tenant && \$tenant->schema_name) {
    \$tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name", [\$tenant->schema_name]);
    if (count(\$tables) > 0) {
        echo "Tables in " . \$tenant->schema_name . ":\n";
        foreach (\$tables as \$table) {
            echo "  - " . \$table->table_name . "\n";
        }
    } else {
        echo "No tables found in schema: " . \$tenant->schema_name . "\n";
        echo "Schema exists but migrations not run!\n";
    }
}
exit
EOF

echo ""
echo "=== Check Complete ==="
