#!/bin/bash

# WebSocket Connectivity Test
# Tests Soketi WebSocket server connectivity and broadcasting

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

# Soketi configuration
SOKETI_HOST="traidnet-soketi"
SOKETI_PORT="6001"
SOKETI_METRICS_PORT="9601"

echo "🔌 Testing WebSocket Connectivity..."
echo "==================================="

# Test Soketi HTTP API
if curl -s -f "http://$SOKETI_HOST:$SOKETI_PORT" > /dev/null 2>&1; then
    print_status "PASS" "Soketi HTTP API accessible"
else
    print_status "FAIL" "Soketi HTTP API not accessible"
    exit 1
fi

# Test Soketi metrics endpoint
if curl -s -f "http://$SOKETI_HOST:$SOKETI_METRICS_PORT" > /dev/null 2>&1; then
    print_status "PASS" "Soketi metrics endpoint accessible"
else
    print_status "FAIL" "Soketi metrics endpoint not accessible"
fi

echo ""
echo "📡 Testing WebSocket Broadcasting..."
echo "==================================="

# Test broadcasting authentication endpoint (should be accessible via backend)
if curl -s -f -H "Host: traidnet-nginx" "http://traidnet-nginx/api/broadcasting/auth" > /dev/null 2>&1; then
    print_status "PASS" "Broadcasting auth endpoint accessible"
else
    print_status "WARN" "Broadcasting auth endpoint not accessible"
fi

echo ""
echo "🔧 Testing Soketi Configuration..."
echo "================================="

# Test app information via Soketi API
APP_INFO=$(curl -s "http://$SOKETI_HOST:$SOKETI_PORT/apps/app-id" 2>/dev/null || echo "")

if [ -n "$APP_INFO" ]; then
    print_status "PASS" "Soketi app configuration accessible"
else
    print_status "WARN" "Soketi app configuration not accessible"
fi

# Test channels endpoint
CHANNELS_INFO=$(curl -s "http://$SOKETI_HOST:$SOKETI_PORT/apps/app-id/channels" 2>/dev/null || echo "")

if [ -n "$CHANNELS_INFO" ]; then
    print_status "PASS" "Soketi channels endpoint accessible"
else
    print_status "WARN" "Soketi channels endpoint not accessible"
fi

echo ""
echo "🌐 Testing WebSocket Client Connection..."
echo "========================================"

# Create a simple WebSocket test using curl (if available) or node
if command -v node > /dev/null 2>&1; then
    # Create a temporary test script
    cat > /tmp/ws_test.js << 'EOF'
const WebSocket = require('ws');

const ws = new WebSocket('ws://traidnet-soketi:6001/app/app-key');

ws.on('open', function open() {
  console.log('WebSocket connected');
  ws.send(JSON.stringify({
    event: 'pusher:subscribe',
    data: { channel: 'test-channel' }
  }));
  
  setTimeout(() => {
    ws.close();
    process.exit(0);
  }, 2000);
});

ws.on('error', function error(err) {
  console.error('WebSocket error:', err.message);
  process.exit(1);
});

ws.on('close', function close() {
  console.log('WebSocket closed');
});
EOF

    if timeout 10s node /tmp/ws_test.js > /tmp/ws_output.log 2>&1; then
        if grep -q "WebSocket connected" /tmp/ws_output.log; then
            print_status "PASS" "WebSocket client connection successful"
        else
            print_status "FAIL" "WebSocket client connection failed"
        fi
    else
        print_status "FAIL" "WebSocket test timed out"
    fi

    rm -f /tmp/ws_test.js /tmp/ws_output.log
else
    print_status "WARN" "Node.js not available for WebSocket client testing"
fi

echo ""
echo "📊 Testing WebSocket Metrics..."
echo "==============================="

# Test metrics data
METRICS=$(curl -s "http://$SOKETI_HOST:$SOKETI_METRICS_PORT" 2>/dev/null || echo "")

if echo "$METRICS" | grep -q "uptime"; then
    print_status "PASS" "Soketi metrics reporting uptime"
else
    print_status "WARN" "Soketi metrics not reporting uptime"
fi

if echo "$METRICS" | grep -q "memory"; then
    print_status "PASS" "Soketi metrics reporting memory usage"
else
    print_status "WARN" "Soketi metrics not reporting memory usage"
fi

echo ""
echo "🔄 Testing Broadcasting Integration..."
echo "====================================="

# Test if Laravel can connect to Soketi (via environment check)
if docker exec traidnet-backend grep -q "SOKETI" /var/www/html/.env 2>/dev/null; then
    print_status "PASS" "Laravel broadcasting configuration detected"
else
    print_status "WARN" "Laravel broadcasting configuration not found"
fi

# Test Redis connectivity for broadcasting
if docker exec traidnet-backend php -r "
try {
    \$redis = new Redis();
    \$redis->connect('traidnet-redis', 6379);
    echo 'Redis connected';
} catch (Exception \$e) {
    echo 'Redis connection failed';
    exit(1);
}
" 2>/dev/null | grep -q "Redis connected"; then
    print_status "PASS" "Redis connectivity for broadcasting successful"
else
    print_status "FAIL" "Redis connectivity for broadcasting failed"
fi

echo ""
print_status "PASS" "All WebSocket tests completed"
