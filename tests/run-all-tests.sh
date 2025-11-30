#!/bin/bash

# Comprehensive Test Runner Script

echo "=========================================="
echo "User Management Restructure - Full Tests"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Test counters
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Function to run a test
run_test() {
    local test_name=$1
    local test_command=$2
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    printf "%-50s" "$test_name..."
    
    if eval "$test_command" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ PASS${NC}"
        PASSED_TESTS=$((PASSED_TESTS + 1))
        return 0
    else
        echo -e "${RED}❌ FAIL${NC}"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        return 1
    fi
}

# Check prerequisites
echo -e "${CYAN}Checking Prerequisites...${NC}"
echo "----------------------------------------"

run_test "Node.js installed" "command -v node"
run_test "npm installed" "command -v npm"
run_test "curl installed" "command -v curl"

echo ""

# Check dev server
echo -e "${CYAN}Checking Development Server...${NC}"
echo "----------------------------------------"

if curl -s --head --request GET http://localhost:3000 | grep "200 OK" > /dev/null; then
    echo -e "${GREEN}✅ Dev server is running${NC}"
    SERVER_RUNNING=true
else
    echo -e "${RED}❌ Dev server is not running${NC}"
    echo ""
    echo "Please start the dev server first:"
    echo "  cd frontend"
    echo "  npm run dev"
    echo ""
    echo "Or run: ./tests/start-dev.sh"
    exit 1
fi

echo ""

# Test routes
echo -e "${CYAN}Testing Routes...${NC}"
echo "----------------------------------------"

declare -a routes=(
    "Admin Users List:/dashboard/users/all"
    "Create Admin User:/dashboard/users/create"
    "Roles & Permissions:/dashboard/users/roles"
    "PPPoE Users List:/dashboard/pppoe/users"
    "PPPoE Add User:/dashboard/pppoe/add-user"
    "Hotspot Users List:/dashboard/hotspot/users"
    "Hotspot Sessions:/dashboard/hotspot/sessions"
    "Component Showcase:/component-showcase"
)

for route in "${routes[@]}"; do
    IFS=':' read -r name path <<< "$route"
    url="http://localhost:3000$path"
    run_test "$name" "curl -s -o /dev/null -w '%{http_code}' '$url' | grep -E '200|302'"
done

echo ""

# Test API endpoints (if backend is running)
echo -e "${CYAN}Testing API Endpoints...${NC}"
echo "----------------------------------------"

if curl -s --head --request GET http://localhost:8000/api/health > /dev/null 2>&1; then
    echo -e "${GREEN}Backend API is running${NC}"
    
    run_test "Users API endpoint" "curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8000/api/users' | grep -E '200|401'"
    run_test "PPPoE Users API endpoint" "curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8000/api/pppoe/users' | grep -E '200|401'"
    run_test "Hotspot Users API endpoint" "curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8000/api/hotspot/users' | grep -E '200|401'"
else
    echo -e "${YELLOW}⚠️  Backend API is not running (skipping API tests)${NC}"
fi

echo ""

# Test file structure
echo -e "${CYAN}Checking File Structure...${NC}"
echo "----------------------------------------"

run_test "Admin Users component exists" "test -f frontend/src/views/dashboard/users/UserListNew.vue"
run_test "PPPoE Users component exists" "test -f frontend/src/views/dashboard/pppoe/PPPoEUsers.vue"
run_test "Hotspot Users component exists" "test -f frontend/src/views/dashboard/hotspot/HotspotUsers.vue"
run_test "Roles component exists" "test -f frontend/src/views/dashboard/users/RolesPermissions.vue"
run_test "useUsers composable exists" "test -f frontend/src/composables/data/useUsers.js"
run_test "useFilters composable exists" "test -f frontend/src/composables/utils/useFilters.js"
run_test "usePagination composable exists" "test -f frontend/src/composables/utils/usePagination.js"

echo ""

# Test base components
echo -e "${CYAN}Checking Base Components...${NC}"
echo "----------------------------------------"

run_test "BaseButton exists" "test -f frontend/src/components/base/BaseButton.vue"
run_test "BaseCard exists" "test -f frontend/src/components/base/BaseCard.vue"
run_test "BaseBadge exists" "test -f frontend/src/components/base/BaseBadge.vue"
run_test "BaseInput exists" "test -f frontend/src/components/base/BaseInput.vue"
run_test "BaseSearch exists" "test -f frontend/src/components/base/BaseSearch.vue"
run_test "BasePagination exists" "test -f frontend/src/components/base/BasePagination.vue"
run_test "BaseModal exists" "test -f frontend/src/components/base/BaseModal.vue"
run_test "BaseLoading exists" "test -f frontend/src/components/base/BaseLoading.vue"

echo ""

# Test layout templates
echo -e "${CYAN}Checking Layout Templates...${NC}"
echo "----------------------------------------"

run_test "PageHeader exists" "test -f frontend/src/components/layout/templates/PageHeader.vue"
run_test "PageContent exists" "test -f frontend/src/components/layout/templates/PageContent.vue"
run_test "PageFooter exists" "test -f frontend/src/components/layout/templates/PageFooter.vue"
run_test "PageContainer exists" "test -f frontend/src/components/layout/templates/PageContainer.vue"

echo ""

# Summary
echo "=========================================="
echo -e "${CYAN}Test Summary${NC}"
echo "=========================================="
echo ""
echo "Total Tests:  $TOTAL_TESTS"
echo -e "Passed:       ${GREEN}$PASSED_TESTS${NC}"
echo -e "Failed:       ${RED}$FAILED_TESTS${NC}"
echo ""

if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "${GREEN}✅ All tests passed!${NC}"
    echo ""
    echo "Next Steps:"
    echo "1. Open browser to http://localhost:3000"
    echo "2. Login to the dashboard"
    echo "3. Manually test the three user views:"
    echo "   - Admin Users: /dashboard/users/all"
    echo "   - PPPoE Users: /dashboard/pppoe/users"
    echo "   - Hotspot Users: /dashboard/hotspot/users"
    echo ""
    echo "See tests/MANUAL_TEST_GUIDE.md for detailed testing steps"
    exit 0
else
    echo -e "${RED}❌ Some tests failed${NC}"
    echo ""
    echo "Please review the failed tests above and fix any issues."
    exit 1
fi
