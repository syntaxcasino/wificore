#!/bin/bash

# Quick Test Script for User Management Restructure
# Run this to quickly verify all routes are accessible

echo "=================================="
echo "User Management Quick Test Script"
echo "=================================="
echo ""

# Check if dev server is running
echo "Checking if dev server is running..."
if curl -s --head --request GET http://localhost:3000 | grep "200 OK" > /dev/null; then 
    echo "✅ Dev server is running"
else
    echo "❌ Dev server is not running!"
    echo "Please start the dev server first:"
    echo "  cd frontend"
    echo "  npm run dev"
    exit 1
fi

echo ""

# Test routes
declare -a routes=(
    "Admin Users:/dashboard/users/all"
    "Create Admin:/dashboard/users/create"
    "Roles & Permissions:/dashboard/users/roles"
    "PPPoE Users:/dashboard/pppoe/users"
    "Hotspot Users:/dashboard/hotspot/users"
    "Component Showcase:/component-showcase"
)

echo "Testing Routes:"
echo "----------------"

for route in "${routes[@]}"; do
    IFS=':' read -r name path <<< "$route"
    url="http://localhost:3000$path"
    
    printf "Testing: %-25s" "$name..."
    
    status_code=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    
    if [ "$status_code" -eq 200 ]; then
        echo " ✅ OK"
    elif [ "$status_code" -eq 302 ] || [ "$status_code" -eq 301 ]; then
        echo " ⚠️  Redirect ($status_code)"
    else
        echo " ❌ FAILED (Status: $status_code)"
    fi
done

echo ""
echo "=================================="
echo "Quick Test Complete!"
echo "=================================="
echo ""
echo "Next Steps:"
echo "1. Open browser to http://localhost:3000"
echo "2. Login to the dashboard"
echo "3. Follow the manual test guide in tests/MANUAL_TEST_GUIDE.md"
echo ""
echo "Quick Links:"
echo "- Admin Users:    http://localhost:3000/dashboard/users/all"
echo "- PPPoE Users:    http://localhost:3000/dashboard/pppoe/users"
echo "- Hotspot Users:  http://localhost:3000/dashboard/hotspot/users"
echo "- Component Demo: http://localhost:3000/component-showcase"
echo ""
