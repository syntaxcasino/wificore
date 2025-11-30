#!/bin/bash

# Backend API Endpoints Test
# Tests Laravel API endpoints, authentication, and core functionality

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

# Backend API base URL
API_BASE="http://traidnet-nginx/api"

echo "🚀 Testing Backend API Endpoints..."
echo "==================================="

# Test health endpoint
if curl -s -f "$API_BASE/health" > /dev/null 2>&1; then
    print_status "PASS" "Health endpoint accessible"
else
    print_status "FAIL" "Health endpoint not accessible"
    exit 1
fi

# Test ping endpoint
if curl -s -f "$API_BASE/health/ping" > /dev/null 2>&1; then
    print_status "PASS" "Ping endpoint accessible"
else
    print_status "FAIL" "Ping endpoint not accessible"
    exit 1
fi

# Test database health
if curl -s -f "$API_BASE/health/database" > /dev/null 2>&1; then
    print_status "PASS" "Database health check passed"
else
    print_status "FAIL" "Database health check failed"
fi

echo ""
echo "🔐 Testing Authentication Endpoints..."
echo "====================================="

# Test login endpoint (should return 422 for invalid data)
LOGIN_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_BASE/login" \
    -H "Content-Type: application/json" \
    -d '{"email":"invalid","password":"invalid"}')

if [ "$LOGIN_RESPONSE" = "422" ]; then
    print_status "PASS" "Login endpoint returns validation error for invalid data"
else
    print_status "FAIL" "Login endpoint unexpected response: $LOGIN_RESPONSE"
fi

echo ""
echo "👥 Testing User Endpoints..."
echo "==========================="

# Test users endpoint (requires authentication, should return 401)
USERS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/users")

if [ "$USERS_RESPONSE" = "401" ]; then
    print_status "PASS" "Users endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "Users endpoint response: $USERS_RESPONSE (expected 401)"
fi

echo ""
echo "📦 Testing Package Endpoints..."
echo "=============================="

# Test packages endpoint
PACKAGES_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/packages")

if [ "$PACKAGES_RESPONSE" = "401" ]; then
    print_status "PASS" "Packages endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "Packages endpoint response: $PACKAGES_RESPONSE"
fi

echo ""
echo "🏪 Testing Hotspot Endpoints..."
echo "==============================="

# Test hotspot users endpoint
HOTSPOT_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/hotspot/users")

if [ "$HOTSPOT_RESPONSE" = "401" ]; then
    print_status "PASS" "Hotspot users endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "Hotspot users endpoint response: $HOTSPOT_RESPONSE"
fi

echo ""
echo "🔌 Testing Router Endpoints..."
echo "============================="

# Test routers endpoint
ROUTERS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/routers")

if [ "$ROUTERS_RESPONSE" = "401" ]; then
    print_status "PASS" "Routers endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "Routers endpoint response: $ROUTERS_RESPONSE"
fi

echo ""
echo "💳 Testing Payment Endpoints..."
echo "=============================="

# Test payments endpoint
PAYMENTS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/payments")

if [ "$PAYMENTS_RESPONSE" = "401" ]; then
    print_status "PASS" "Payments endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "Payments endpoint response: $PAYMENTS_RESPONSE"
fi

echo ""
echo "📊 Testing Monitoring Endpoints..."
echo "================================="

# Test queue stats endpoint
QUEUE_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/queue/stats")

if [ "$QUEUE_RESPONSE" = "401" ]; then
    print_status "PASS" "Queue stats endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "Queue stats endpoint response: $QUEUE_RESPONSE"
fi

# Test system logs endpoint
LOGS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/logs/system")

if [ "$LOGS_RESPONSE" = "401" ]; then
    print_status "PASS" "System logs endpoint properly protected (401 unauthorized)"
else
    print_status "WARN" "System logs endpoint response: $LOGS_RESPONSE"
fi

echo ""
echo "🎯 Testing Laravel Artisan Commands..."
echo "===================================="

# Test Laravel commands via docker exec
if docker exec traidnet-backend php artisan --version > /dev/null 2>&1; then
    print_status "PASS" "Laravel artisan accessible"
else
    print_status "FAIL" "Laravel artisan not accessible"
    exit 1
fi

# Test route list
if docker exec traidnet-backend php artisan route:list --compact > /dev/null 2>&1; then
    ROUTE_COUNT=$(docker exec traidnet-backend php artisan route:list --compact | wc -l)
    print_status "PASS" "Route list accessible ($ROUTE_COUNT routes)"
else
    print_status "WARN" "Route list not accessible"
fi

# Test migration status
if docker exec traidnet-backend php artisan migrate:status > /dev/null 2>&1; then
    print_status "PASS" "Migration status check successful"
else
    print_status "FAIL" "Migration status check failed"
fi

echo ""
echo "⚡ Testing Queue System..."
echo "========================="

# Check if queue worker is running (basic check)
if docker exec traidnet-backend ps aux | grep -q "queue:work"; then
    print_status "PASS" "Queue worker process detected"
else
    print_status "WARN" "Queue worker process not detected"
fi

# Check Redis connectivity from backend
if docker exec traidnet-backend php artisan tinker --execute="echo Redis::ping()" > /dev/null 2>&1; then
    print_status "PASS" "Redis connectivity from backend successful"
else
    print_status "FAIL" "Redis connectivity from backend failed"
fi

echo ""
print_status "PASS" "All backend API tests completed"
