#!/bin/bash

# Queue Fix Automation Script for Linux/Unix
# Run this script to diagnose and fix queue issues

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
BACKEND_DIR="$SCRIPT_DIR/backend"

echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}  WiFi Hotspot - Queue Fix Script${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""

# Change to backend directory
cd "$BACKEND_DIR" || {
    echo -e "${RED}❌ Error: Cannot find backend directory${NC}"
    exit 1
}

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ Error: PHP is not installed${NC}"
    exit 1
fi

# Check if artisan exists
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ Error: artisan file not found${NC}"
    exit 1
fi

# Step 1: Check Status
echo -e "${YELLOW}Step 1: Checking Current Status...${NC}"
echo -e "${YELLOW}-----------------------------------${NC}"
php artisan queue:diagnose-failed
echo ""

# Step 2: Run Migrations
echo -e "${YELLOW}Step 2: Ensuring Migrations...${NC}"
echo -e "${YELLOW}-----------------------------------${NC}"
php artisan migrate --force
MIGRATE_RESULT=$?
if [ $MIGRATE_RESULT -ne 0 ]; then
    echo -e "${RED}❌ Migration failed!${NC}"
    exit 1
fi
echo -e "${GREEN}✅ Migrations complete${NC}"
echo ""

# Step 3: Test Dashboard Job
echo -e "${YELLOW}Step 3: Testing Dashboard Job (Synchronous)...${NC}"
echo -e "${YELLOW}-----------------------------------${NC}"
php artisan test:dashboard-job --sync --clear-cache
TEST_RESULT=$?
echo ""

if [ $TEST_RESULT -ne 0 ]; then
    echo -e "${RED}❌ Dashboard job test FAILED!${NC}"
    echo -e "${RED}   Check the error messages above.${NC}"
    echo ""
    echo -e "${YELLOW}Common fixes:${NC}"
    echo "  1. Check database connection in .env"
    echo "  2. Ensure all migrations are run"
    echo "  3. Check storage/logs/laravel.log for details"
    echo ""
    exit 1
fi

echo -e "${GREEN}✅ Dashboard job test PASSED!${NC}"
echo ""

# Step 4: Fix Failed Jobs
echo -e "${YELLOW}Step 4: Fixing Failed Jobs...${NC}"
echo -e "${YELLOW}-----------------------------------${NC}"

# Count failed jobs
FAILED_COUNT=$(php artisan queue:failed | wc -l)

if [ "$FAILED_COUNT" -gt 1 ]; then
    echo -e "${YELLOW}Found failed jobs. What would you like to do?${NC}"
    echo "  1. Retry all failed jobs (recommended)"
    echo "  2. Clear all failed jobs"
    echo "  3. Skip this step"
    read -p "Enter choice (1-3): " choice
    
    case $choice in
        1)
            echo -e "${CYAN}Retrying all failed jobs...${NC}"
            php artisan queue:fix
            ;;
        2)
            echo -e "${CYAN}Clearing all failed jobs...${NC}"
            php artisan queue:fix --clear
            ;;
        3)
            echo -e "${YELLOW}Skipping...${NC}"
            ;;
        *)
            echo -e "${RED}Invalid choice. Skipping...${NC}"
            ;;
    esac
else
    echo -e "${GREEN}✅ No failed jobs found!${NC}"
fi
echo ""

# Step 5: Test Queued Job
echo -e "${YELLOW}Step 5: Testing Queued Job...${NC}"
echo -e "${YELLOW}-----------------------------------${NC}"
php artisan test:dashboard-job
echo ""

# Step 6: Queue Worker Instructions
echo -e "${YELLOW}Step 6: Queue Worker Setup${NC}"
echo -e "${YELLOW}-----------------------------------${NC}"
echo -e "${YELLOW}Queue worker is NOT running automatically.${NC}"
echo ""
echo -e "${CYAN}To start the queue worker, run:${NC}"
echo "  php artisan queue:work --tries=3 --timeout=120"
echo ""
echo -e "${CYAN}Or to run it in the background:${NC}"
echo "  nohup php artisan queue:work --tries=3 --timeout=120 > storage/logs/worker.log 2>&1 &"
echo ""
echo -e "${CYAN}For production, use Supervisor (see QUEUE_TROUBLESHOOTING.md)${NC}"
echo ""

read -p "Do you want to start the queue worker now? (y/n): " start_worker

if [[ $start_worker == "y" || $start_worker == "Y" ]]; then
    echo -e "${CYAN}Starting queue worker in background...${NC}"
    nohup php artisan queue:work --tries=3 --timeout=120 > storage/logs/worker.log 2>&1 &
    WORKER_PID=$!
    sleep 2
    
    # Check if worker is still running
    if ps -p $WORKER_PID > /dev/null; then
        echo -e "${GREEN}✅ Queue worker started! (PID: $WORKER_PID)${NC}"
        echo "   Logs: storage/logs/worker.log"
        echo "   To stop: kill $WORKER_PID"
    else
        echo -e "${RED}❌ Failed to start queue worker${NC}"
        echo "   Check storage/logs/worker.log for errors"
    fi
else
    echo -e "${YELLOW}⚠️  Remember to start the queue worker manually!${NC}"
fi
echo ""

# Final Summary
echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}  Summary${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""
echo -e "${GREEN}✅ Migrations: Complete${NC}"
echo -e "${GREEN}✅ Dashboard Job: Tested${NC}"
echo -e "${GREEN}✅ Failed Jobs: Processed${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "  1. Ensure queue worker is running"
echo "  2. Open dashboard in browser"
echo "  3. Check System Health widget"
echo "  4. Monitor logs: tail -f storage/logs/laravel.log"
echo ""
echo -e "${YELLOW}Useful Commands:${NC}"
echo "  php artisan queue:diagnose-failed    - Check for issues"
echo "  php artisan queue:fix                - Fix failed jobs"
echo "  php artisan test:dashboard-job       - Test the job"
echo "  php artisan queue:work               - Start worker"
echo "  php artisan queue:restart            - Restart all workers"
echo ""
echo -e "${YELLOW}Monitor Queue Worker:${NC}"
echo "  tail -f storage/logs/worker.log      - Watch worker logs"
echo "  ps aux | grep 'queue:work'           - Check if running"
echo "  kill <PID>                           - Stop worker"
echo ""
echo -e "${YELLOW}Documentation:${NC}"
echo "  See QUEUE_TROUBLESHOOTING.md for detailed guide"
echo "  See QUEUE_FIX_STEPS.md for manual steps"
echo ""
echo -e "${CYAN}========================================${NC}"
echo -e "${GREEN}  Fix Complete!${NC}"
echo -e "${CYAN}========================================${NC}"
