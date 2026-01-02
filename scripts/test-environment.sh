#!/bin/bash

# WiFi Hotspot Management System - End-to-End Environment Testing
# Master test script that runs all environment validation tests

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test results tracking
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "PASS")
            echo -e "${GREEN}✅ PASS${NC}: $message"
            ((PASSED_TESTS++))
            ;;
        "FAIL")
            echo -e "${RED}❌ FAIL${NC}: $message"
            ((FAILED_TESTS++))
            ;;
        "WARN")
            echo -e "${YELLOW}⚠️  WARN${NC}: $message"
            ;;
        "INFO")
            echo -e "${BLUE}ℹ️  INFO${NC}: $message"
            ;;
        *)
            echo "$message"
            ;;
    esac
    ((TOTAL_TESTS++))
}

# Function to run a test script
run_test() {
    local test_script=$1
    local test_name=$2

    if [ -f "scripts/$test_script" ]; then
        print_status "INFO" "Running $test_name..."
        if bash "scripts/$test_script"; then
            print_status "PASS" "$test_name completed successfully"
        else
            print_status "FAIL" "$test_name failed"
        fi
    else
        print_status "FAIL" "$test_script not found"
    fi
}

# Main test execution
echo "=================================================="
echo "🚀 WiFi Hotspot Management System - Environment Tests"
echo "=================================================="
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    print_status "FAIL" "Docker is not running"
    exit 1
fi

# Check if docker-compose.yml exists
if [ ! -f "docker-compose.yml" ]; then
    print_status "FAIL" "docker-compose.yml not found in current directory"
    exit 1
fi

# Check if services are running
if ! docker-compose ps | grep -q "Up"; then
    print_status "WARN" "Some Docker services may not be running. Starting services..."
    docker-compose up -d
    sleep 10  # Wait for services to start
fi

echo ""
echo "🔍 Starting Environment Tests..."
echo "=================================="

# Run individual test suites
run_test "test-docker-services.sh" "Docker Services Health Check"
run_test "test-database.sh" "Database Connectivity & Schema"
run_test "test-backend-api.sh" "Backend API Endpoints"
run_test "test-frontend.sh" "Frontend Build & Serving"
run_test "test-websockets.sh" "WebSocket Connectivity"
run_test "test-radius.sh" "RADIUS Authentication"
run_test "test-end-to-end.sh" "End-to-End User Flows"

echo ""
echo "=================================================="
echo "📊 TEST RESULTS SUMMARY"
echo "=================================================="
echo "Total Tests: $TOTAL_TESTS"
echo -e "Passed: ${GREEN}$PASSED_TESTS${NC}"
echo -e "Failed: ${RED}$FAILED_TESTS${NC}"

if [ $FAILED_TESTS -eq 0 ]; then
    echo ""
    print_status "PASS" "All environment tests passed! ✅"
    exit 0
else
    echo ""
    print_status "FAIL" "$FAILED_TESTS test(s) failed. Check logs above for details."
    exit 1
fi
