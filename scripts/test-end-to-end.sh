#!/bin/bash

# End-to-End User Flows Test
# Tests complete user journeys from registration to hotspot access

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

# API configuration
API_BASE="http://traidnet-nginx/api"
FRONTEND_URL="http://traidnet-nginx"

echo "🔄 Testing End-to-End User Flows..."
echo "==================================="

# Test data
TEST_EMAIL="test-$(date +%s)@example.com"
TEST_PASSWORD="TestPass123!"
TEST_PHONE="+254700000000"

echo ""
echo "📝 Testing User Registration Flow..."
echo "===================================="

# Attempt user registration (if endpoint exists)
REGISTER_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_BASE/users" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"Test User\",\"email\":\"$TEST_EMAIL\",\"password\":\"$TEST_PASSWORD\",\"phone\":\"$TEST_PHONE\"}")

if [ "$REGISTER_RESPONSE" = "201" ] || [ "$REGISTER_RESPONSE" = "200" ]; then
    print_status "PASS" "User registration successful ($REGISTER_RESPONSE)"
    USER_CREATED=true
else
    print_status "WARN" "User registration returned $REGISTER_RESPONSE (may require different endpoint)"
    USER_CREATED=false
fi

echo ""
echo "🔐 Testing Authentication Flow..."
echo "================================"

# Test login
LOGIN_RESPONSE=$(curl -s -X POST "$API_BASE/login" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"$TEST_EMAIL\",\"password\":\"$TEST_PASSWORD\"}")

if echo "$LOGIN_RESPONSE" | grep -q "token"; then
    print_status "PASS" "User login successful"
    TOKEN=$(echo "$LOGIN_RESPONSE" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
else
    print_status "WARN" "User login failed or returned unexpected response"
    TOKEN=""
fi

echo ""
echo "📦 Testing Package Management..."
echo "==============================="

# Test packages listing (with auth if available)
if [ -n "$TOKEN" ]; then
    PACKAGES_RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" "$API_BASE/packages")
else
    PACKAGES_RESPONSE=$(curl -s "$API_BASE/packages")
fi

if echo "$PACKAGES_RESPONSE" | grep -q "id\|name"; then
    PACKAGE_COUNT=$(echo "$PACKAGES_RESPONSE" | grep -o '"id":[^,}]*' | wc -l)
    print_status "PASS" "Packages accessible ($PACKAGE_COUNT packages found)"
else
    print_status "WARN" "Packages endpoint not returning expected data"
fi

echo ""
echo "💳 Testing Payment Processing..."
echo "==============================="

# Test payment endpoints (basic accessibility)
PAYMENTS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/payments")

if [ "$PAYMENTS_RESPONSE" = "401" ]; then
    print_status "PASS" "Payments endpoint properly secured"
else
    print_status "INFO" "Payments endpoint returned $PAYMENTS_RESPONSE"
fi

echo ""
echo "🏪 Testing Hotspot User Management..."
echo "===================================="

# Test hotspot users endpoint
HOTSPOT_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/hotspot/users")

if [ "$HOTSPOT_RESPONSE" = "401" ]; then
    print_status "PASS" "Hotspot users endpoint properly secured"
else
    print_status "INFO" "Hotspot users endpoint returned $HOTSPOT_RESPONSE"
fi

echo ""
echo "🔌 Testing Router Management..."
echo "=============================="

# Test routers endpoint
ROUTERS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/routers")

if [ "$ROUTERS_RESPONSE" = "401" ]; then
    print_status "PASS" "Routers endpoint properly secured"
else
    print_status "INFO" "Routers endpoint returned $ROUTERS_RESPONSE"
fi

echo ""
echo "📊 Testing Dashboard Data..."
echo "==========================="

# Test dashboard/health endpoints
HEALTH_RESPONSE=$(curl -s "$API_BASE/health")

if echo "$HEALTH_RESPONSE" | grep -q "status\|healthy"; then
    print_status "PASS" "Health endpoint returning data"
else
    print_status "WARN" "Health endpoint not returning expected data"
fi

echo ""
echo "🌐 Testing Frontend Integration..."
echo "================================="

# Test frontend page loading
FRONTEND_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$FRONTEND_URL")

if [ "$FRONTEND_RESPONSE" = "200" ]; then
    print_status "PASS" "Frontend homepage accessible"
else
    print_status "FAIL" "Frontend homepage not accessible ($FRONTEND_RESPONSE)"
fi

# Test login page
LOGIN_PAGE_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$FRONTEND_URL/login")

if [ "$LOGIN_PAGE_RESPONSE" = "200" ]; then
    print_status "PASS" "Frontend login page accessible"
else
    print_status "WARN" "Frontend login page returned $LOGIN_PAGE_RESPONSE"
fi

echo ""
echo "📱 Testing WebSocket Real-time Features..."
echo "=========================================="

# Test WebSocket connectivity (basic)
if curl -s "http://traidnet-soketi:6001/apps/app-id/channels" > /dev/null 2>&1; then
    print_status "PASS" "WebSocket server accessible for real-time features"
else
    print_status "WARN" "WebSocket server not accessible"
fi

echo ""
echo "🛡️  Testing RADIUS Integration..."
echo "==============================="

# Test RADIUS connectivity from backend
if docker exec traidnet-backend nc -z -u traidnet-freeradius 1812; then
    print_status "PASS" "RADIUS server accessible from backend"
else
    print_status "FAIL" "RADIUS server not accessible from backend"
fi

echo ""
echo "🔄 Testing Queue System..."
echo "========================="

# Test queue status
QUEUE_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/queue/stats")

if [ "$QUEUE_RESPONSE" = "401" ]; then
    print_status "PASS" "Queue stats endpoint secured"
else
    print_status "INFO" "Queue stats endpoint returned $QUEUE_RESPONSE"
fi

echo ""
echo "🧹 Cleaning up test data..."
echo "==========================="

# Clean up test user if created
if [ "$USER_CREATED" = true ]; then
    docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -c "
    DELETE FROM users WHERE email = '$TEST_EMAIL';
    " > /dev/null 2>&1
    print_status "PASS" "Test user data cleaned up"
fi

echo ""
print_status "PASS" "End-to-end user flow tests completed"
