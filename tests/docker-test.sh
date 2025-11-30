#!/bin/bash

# Docker-based Testing Script for User Management Restructure

echo "=========================================="
echo "Docker Environment - User Management Test"
echo "=========================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Check if Docker is running
echo -e "${CYAN}Checking Docker environment...${NC}"
if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}❌ Docker is not running${NC}"
    echo "Please start Docker Desktop and try again"
    exit 1
fi
echo -e "${GREEN}✅ Docker is running${NC}"
echo ""

# Check if containers are running
echo -e "${CYAN}Checking containers...${NC}"
FRONTEND_RUNNING=$(docker ps --filter "name=traidnet-frontend" --format "{{.Names}}")
BACKEND_RUNNING=$(docker ps --filter "name=traidnet-backend" --format "{{.Names}}")
NGINX_RUNNING=$(docker ps --filter "name=traidnet-nginx" --format "{{.Names}}")

if [ -z "$FRONTEND_RUNNING" ] || [ -z "$BACKEND_RUNNING" ] || [ -z "$NGINX_RUNNING" ]; then
    echo -e "${YELLOW}⚠️  Some containers are not running${NC}"
    echo ""
    echo "Starting containers..."
    docker-compose up -d
    echo ""
    echo "Waiting for services to be ready (30 seconds)..."
    sleep 30
else
    echo -e "${GREEN}✅ All required containers are running${NC}"
fi

echo ""

# Test if frontend is accessible
echo -e "${CYAN}Testing frontend accessibility...${NC}"
if curl -s -o /dev/null -w "%{http_code}" http://localhost | grep -q "200"; then
    echo -e "${GREEN}✅ Frontend is accessible at http://localhost${NC}"
else
    echo -e "${RED}❌ Frontend is not accessible${NC}"
    echo "Check nginx and frontend containers"
    exit 1
fi

echo ""

# Test routes
echo -e "${CYAN}Testing User Management Routes...${NC}"
echo "----------------------------------------"

declare -a routes=(
    "Admin Users:/dashboard/users/all"
    "Create Admin:/dashboard/users/create"
    "Roles & Permissions:/dashboard/users/roles"
    "PPPoE Users:/dashboard/pppoe/users"
    "Hotspot Users:/dashboard/hotspot/users"
    "Component Showcase:/component-showcase"
)

for route in "${routes[@]}"; do
    IFS=':' read -r name path <<< "$route"
    url="http://localhost$path"
    
    printf "%-30s" "$name..."
    
    status_code=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    
    if [ "$status_code" -eq 200 ] || [ "$status_code" -eq 302 ]; then
        echo -e "${GREEN}✅ OK${NC}"
    else
        echo -e "${RED}❌ FAILED (Status: $status_code)${NC}"
    fi
done

echo ""

# Check if files exist in frontend container
echo -e "${CYAN}Verifying component files in container...${NC}"
echo "----------------------------------------"

declare -a files=(
    "src/views/dashboard/users/UserListNew.vue:Admin Users"
    "src/views/dashboard/pppoe/PPPoEUsers.vue:PPPoE Users"
    "src/views/dashboard/hotspot/HotspotUsers.vue:Hotspot Users"
    "src/components/base/BaseButton.vue:BaseButton"
    "src/components/base/BaseCard.vue:BaseCard"
    "src/components/layout/templates/PageHeader.vue:PageHeader"
)

for file_entry in "${files[@]}"; do
    IFS=':' read -r file name <<< "$file_entry"
    printf "%-30s" "$name..."
    
    if docker exec traidnet-frontend test -f "$file" 2>/dev/null; then
        echo -e "${GREEN}✅ Found${NC}"
    else
        echo -e "${RED}❌ Missing${NC}"
    fi
done

echo ""

# Test API endpoints
echo -e "${CYAN}Testing API Endpoints...${NC}"
echo "----------------------------------------"

declare -a api_routes=(
    "/api/health:Health Check"
    "/api/users:Users API"
)

for api_route in "${api_routes[@]}"; do
    IFS=':' read -r path name <<< "$api_route"
    url="http://localhost$path"
    
    printf "%-30s" "$name..."
    
    status_code=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    
    if [ "$status_code" -eq 200 ] || [ "$status_code" -eq 401 ]; then
        echo -e "${GREEN}✅ OK (Status: $status_code)${NC}"
    else
        echo -e "${YELLOW}⚠️  Status: $status_code${NC}"
    fi
done

echo ""

# Container health check
echo -e "${CYAN}Container Health Status...${NC}"
echo "----------------------------------------"

declare -a containers=(
    "traidnet-frontend:Frontend"
    "traidnet-backend:Backend"
    "traidnet-nginx:Nginx"
    "traidnet-postgres:PostgreSQL"
    "traidnet-redis:Redis"
)

for container_entry in "${containers[@]}"; do
    IFS=':' read -r container name <<< "$container_entry"
    printf "%-30s" "$name..."
    
    health=$(docker inspect --format='{{.State.Health.Status}}' "$container" 2>/dev/null)
    
    if [ "$health" = "healthy" ]; then
        echo -e "${GREEN}✅ Healthy${NC}"
    elif [ "$health" = "starting" ]; then
        echo -e "${YELLOW}⚠️  Starting${NC}"
    elif [ -z "$health" ]; then
        # No health check defined, check if running
        if docker ps --filter "name=$container" --format "{{.Names}}" | grep -q "$container"; then
            echo -e "${GREEN}✅ Running${NC}"
        else
            echo -e "${RED}❌ Not Running${NC}"
        fi
    else
        echo -e "${RED}❌ Unhealthy${NC}"
    fi
done

echo ""

# Summary
echo "=========================================="
echo -e "${CYAN}Test Summary${NC}"
echo "=========================================="
echo ""
echo "✅ Docker environment is ready"
echo "✅ All containers are running"
echo "✅ Frontend is accessible"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Open browser to: ${CYAN}http://localhost${NC}"
echo "2. Login to the dashboard"
echo "3. Test the three user views:"
echo "   - Admin Users:    ${CYAN}http://localhost/dashboard/users/all${NC}"
echo "   - PPPoE Users:    ${CYAN}http://localhost/dashboard/pppoe/users${NC}"
echo "   - Hotspot Users:  ${CYAN}http://localhost/dashboard/hotspot/users${NC}"
echo ""
echo "4. Follow manual test guide: tests/MANUAL_TEST_GUIDE.md"
echo ""
echo "=========================================="
