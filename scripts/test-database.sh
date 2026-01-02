#!/bin/bash

# Database Connectivity & Schema Test
# Tests PostgreSQL connection, authentication, and schema validation

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "PASS")
            echo -e "${GREEN}✅ PASS${NC}: $message"
            ;;
        "FAIL")
            echo -e "${RED}❌ FAIL${NC}: $message"
            ;;
        "WARN")
            echo -e "${YELLOW}⚠️  WARN${NC}: $message"
            ;;
        "INFO")
            echo -e "${BLUE}ℹ️  INFO${NC}: $message"
            ;;
    esac
}

# Database connection parameters
DB_HOST="traidnet-postgres"
DB_PORT="5432"
DB_NAME="wifi_hotspot"
DB_USER="admin"
DB_PASS="secret"

echo "🗄️  Testing Database Connectivity..."
echo "==================================="

# Test basic connection
if docker exec traidnet-postgres pg_isready -h localhost -p 5432 -U admin -d wifi_hotspot > /dev/null 2>&1; then
    print_status "PASS" "PostgreSQL connection successful"
else
    print_status "FAIL" "Cannot connect to PostgreSQL"
    exit 1
fi

echo ""
echo "🔐 Testing Database Authentication..."
echo "===================================="

# Test authentication with psql
if docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -c "SELECT 1;" > /dev/null 2>&1; then
    print_status "PASS" "Database authentication successful"
else
    print_status "FAIL" "Database authentication failed"
    exit 1
fi

echo ""
echo "📋 Testing Laravel Migrations..."
echo "==============================="

# Check if migrations table exists and has records
MIGRATION_COUNT=$(docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM migrations;" 2>/dev/null || echo "0")

if [ "$MIGRATION_COUNT" -gt 0 ]; then
    print_status "PASS" "Laravel migrations table exists with $MIGRATION_COUNT migrations"
else
    print_status "FAIL" "Laravel migrations not found or table empty"
    exit 1
fi

echo ""
echo "🛡️  Testing RADIUS Schema..."
echo "============================"

# Check RADIUS tables exist
RADIUS_TABLES=("radcheck" "radreply" "radacct")

for table in "${RADIUS_TABLES[@]}"; do
    if docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -c "SELECT 1 FROM $table LIMIT 1;" > /dev/null 2>&1; then
        print_status "PASS" "RADIUS table '$table' exists"
    else
        print_status "FAIL" "RADIUS table '$table' not found"
        exit 1
    fi
done

echo ""
echo "📊 Testing Core Application Tables..."
echo "====================================="

# Check core application tables
CORE_TABLES=("users" "hotspot_users" "packages" "payments" "routers" "user_subscriptions")

for table in "${CORE_TABLES[@]}"; do
    if docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -c "SELECT 1 FROM $table LIMIT 1;" > /dev/null 2>&1; then
        print_status "PASS" "Core table '$table' exists"
    else
        print_status "WARN" "Core table '$table' not found or empty"
    fi
done

echo ""
echo "🔄 Testing Database Operations..."
echo "================================="

# Test basic CRUD operations
if docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -c "
    -- Test INSERT
    INSERT INTO users (name, email, password, created_at, updated_at)
    VALUES ('test_user', 'test@example.com', 'hashed_password', NOW(), NOW());

    -- Test SELECT
    SELECT id FROM users WHERE email = 'test@example.com';

    -- Test UPDATE
    UPDATE users SET name = 'updated_test_user' WHERE email = 'test@example.com';

    -- Test DELETE
    DELETE FROM users WHERE email = 'test@example.com';
" > /dev/null 2>&1; then
    print_status "PASS" "Basic CRUD operations successful"
else
    print_status "FAIL" "CRUD operations failed"
    exit 1
fi

echo ""
echo "📈 Testing Database Performance..."
echo "=================================="

# Test query performance (basic)
QUERY_TIME=$(docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -t -c "
    \timing on
    SELECT COUNT(*) FROM users;
    \timing off
" 2>&1 | grep "Time:" | sed 's/Time: //' | sed 's/ ms//' | head -1)

if [ -n "$QUERY_TIME" ] && [ "$QUERY_TIME" -lt 100 ]; then
    print_status "PASS" "Database query performance acceptable (${QUERY_TIME}ms)"
else
    print_status "WARN" "Database query performance slow (${QUERY_TIME}ms)"
fi

echo ""
print_status "PASS" "All database tests passed"
