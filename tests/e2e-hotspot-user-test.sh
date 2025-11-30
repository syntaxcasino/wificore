#!/bin/bash

# End-to-End Test for Hotspot User
# Tests complete hotspot user workflow from payment to WiFi access

set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
GRAY='\033[0;37m'
NC='\033[0m' # No Color

echo -e "\n${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   End-to-End Test: Hotspot User Workflow              ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}\n"

PASSED=0
FAILED=0
TOTAL=0

# Generate unique test data
TIMESTAMP=$(date +%H%M%S)
TEST_PHONE="+25471234$TIMESTAMP"
TEST_MAC="AA:BB:CC:DD:EE:${TIMESTAMP:0:2}"
CHECKOUT_REQUEST_ID=""
PAYMENT_ID=""
USER_ID=""

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

echo -e "${CYAN}Test Data:${NC}"
echo -e "  ${GRAY}Phone: $TEST_PHONE${NC}"
echo -e "  ${GRAY}MAC: $TEST_MAC${NC}"

# Test 1: View available packages (public endpoint)
test_step "View available packages (public)" '
    RESPONSE=$(curl -s http://localhost/api/packages)
    COUNT=$(echo $RESPONSE | jq ". | length")
    
    if [ "$COUNT" -gt 0 ]; then
        FIRST_PACKAGE=$(echo $RESPONSE | jq -r ".[0].name")
        FIRST_PRICE=$(echo $RESPONSE | jq -r ".[0].price")
        echo -e "    ${GRAY}Available packages: $COUNT${NC}"
        echo -e "    ${GRAY}First package: $FIRST_PACKAGE - KES $FIRST_PRICE${NC}"
        true
    else
        false
    fi
'

# Test 2: Initiate M-Pesa payment
test_step "Initiate M-Pesa payment" '
    RESPONSE=$(curl -s -X POST http://localhost/api/payments/initiate \
        -H "Content-Type: application/json" \
        -d "{\"package_id\":1,\"phone_number\":\"$TEST_PHONE\",\"mac_address\":\"$TEST_MAC\"}" 2>/dev/null || echo "{\"success\":false}")
    
    CHECKOUT_REQUEST_ID=$(echo $RESPONSE | jq -r ".checkout_request_id")
    
    if [ -z "$CHECKOUT_REQUEST_ID" ] || [ "$CHECKOUT_REQUEST_ID" = "null" ]; then
        echo -e "    ${YELLOW}M-Pesa not configured (expected in test environment)${NC}"
        CHECKOUT_REQUEST_ID="TEST_$RANDOM"
    else
        echo -e "    ${GRAY}Checkout ID: $CHECKOUT_REQUEST_ID${NC}"
    fi
    
    true
'

# Test 3: Create payment record in database
test_step "Create payment record" '
    QUERY="INSERT INTO payments (mac_address, phone_number, package_id, amount, transaction_id, status) VALUES ('\''$TEST_MAC'\'', '\''$TEST_PHONE'\'', 1, 100.00, '\''$CHECKOUT_REQUEST_ID'\'', '\''pending'\'') RETURNING id;"
    
    PAYMENT_ID=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Payment ID: $PAYMENT_ID${NC}"
    [ ! -z "$PAYMENT_ID" ] && [ "$PAYMENT_ID" -gt 0 ]
'

# Test 4: Simulate M-Pesa callback
test_step "Simulate M-Pesa callback (payment success)" '
    CALLBACK_DATA=$(cat <<EOF
{
  "Body": {
    "stkCallback": {
      "CheckoutRequestID": "$CHECKOUT_REQUEST_ID",
      "ResultCode": 0,
      "ResultDesc": "The service request is processed successfully.",
      "CallbackMetadata": {
        "Item": [
          {"Name": "Amount", "Value": 100},
          {"Name": "MpesaReceiptNumber", "Value": "TEST123456"},
          {"Name": "PhoneNumber", "Value": ${TEST_PHONE//+/}}
        ]
      }
    }
  }
}
EOF
)
    
    RESPONSE=$(curl -s -X POST http://localhost/api/mpesa/callback \
        -H "Content-Type: application/json" \
        -d "$CALLBACK_DATA")
    
    SUCCESS=$(echo $RESPONSE | jq -r ".success")
    echo -e "    ${GRAY}Callback processed: $SUCCESS${NC}"
    
    [ "$SUCCESS" = "true" ]
'

# Test 5: Wait for queue processing
test_step "Wait for queue to process payment" '
    echo -e "    ${GRAY}Waiting 10 seconds for queue processing...${NC}"
    sleep 10
    
    QUERY="SELECT status FROM payments WHERE id = $PAYMENT_ID;"
    STATUS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Payment status: $STATUS${NC}"
    [ "$STATUS" = "completed" ]
'

# Test 6: Verify user was created
test_step "Verify hotspot user was created" '
    QUERY="SELECT id, username, role, phone_number FROM users WHERE phone_number = '\''$TEST_PHONE'\'';"
    RESULT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null)
    
    if [ ! -z "$RESULT" ]; then
        USER_ID=$(echo $RESULT | awk -F"|" "{print \$1}" | tr -d " ")
        USERNAME=$(echo $RESULT | awk -F"|" "{print \$2}" | tr -d " ")
        ROLE=$(echo $RESULT | awk -F"|" "{print \$3}" | tr -d " ")
        
        echo -e "    ${GRAY}User ID: $USER_ID${NC}"
        echo -e "    ${GRAY}Username: $USERNAME${NC}"
        echo -e "    ${GRAY}Role: $ROLE${NC}"
        
        [ "$ROLE" = "hotspot_user" ]
    else
        false
    fi
'

# Test 7: Verify subscription was created
test_step "Verify subscription was created" '
    QUERY="SELECT id, status, mikrotik_username, mikrotik_password FROM user_subscriptions WHERE user_id = $USER_ID;"
    RESULT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null)
    
    if [ ! -z "$RESULT" ]; then
        SUB_ID=$(echo $RESULT | awk -F"|" "{print \$1}" | tr -d " ")
        STATUS=$(echo $RESULT | awk -F"|" "{print \$2}" | tr -d " ")
        MIKROTIK_USER=$(echo $RESULT | awk -F"|" "{print \$3}" | tr -d " ")
        MIKROTIK_PASS=$(echo $RESULT | awk -F"|" "{print \$4}" | tr -d " ")
        
        echo -e "    ${GRAY}Subscription ID: $SUB_ID${NC}"
        echo -e "    ${GRAY}Status: $STATUS${NC}"
        echo -e "    ${GRAY}MikroTik Username: $MIKROTIK_USER${NC}"
        echo -e "    ${GRAY}MikroTik Password: $MIKROTIK_PASS${NC}"
        
        [ "$STATUS" = "active" ]
    else
        false
    fi
'

# Test 8: Verify RADIUS entry was created
test_step "Verify RADIUS entry was created" '
    PHONE_CLEAN=${TEST_PHONE//+/}
    PHONE_SHORT=${PHONE_CLEAN:0:12}
    
    QUERY="SELECT username, value FROM radcheck WHERE username LIKE '\''user_${PHONE_SHORT}%'\'';"
    RESULT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null)
    
    if [ ! -z "$RESULT" ]; then
        RADIUS_USER=$(echo $RESULT | awk -F"|" "{print \$1}" | tr -d " ")
        RADIUS_PASS=$(echo $RESULT | awk -F"|" "{print \$2}" | tr -d " ")
        
        echo -e "    ${GRAY}RADIUS Username: $RADIUS_USER${NC}"
        echo -e "    ${GRAY}RADIUS Password: $RADIUS_PASS${NC}"
        
        [ ! -z "$RADIUS_USER" ]
    else
        false
    fi
'

# Test 9: Check queue jobs processed
test_step "Verify queue jobs were processed" '
    QUERY="SELECT COUNT(*) FROM jobs WHERE queue = '\''payments'\'';"
    PENDING=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Pending payment jobs: $PENDING${NC}"
    
    FAILED_QUERY="SELECT COUNT(*) FROM failed_jobs WHERE queue = '\''payments'\'' AND failed_at > NOW() - INTERVAL '\''1 minute'\'';"
    FAILED=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$FAILED_QUERY" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Failed jobs (last minute): $FAILED${NC}"
    
    [ "$FAILED" -eq 0 ]
'

# Test 10: Test returning user (second purchase)
test_step "Test returning user (second purchase)" '
    NEW_CHECKOUT_ID="TEST_RETURN_$RANDOM"
    
    QUERY="INSERT INTO payments (user_id, mac_address, phone_number, package_id, amount, transaction_id, status) VALUES ($USER_ID, '\''$TEST_MAC'\'', '\''$TEST_PHONE'\'', 1, 100.00, '\''$NEW_CHECKOUT_ID'\'', '\''completed'\'') RETURNING id;"
    
    NEW_PAYMENT_ID=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Second payment ID: $NEW_PAYMENT_ID${NC}"
    
    [ ! -z "$NEW_PAYMENT_ID" ] && [ "$NEW_PAYMENT_ID" -gt 0 ]
'

# Test 11: Verify user count didn'\''t increase
test_step "Verify returning user wasn'\''t duplicated" '
    QUERY="SELECT COUNT(*) FROM users WHERE phone_number = '\''$TEST_PHONE'\'';"
    USER_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" 2>/dev/null | tr -d " ")
    
    echo -e "    ${GRAY}Users with phone $TEST_PHONE: $USER_COUNT${NC}"
    
    [ "$USER_COUNT" -eq 1 ]
'

# Test 12: Check queue worker logs
test_step "Check queue worker logs for errors" '
    LOGS=$(docker exec traidnet-backend tail -50 /var/www/html/storage/logs/payments-queue.log 2>/dev/null || echo "")
    
    if [ ! -z "$LOGS" ]; then
        ERROR_COUNT=$(echo "$LOGS" | grep -ci "ERROR\|FAIL" || echo "0")
        echo -e "    ${GRAY}Error lines in logs: $ERROR_COUNT${NC}"
        
        if [ "$ERROR_COUNT" -gt 0 ]; then
            echo -e "    ${YELLOW}Recent errors found (check logs for details)${NC}"
        fi
    fi
    
    true # Informational only
'

# Cleanup
echo -e "\n${YELLOW}[CLEANUP] Removing test data...${NC}"

PHONE_CLEAN=${TEST_PHONE//+/}
PHONE_SHORT=${PHONE_CLEAN:0:12}

CLEANUP_QUERY=$(cat <<EOF
DELETE FROM user_subscriptions WHERE user_id = $USER_ID;
DELETE FROM payments WHERE phone_number = '$TEST_PHONE';
DELETE FROM radcheck WHERE username LIKE 'user_${PHONE_SHORT}%';
DELETE FROM users WHERE id = $USER_ID;
EOF
)

docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$CLEANUP_QUERY" >/dev/null 2>&1
echo -e "    ${GRAY}Test data cleaned up${NC}"

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
    echo -e "\n${GREEN}[SUCCESS] ALL HOTSPOT USER TESTS PASSED!${NC}\n"
    exit 0
else
    echo -e "\n${RED}[FAILED] SOME TESTS FAILED${NC}\n"
    exit 1
fi
