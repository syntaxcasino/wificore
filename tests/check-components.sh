#!/bin/bash

# Component Verification Script
# Checks if all required components and files exist

echo "=========================================="
echo "Component Structure Verification"
echo "=========================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

TOTAL=0
FOUND=0
MISSING=0

check_file() {
    local file=$1
    local description=$2
    
    TOTAL=$((TOTAL + 1))
    printf "%-60s" "$description..."
    
    if [ -f "$file" ]; then
        echo -e "${GREEN}✅ Found${NC}"
        FOUND=$((FOUND + 1))
    else
        echo -e "${RED}❌ Missing${NC}"
        MISSING=$((MISSING + 1))
    fi
}

# Base Components
echo -e "${CYAN}Base Components:${NC}"
echo "----------------------------------------"
check_file "frontend/src/components/base/BaseButton.vue" "BaseButton"
check_file "frontend/src/components/base/BaseCard.vue" "BaseCard"
check_file "frontend/src/components/base/BaseBadge.vue" "BaseBadge"
check_file "frontend/src/components/base/BaseInput.vue" "BaseInput"
check_file "frontend/src/components/base/BaseSelect.vue" "BaseSelect"
check_file "frontend/src/components/base/BaseSearch.vue" "BaseSearch"
check_file "frontend/src/components/base/BasePagination.vue" "BasePagination"
check_file "frontend/src/components/base/BaseLoading.vue" "BaseLoading"
check_file "frontend/src/components/base/BaseEmpty.vue" "BaseEmpty"
check_file "frontend/src/components/base/BaseAlert.vue" "BaseAlert"
check_file "frontend/src/components/base/BaseModal.vue" "BaseModal"
check_file "frontend/src/components/base/README.md" "Base Components README"

echo ""

# Layout Templates
echo -e "${CYAN}Layout Templates:${NC}"
echo "----------------------------------------"
check_file "frontend/src/components/layout/templates/PageHeader.vue" "PageHeader"
check_file "frontend/src/components/layout/templates/PageContent.vue" "PageContent"
check_file "frontend/src/components/layout/templates/PageFooter.vue" "PageFooter"
check_file "frontend/src/components/layout/templates/PageContainer.vue" "PageContainer"

echo ""

# User Management Components
echo -e "${CYAN}User Management Components:${NC}"
echo "----------------------------------------"
check_file "frontend/src/views/dashboard/users/UserListNew.vue" "Admin Users List"
check_file "frontend/src/views/dashboard/users/RolesPermissions.vue" "Roles & Permissions"
check_file "frontend/src/views/dashboard/pppoe/PPPoEUsers.vue" "PPPoE Users List"
check_file "frontend/src/views/dashboard/hotspot/HotspotUsers.vue" "Hotspot Users List"

echo ""

# User Modals
echo -e "${CYAN}User Modals:${NC}"
echo "----------------------------------------"
check_file "frontend/src/components/users/CreateUserModal.vue" "Create User Modal"
check_file "frontend/src/components/users/EditUserModal.vue" "Edit User Modal"
check_file "frontend/src/components/users/UserDetailsModal.vue" "User Details Modal"

echo ""

# Composables
echo -e "${CYAN}Composables:${NC}"
echo "----------------------------------------"
check_file "frontend/src/composables/data/useUsers.js" "useUsers (data)"
check_file "frontend/src/composables/utils/useFilters.js" "useFilters (utils)"
check_file "frontend/src/composables/utils/usePagination.js" "usePagination (utils)"

echo ""

# Test Files
echo -e "${CYAN}Test Files:${NC}"
echo "----------------------------------------"
check_file "frontend/src/views/test/ComponentShowcase.vue" "Component Showcase"

echo ""

# Configuration Files
echo -e "${CYAN}Configuration Files:${NC}"
echo "----------------------------------------"
check_file "frontend/src/router/index.js" "Router Configuration"
check_file "frontend/src/components/layout/AppSidebar.vue" "App Sidebar"

echo ""

# Documentation
echo -e "${CYAN}Documentation:${NC}"
echo "----------------------------------------"
check_file "PHASE_1_COMPLETE.md" "Phase 1 Documentation"
check_file "PHASE_2_USERS_MODULE_COMPLETE.md" "Phase 2 Documentation"
check_file "ARCHITECTURE_RESTRUCTURE_COMPLETE.md" "Architecture Documentation"
check_file "IMMEDIATE_TESTING_STEPS.md" "Testing Steps"
check_file "tests/MANUAL_TEST_GUIDE.md" "Manual Test Guide"

echo ""

# Summary
echo "=========================================="
echo -e "${CYAN}Summary${NC}"
echo "=========================================="
echo ""
echo "Total Files Checked: $TOTAL"
echo -e "Found:               ${GREEN}$FOUND${NC}"
echo -e "Missing:             ${RED}$MISSING${NC}"
echo ""

if [ $MISSING -eq 0 ]; then
    echo -e "${GREEN}✅ All components are in place!${NC}"
    echo ""
    echo "You're ready to start testing."
    echo "Run: ./tests/run-all-tests.sh"
    exit 0
else
    echo -e "${YELLOW}⚠️  Some components are missing${NC}"
    echo ""
    echo "Please ensure all components are created before testing."
    exit 1
fi
