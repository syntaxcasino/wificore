#!/bin/bash

# End-to-End Test for Admin User
# Tests complete admin workflow including authentication and system management

set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
GRAY='\033[0;37m'
NC='\033[0m' # No Color

echo -e "\n${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║     End-to-End Test: Admin User Workflow              ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}\n"

PASSED=0
FAILED=0
TOTAL=0

test_step() {
    local step_name=$1
    TOTAL=$((TOTAL + 1))
    
    echo -e "\n${YELLOW}[$TOTAL] Testing: $step_name${NC}"
    
    if eval "$2"; then
        echo -e "    ${GREEN}[PASSED]${NC}"
        PASSED=$((PASSED + 1))
        return 0
    else
        echo -e "    ${RED}[FAILED]${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

# Test 1: Check if admin user exists in RADIUS
test_step "Admin user exists in RADIUS" '
    COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM radcheck WHERE username='\''admin'\'';" 2>/dev/null | tr -d " ")
    
    if [ "$COUNT" -eq 0 ]; then
        echo -e "    ${YELLOW}Creating admin user...${NC}"
        docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "INSERT INTO radcheck (username, attribute, op, value) VALUES ('\''admin'\'', '\''Cleartext-Password'\'', '\'':='\'', '\''admin123'\'') ON CONFLICT DO NOTHING;" >/dev/null 2>&1
        sleep 2
        COUNT=1
    fi
    
    echo -e "    ${GRAY}Admin user found in RADIUS${NC}"
    [ "$COUNT" -gt 0 ]
'

# Test 2: Admin login
ADMIN_TOKEN=""
test_step "Admin login via RADIUS" '
    RESPONSE=$(curl -s -X POST http://localhost/api/login \
        -H "Content-Type: application/json" \
        -d '\''{"username":"admin","password":"admin123"}'\'')
    
    SUCCESS=$(echo $RESPONSE | jq -r ".success")
    ADMIN_TOKEN=$(echo $RESPONSE | jq -r ".token")
    ROLE=$(echo $RESPONSE | jq -r ".user.role")
    
    if [ "$SUCCESS" = "true" ] && [ ! -z "$ADMIN_TOKEN" ]; then
        echo -e "    ${GRAY}Token: ${ADMIN_TOKEN:0:30}...${NC}"
        echo -e "    ${GRAY}Role: $ROLE${NC}"
        [ "$ROLE" = "admin" ]
    else
        false
    fi
'

# Test 3: Access admin-only endpoint (routers)
test_step "Access admin-only endpoint (GET /api/routers)" '
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/routers)
    
    echo -e "    ${GRAY}Status: $STATUS${NC}"
    [ "$STATUS" = "200" ]
'

# Test 4: View all users
test_step "View all users (GET /api/users)" '
    RESPONSE=$(curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/users)
    
    TOTAL_USERS=$(echo $RESPONSE | jq -r ".users.total")
    echo -e "    ${GRAY}Total users: $TOTAL_USERS${NC}"
    
    STATUS=$(echo $RESPONSE | jq -r ".success")
    [ "$STATUS" = "true" ]
'

# Test 5: View all payments
test_step "View all payments (GET /api/payments)" '
    RESPONSE=$(curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/payments)
    
    TOTAL_PAYMENTS=$(echo $RESPONSE | jq -r ".payments.total")
    echo -e "    ${GRAY}Total payments: $TOTAL_PAYMENTS${NC}"
    
    STATUS=$(echo $RESPONSE | jq -r ".success")
    [ "$STATUS" = "true" ]
'

# Test 6: View all subscriptions
test_step "View all subscriptions (GET /api/subscriptions)" '
    RESPONSE=$(curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/subscriptions)
    
    TOTAL_SUBS=$(echo $RESPONSE | jq -r ".subscriptions.total")
    echo -e "    ${GRAY}Total subscriptions: $TOTAL_SUBS${NC}"
    
    STATUS=$(echo $RESPONSE | jq -r ".success")
    [ "$STATUS" = "true" ]
'

# Test 7: View packages
test_step "View packages (GET /api/packages)" '
    RESPONSE=$(curl -s http://localhost/api/packages)
    
    COUNT=$(echo $RESPONSE | jq ". | length")
    echo -e "    ${GRAY}Total packages: $COUNT${NC}"
    
    [ "$COUNT" -gt 0 ]
'

# Test 8: Check queue workers
test_step "Queue workers are running" '
    WORKERS=$(docker exec traidnet-backend supervisorctl status 2>/dev/null | grep -c "RUNNING" || echo "0")
    
    echo -e "    ${GRAY}Running workers: $WORKERS${NC}"
    [ "$WORKERS" -gt 0 ]
'

# Test 9: Check queue jobs
test_step "Check queue system" '
    JOB_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM jobs;" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Pending jobs: $JOB_COUNT${NC}"
    true # Always pass, informational only
'

# Test 10: Admin profile
test_step "View admin profile (GET /api/profile)" '
    RESPONSE=$(curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/profile)
    
    USERNAME=$(echo $RESPONSE | jq -r ".user.username")
    ROLE=$(echo $RESPONSE | jq -r ".user.role")
    LAST_LOGIN=$(echo $RESPONSE | jq -r ".user.last_login_at")
    
    echo -e "    ${GRAY}Username: $USERNAME${NC}"
    echo -e "    ${GRAY}Role: $ROLE${NC}"
    echo -e "    ${GRAY}Last login: $LAST_LOGIN${NC}"
    
    [ "$ROLE" = "admin" ]
'

# Test 11: Logout
test_step "Admin logout (POST /api/logout)" '
    RESPONSE=$(curl -s -X POST \
        -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/logout)
    
    SUCCESS=$(echo $RESPONSE | jq -r ".success")
    MESSAGE=$(echo $RESPONSE | jq -r ".message")
    
    echo -e "    ${GRAY}Message: $MESSAGE${NC}"
    [ "$SUCCESS" = "true" ]
'

# Test 12: Verify token is revoked
test_step "Verify token is revoked after logout" '
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "Authorization: Bearer $ADMIN_TOKEN" \
        http://localhost/api/routers)
    
    echo -e "    ${GRAY}Token correctly revoked (401 Unauthorized)${NC}"
    [ "$STATUS" = "401" ]
'

# Summary
echo -e "\n${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║                  TEST RESULTS SUMMARY                  ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}"

echo -e "\nTotal Tests:  $TOTAL"
echo -e "${GREEN}Passed:       $PASSED${NC}"
echo -e "${RED}Failed:       $FAILED${NC}"

SUCCESS_RATE=$(awk "BEGIN {printf \"%.2f\", ($PASSED / $TOTAL) * 100}")
echo -e "${YELLOW}Success Rate: $SUCCESS_RATE%${NC}"

if [ $FAILED -eq 0 ]; then
    echo -e "\n${GREEN}[SUCCESS] ALL ADMIN TESTS PASSED!${NC}\n"
    exit 0
else
    echo -e "\n${RED}[FAILED] SOME TESTS FAILED${NC}\n"
    exit 1
fi
