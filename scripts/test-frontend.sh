#!/bin/bash

# Frontend Build & Serving Test
# Tests Vue.js frontend build process and static file serving

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

# Frontend URL
FRONTEND_URL="http://traidnet-nginx"

echo "🎨 Testing Frontend Build & Serving..."
echo "====================================="

# Test if frontend container is accessible
if curl -s -f "$FRONTEND_URL" > /dev/null 2>&1; then
    print_status "PASS" "Frontend is accessible at $FRONTEND_URL"
else
    print_status "FAIL" "Frontend not accessible at $FRONTEND_URL"
    exit 1
fi

# Test HTML content
HTML_CONTENT=$(curl -s "$FRONTEND_URL")

if echo "$HTML_CONTENT" | grep -q "<!DOCTYPE html>"; then
    print_status "PASS" "Frontend serving valid HTML"
else
    print_status "FAIL" "Frontend not serving valid HTML"
fi

# Test Vue.js app detection
if echo "$HTML_CONTENT" | grep -q "vue"; then
    print_status "PASS" "Vue.js application detected in HTML"
else
    print_status "WARN" "Vue.js application not clearly detected in HTML"
fi

echo ""
echo "📦 Testing Static Assets..."
echo "=========================="

# Test CSS files
CSS_URLS=$(echo "$HTML_CONTENT" | grep -o 'href="[^"]*\.css"' | sed 's/href="//' | sed 's/"//')

for css_url in $CSS_URLS; do
    if curl -s -f "$FRONTEND_URL$css_url" > /dev/null 2>&1; then
        print_status "PASS" "CSS file accessible: $css_url"
    else
        print_status "FAIL" "CSS file not accessible: $css_url"
    fi
done

# Test JS files
JS_URLS=$(echo "$HTML_CONTENT" | grep -o 'src="[^"]*\.js"' | sed 's/src="//' | sed 's/"//')

for js_url in $JS_URLS; do
    if curl -s -f "$FRONTEND_URL$js_url" > /dev/null 2>&1; then
        print_status "PASS" "JS file accessible: $js_url"
    else
        print_status "FAIL" "JS file not accessible: $js_url"
    fi
done

echo ""
echo "🔧 Testing Build Process..."
echo "=========================="

# Test if build files exist in container
if docker exec traidnet-frontend test -d /app/dist; then
    print_status "PASS" "Frontend build directory exists"
else
    print_status "FAIL" "Frontend build directory not found"
fi

# Check if package.json exists
if docker exec traidnet-frontend test -f /app/package.json; then
    print_status "PASS" "package.json exists"
else
    print_status "FAIL" "package.json not found"
fi

# Test npm/node accessibility
if docker exec traidnet-frontend node --version > /dev/null 2>&1; then
    NODE_VERSION=$(docker exec traidnet-frontend node --version)
    print_status "PASS" "Node.js accessible ($NODE_VERSION)"
else
    print_status "FAIL" "Node.js not accessible"
fi

if docker exec traidnet-frontend npm --version > /dev/null 2>&1; then
    NPM_VERSION=$(docker exec traidnet-frontend npm --version)
    print_status "PASS" "NPM accessible ($NPM_VERSION)"
else
    print_status "FAIL" "NPM not accessible"
fi

echo ""
echo "🌐 Testing Frontend Routes..."
echo "============================="

# Test common routes (these should return HTML, not 404)
COMMON_ROUTES=("/" "/login" "/dashboard")

for route in "${COMMON_ROUTES[@]}"; do
    RESPONSE_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$FRONTEND_URL$route")
    if [ "$RESPONSE_CODE" = "200" ]; then
        print_status "PASS" "Route $route accessible (200)"
    else
        print_status "WARN" "Route $route returned $RESPONSE_CODE"
    fi
done

echo ""
echo "🔒 Testing Security Headers..."
echo "============================="

# Test basic security headers
HEADERS=$(curl -s -I "$FRONTEND_URL")

if echo "$HEADERS" | grep -q "X-Content-Type-Options"; then
    print_status "PASS" "X-Content-Type-Options header present"
else
    print_status "WARN" "X-Content-Type-Options header missing"
fi

if echo "$HEADERS" | grep -q "X-Frame-Options"; then
    print_status "PASS" "X-Frame-Options header present"
else
    print_status "WARN" "X-Frame-Options header missing"
fi

echo ""
echo "📊 Testing Performance..."
echo "========================="

# Test response time
RESPONSE_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$FRONTEND_URL" | awk '{printf "%.2f", $1 * 1000}')

if (( $(echo "$RESPONSE_TIME < 1000" | bc -l) )); then
    print_status "PASS" "Frontend response time acceptable (${RESPONSE_TIME}ms)"
else
    print_status "WARN" "Frontend response time slow (${RESPONSE_TIME}ms)"
fi

# Test page size (basic check)
PAGE_SIZE=$(curl -s "$FRONTEND_URL" | wc -c)

if [ "$PAGE_SIZE" -gt 1000 ]; then
    print_status "PASS" "Page size reasonable (${PAGE_SIZE} bytes)"
else
    print_status "WARN" "Page size very small (${PAGE_SIZE} bytes)"
fi

echo ""
print_status "PASS" "All frontend tests completed"
