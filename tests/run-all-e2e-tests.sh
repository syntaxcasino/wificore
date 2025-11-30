#!/bin/bash

# Master E2E Test Runner
# Runs all end-to-end tests for the WiFi Hotspot Management System

set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
GRAY='\033[0;37m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

echo -e "\n${MAGENTA}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${MAGENTA}║   WiFi Hotspot Management System - E2E Test Suite     ║${NC}"
echo -e "${MAGENTA}╚════════════════════════════════════════════════════════╝${NC}\n"

ADMIN_PASSED=false
HOTSPOT_PASSED=false
START_TIME=$(date +%s)

# Pre-flight checks
echo -e "${CYAN}═══ PRE-FLIGHT CHECKS ═══${NC}\n"

echo -e "${YELLOW}[1/5] Checking Docker containers...${NC}"
CONTAINERS=$(docker ps --format "{{.Names}}" 2>/dev/null)
REQUIRED_CONTAINERS=("traidnet-backend" "traidnet-postgres" "traidnet-freeradius" "traidnet-nginx")

for container in "${REQUIRED_CONTAINERS[@]}"; do
    if echo "$CONTAINERS" | grep -q "$container"; then
        echo -e "  ${GREEN}[OK]${NC} $container is running"
    else
        echo -e "  ${RED}[FAIL]${NC} $container is NOT running"
        echo -e "\n${YELLOW}Please start all containers with: docker-compose up -d${NC}"
        exit 1
    fi
done

echo -e "\n${YELLOW}[2/5] Checking database connectivity...${NC}"
if docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT 1;" >/dev/null 2>&1; then
    echo -e "  ${GREEN}[OK]${NC} Database is accessible"
else
    echo -e "  ${RED}[FAIL]${NC} Database is not accessible"
    exit 1
fi

echo -e "\n${YELLOW}[3/5] Checking API endpoint...${NC}"
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/packages 2>/dev/null || echo "000")
if [ "$API_STATUS" = "200" ]; then
    echo -e "  ${GREEN}[OK]${NC} API is responding (Status: $API_STATUS)"
else
    echo -e "  ${RED}[FAIL]${NC} API is not responding (Status: $API_STATUS)"
    exit 1
fi

echo -e "\n${YELLOW}[4/5] Checking queue workers...${NC}"
WORKERS=$(docker exec traidnet-backend supervisorctl status 2>/dev/null | grep -c "RUNNING" || echo "0")
if [ "$WORKERS" -gt 0 ]; then
    echo -e "  ${GREEN}[OK]${NC} Queue workers are running ($WORKERS workers)"
else
    echo -e "  ${YELLOW}[WARN]${NC} No queue workers running (tests may be slower)"
fi

echo -e "\n${YELLOW}[5/5] Checking required tables...${NC}"
TABLES=("users" "packages" "payments" "user_subscriptions" "radcheck" "personal_access_tokens")
MISSING_TABLES=()

for table in "${TABLES[@]}"; do
    if docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM $table LIMIT 1;" >/dev/null 2>&1; then
        echo -e "  ${GREEN}[OK]${NC} Table '$table' exists"
    else
        echo -e "  ${RED}[FAIL]${NC} Table '$table' is missing"
        MISSING_TABLES+=("$table")
    fi
done

if [ ${#MISSING_TABLES[@]} -gt 0 ]; then
    echo -e "\n${RED}Missing tables detected. Please run database migrations.${NC}"
    exit 1
fi

echo -e "\n${GREEN}[OK] All pre-flight checks passed!${NC}\n"
sleep 2

# Run Admin Tests
echo -e "\n${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              RUNNING ADMIN USER TESTS                  ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}\n"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if bash "$SCRIPT_DIR/e2e-admin-test.sh"; then
    ADMIN_PASSED=true
    echo -e "\n${GREEN}[OK] Admin tests completed successfully${NC}\n"
else
    echo -e "\n${RED}[FAIL] Admin tests failed${NC}\n"
fi

sleep 3

# Run Hotspot User Tests
echo -e "\n${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║           RUNNING HOTSPOT USER TESTS                   ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}\n"

if bash "$SCRIPT_DIR/e2e-hotspot-user-test.sh"; then
    HOTSPOT_PASSED=true
    echo -e "\n${GREEN}[OK] Hotspot user tests completed successfully${NC}\n"
else
    echo -e "\n${RED}[FAIL] Hotspot user tests failed${NC}\n"
fi

# Final Summary
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

echo -e "\n${MAGENTA}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${MAGENTA}║              OVERALL TEST RESULTS                      ║${NC}"
echo -e "${MAGENTA}╚════════════════════════════════════════════════════════╝${NC}\n"

echo -e "${CYAN}Test Suite Results:${NC}"
if [ "$ADMIN_PASSED" = true ]; then
    echo -e "  Admin User Tests:     ${GREEN}[PASSED]${NC}"
else
    echo -e "  Admin User Tests:     ${RED}[FAILED]${NC}"
fi

if [ "$HOTSPOT_PASSED" = true ]; then
    echo -e "  Hotspot User Tests:   ${GREEN}[PASSED]${NC}"
else
    echo -e "  Hotspot User Tests:   ${RED}[FAILED]${NC}"
fi

echo -e "\n${GRAY}Execution Time: $DURATION seconds${NC}"

if [ "$ADMIN_PASSED" = true ] && [ "$HOTSPOT_PASSED" = true ]; then
    echo -e "\n${GREEN}╔════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                                                        ║${NC}"
    echo -e "${GREEN}║           ALL TESTS PASSED!                            ║${NC}"
    echo -e "${GREEN}║                                                        ║${NC}"
    echo -e "${GREEN}║     System is ready for production deployment!        ║${NC}"
    echo -e "${GREEN}║                                                        ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════╝${NC}\n"
    exit 0
else
    echo -e "\n${RED}╔════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║                                                        ║${NC}"
    echo -e "${RED}║              SOME TESTS FAILED                         ║${NC}"
    echo -e "${RED}║                                                        ║${NC}"
    echo -e "${RED}║     Please review the errors above and fix issues     ║${NC}"
    echo -e "${RED}║                                                        ║${NC}"
    echo -e "${RED}╚════════════════════════════════════════════════════════╝${NC}\n"
    
    echo -e "${YELLOW}Troubleshooting Tips:${NC}"
    echo -e "  ${WHITE}1. Check Docker logs: docker-compose logs${NC}"
    echo -e "  ${WHITE}2. Check Laravel logs: docker exec traidnet-backend tail -100 /var/www/html/storage/logs/laravel.log${NC}"
    echo -e "  ${WHITE}3. Check queue logs: docker exec traidnet-backend tail -100 /var/www/html/storage/logs/payments-queue.log${NC}"
    echo -e "  ${WHITE}4. Restart services: docker-compose restart${NC}"
    echo -e "  ${WHITE}5. Review documentation: docs/TROUBLESHOOTING_GUIDE.md${NC}\n"
    
    exit 1
fi
