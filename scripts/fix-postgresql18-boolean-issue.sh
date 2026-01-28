#!/bin/bash
set -e

echo "=========================================="
echo "PostgreSQL 18 Boolean Type Fix"
echo "=========================================="
echo ""
echo "This script fixes the 'operator does not exist: boolean = integer' error"
echo "by disabling PDO emulated prepares and switching PgBouncer to session mode."
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running from correct directory
if [ ! -f "docker-compose.production.yml" ]; then
    echo -e "${RED}Error: docker-compose.production.yml not found${NC}"
    echo "Please run this script from the wificore root directory"
    exit 1
fi

echo -e "${YELLOW}Step 1: Stopping backend to prevent data corruption...${NC}"
docker compose -f docker-compose.production.yml stop wificore-backend

echo -e "${YELLOW}Step 2: Updating database configuration...${NC}"
docker cp backend/config/database.php wificore-backend:/var/www/html/config/database.php
echo -e "${GREEN}✓ Database config updated (PDO::ATTR_EMULATE_PREPARES => false)${NC}"

echo -e "${YELLOW}Step 3: Updating PgBouncer configuration...${NC}"
docker cp pgbouncer/pgbouncer.ini wificore-pgbouncer:/etc/pgbouncer/pgbouncer.ini
docker cp pgbouncer/pgbouncer.ini wificore-pgbouncer-read:/etc/pgbouncer/pgbouncer.ini
echo -e "${GREEN}✓ PgBouncer config updated (pool_mode = session)${NC}"

echo -e "${YELLOW}Step 4: Restarting PgBouncer services...${NC}"
docker compose -f docker-compose.production.yml restart wificore-pgbouncer wificore-pgbouncer-read
sleep 5
echo -e "${GREEN}✓ PgBouncer services restarted${NC}"

echo -e "${YELLOW}Step 5: Starting backend...${NC}"
docker compose -f docker-compose.production.yml start wificore-backend
sleep 10

echo -e "${YELLOW}Step 6: Checking backend health...${NC}"
for i in {1..30}; do
    if docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan --version > /dev/null 2>&1; then
        echo -e "${GREEN}✓ Backend is healthy${NC}"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${RED}✗ Backend failed to start${NC}"
        echo "Check logs: docker logs wificore-backend --tail 100"
        exit 1
    fi
    echo "Waiting for backend... ($i/30)"
    sleep 2
done

echo -e "${YELLOW}Step 7: Verifying database queries...${NC}"
if docker compose -f docker-compose.production.yml exec -T wificore-backend php artisan tinker --execute="App\Models\Tenant::where('is_active', true)->count();" > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Boolean queries working correctly${NC}"
else
    echo -e "${RED}✗ Boolean queries still failing${NC}"
    echo "Check logs: docker logs wificore-backend --tail 100"
    exit 1
fi

echo ""
echo -e "${GREEN}=========================================="
echo "Fix Applied Successfully!"
echo "==========================================${NC}"
echo ""
echo "Summary of changes:"
echo "  • PDO emulated prepares: DISABLED"
echo "  • PgBouncer pool mode: SESSION"
echo "  • Boolean queries: FIXED"
echo ""
echo "The system should now handle boolean columns correctly."
echo "Monitor logs for any remaining issues:"
echo "  docker logs -f wificore-backend"
echo ""
