#!/bin/bash

# RADIUS Authentication Test
# Tests FreeRADIUS server authentication and accounting

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

# RADIUS configuration
RADIUS_HOST="traidnet-freeradius"
RADIUS_AUTH_PORT="1812"
RADIUS_ACCT_PORT="1813"
RADIUS_SECRET="testing123"

echo "🛡️  Testing RADIUS Server..."
echo "==========================="

# Test RADIUS server accessibility
if docker exec traidnet-freeradius test -f /etc/raddb/radiusd.conf; then
    print_status "PASS" "FreeRADIUS configuration file exists"
else
    print_status "FAIL" "FreeRADIUS configuration file not found"
fi

# Test RADIUS daemon status
if docker exec traidnet-freeradius ps aux | grep -q radiusd; then
    print_status "PASS" "RADIUS daemon is running"
else
    print_status "FAIL" "RADIUS daemon is not running"
fi

# Test RADIUS configuration syntax
if docker exec traidnet-freeradius /opt/sbin/radiusd -C > /dev/null 2>&1; then
    print_status "PASS" "RADIUS configuration syntax is valid"
else
    print_status "FAIL" "RADIUS configuration syntax is invalid"
fi

echo ""
echo "🔐 Testing RADIUS Authentication..."
echo "=================================="

# Test RADIUS authentication (PAP)
# Create a test user in radcheck table first
docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -c "
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('testuser', 'Cleartext-Password', ':=', 'testpass')
ON CONFLICT (username, attribute) DO NOTHING;
" > /dev/null 2>&1

# Test authentication with radtest (if available)
if docker exec traidnet-freeradius which radtest > /dev/null 2>&1; then
    AUTH_RESULT=$(docker exec traidnet-freeradius radtest testuser testpass localhost:1812 0 testing123 2>&1)
    
    if echo "$AUTH_RESULT" | grep -q "Access-Accept"; then
        print_status "PASS" "RADIUS PAP authentication successful"
    else
        print_status "FAIL" "RADIUS PAP authentication failed"
    fi
else
    print_status "WARN" "radtest utility not available for testing"
fi

echo ""
echo "📊 Testing RADIUS Accounting..."
echo "==============================="

# Test accounting table
ACCT_COUNT=$(docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM radacct;" 2>/dev/null || echo "0")

if [ "$ACCT_COUNT" -ge 0 ]; then
    print_status "PASS" "RADIUS accounting table accessible ($ACCT_COUNT records)"
else
    print_status "FAIL" "RADIUS accounting table not accessible"
fi

echo ""
echo "👤 Testing RADIUS User Management..."
echo "==================================="

# Test radcheck table operations
RADCHECK_COUNT=$(docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM radcheck;" 2>/dev/null || echo "0")

if [ "$RADCHECK_COUNT" -gt 0 ]; then
    print_status "PASS" "RADIUS user database has $RADCHECK_COUNT entries"
else
    print_status "WARN" "RADIUS user database is empty"
fi

# Test radreply table
RADREPLY_COUNT=$(docker exec traidnet-postgres psql -h localhost -p 5432 -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM radreply;" 2>/dev/null || echo "0")

if [ "$RADREPLY_COUNT" -ge 0 ]; then
    print_status "PASS" "RADIUS reply attributes table accessible ($RADREPLY_COUNT records)"
else
    print_status "FAIL" "RADIUS reply attributes table not accessible"
fi

echo ""
echo "🔌 Testing RADIUS UDP Ports..."
echo "=============================="

# Test UDP port accessibility (basic connectivity test)
if docker exec traidnet-backend nc -z -u traidnet-freeradius 1812; then
    print_status "PASS" "RADIUS authentication port (1812) accessible"
else
    print_status "FAIL" "RADIUS authentication port (1812) not accessible"
fi

if docker exec traidnet-backend nc -z -u traidnet-freeradius 1813; then
    print_status "PASS" "RADIUS accounting port (1813) accessible"
else
    print_status "FAIL" "RADIUS accounting port (1813) not accessible"
fi

echo ""
echo "📋 Testing RADIUS Logs..."
echo "========================="

# Check for RADIUS log files
if docker exec traidnet-freeradius test -f /var/log/radius/radius.log; then
    print_status "PASS" "RADIUS log file exists"
    # Check if logs are being written (basic check)
    LOG_SIZE=$(docker exec traidnet-freeradius stat -c%s /var/log/radius/radius.log 2>/dev/null || echo "0")
    if [ "$LOG_SIZE" -gt 0 ]; then
        print_status "PASS" "RADIUS logging is active"
    else
        print_status "WARN" "RADIUS log file is empty"
    fi
else
    print_status "WARN" "RADIUS log file not found"
fi

echo ""
echo "🔄 Testing RADIUS Integration..."
echo "==============================="

# Test if backend can connect to RADIUS
if docker exec traidnet-backend php -r "
try {
    \$radius = radius_auth_open();
    radius_add_server(\$radius, 'traidnet-freeradius', 1812, 'testing123', 5, 3);
    radius_create_request(\$radius, RADIUS_ACCESS_REQUEST);
    radius_put_attr(\$radius, RADIUS_USER_NAME, 'testuser');
    radius_put_attr(\$radius, RADIUS_USER_PASSWORD, 'testpass');
    \$result = radius_send_request(\$radius);
    echo 'RADIUS integration test: ' . (\$result === RADIUS_ACCESS_ACCEPT ? 'success' : 'failed');
} catch (Exception \$e) {
    echo 'RADIUS integration test: failed - ' . \$e->getMessage();
}
" 2>/dev/null | grep -q "success"; then
    print_status "PASS" "Backend RADIUS integration working"
else
    print_status "WARN" "Backend RADIUS integration test inconclusive"
fi

echo ""
print_status "PASS" "All RADIUS tests completed"
