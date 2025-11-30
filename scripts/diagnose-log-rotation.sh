#!/bin/bash

###############################################################################
# Log Rotation Queue Diagnostic Script
# 
# This script performs comprehensive diagnostics on the log rotation system
# including permissions, file ownership, queue status, and detailed log analysis.
###############################################################################

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Configuration
CONTAINER_NAME="traidnet-backend"
DB_CONTAINER="traidnet-postgres"
DB_USER="admin"
DB_NAME="wifi_hotspot"

# Helper functions
print_header() {
    echo -e "\n${BOLD}${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${CYAN}║  $1${NC}"
    echo -e "${BOLD}${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}\n"
}

print_section() {
    echo -e "\n${BOLD}${BLUE}═══ $1 ═══${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_info() {
    echo -e "${CYAN}ℹ${NC} $1"
}

check_container() {
    if ! docker ps --format '{{.Names}}' | grep -q "^${1}$"; then
        print_error "Container $1 is not running"
        return 1
    fi
    return 0
}

###############################################################################
# Diagnostic Functions
###############################################################################

check_prerequisites() {
    print_section "Checking Prerequisites"
    
    if ! check_container "$CONTAINER_NAME"; then
        print_error "Backend container is not running"
        exit 1
    fi
    
    if ! check_container "$DB_CONTAINER"; then
        print_error "Database container is not running"
        exit 1
    fi
    
    print_success "All required containers are running"
}

check_storage_permissions() {
    print_section "Storage Directory Permissions"
    
    echo -e "${BOLD}Storage directory ownership and permissions:${NC}"
    docker exec "$CONTAINER_NAME" ls -la /var/www/html/storage | head -20
    
    echo -e "\n${BOLD}Logs directory ownership and permissions:${NC}"
    docker exec "$CONTAINER_NAME" ls -la /var/www/html/storage/logs | head -20
    
    echo -e "\n${BOLD}Current user in container:${NC}"
    docker exec "$CONTAINER_NAME" whoami
    docker exec "$CONTAINER_NAME" id
    
    # Check if www-data can write to logs directory
    echo -e "\n${BOLD}Testing write permissions:${NC}"
    if docker exec "$CONTAINER_NAME" su -s /bin/bash www-data -c "touch /var/www/html/storage/logs/.test-write && rm /var/www/html/storage/logs/.test-write" 2>/dev/null; then
        print_success "www-data can write to logs directory"
    else
        print_error "www-data CANNOT write to logs directory"
        echo -e "${YELLOW}This is likely causing the log rotation failures${NC}"
    fi
}

check_log_rotation_queue() {
    print_section "Log Rotation Queue Status"
    
    # Check supervisor status for log-rotation worker
    echo -e "${BOLD}Log rotation worker status:${NC}"
    docker exec "$CONTAINER_NAME" supervisorctl status | grep "log-rotation" || echo "No log-rotation worker found"
    
    # Check pending jobs
    echo -e "\n${BOLD}Pending log-rotation jobs:${NC}"
    PENDING=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM jobs WHERE queue = 'log-rotation';
    " | xargs)
    echo "Pending: $PENDING"
    
    # Check failed jobs
    echo -e "\n${BOLD}Failed log-rotation jobs:${NC}"
    FAILED=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM failed_jobs WHERE queue = 'log-rotation';
    " | xargs)
    echo "Failed: $FAILED"
    
    if [ "$FAILED" -gt 0 ]; then
        print_warning "Found $FAILED failed log-rotation jobs"
        
        echo -e "\n${BOLD}Recent failed job details:${NC}"
        docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -x -c "
            SELECT 
                id,
                queue,
                LEFT(payload::text, 200) as payload_preview,
                LEFT(exception::text, 500) as error_preview,
                failed_at
            FROM failed_jobs 
            WHERE queue = 'log-rotation'
            ORDER BY failed_at DESC 
            LIMIT 3;
        "
    else
        print_success "No failed log-rotation jobs"
    fi
}

check_log_files() {
    print_section "Log Files Analysis"
    
    LOG_PATH="/var/www/html/storage/logs"
    
    echo -e "${BOLD}Log files that should be rotated:${NC}"
    LOGS=(
        "router-checks-queue.log"
        "router-checks-queue-error.log"
        "router-data-queue.log"
        "router-data-queue-error.log"
        "laravel.log"
        "mpesa_raw.log"
        "mpesa_raw_callback.log"
    )
    
    for log in "${LOGS[@]}"; do
        if docker exec "$CONTAINER_NAME" test -f "$LOG_PATH/$log" 2>/dev/null; then
            SIZE=$(docker exec "$CONTAINER_NAME" stat -c%s "$LOG_PATH/$log" 2>/dev/null || echo "0")
            SIZE_MB=$((SIZE / 1024 / 1024))
            PERMS=$(docker exec "$CONTAINER_NAME" stat -c%a "$LOG_PATH/$log" 2>/dev/null || echo "???")
            OWNER=$(docker exec "$CONTAINER_NAME" stat -c%U:%G "$LOG_PATH/$log" 2>/dev/null || echo "???")
            
            if [ "$SIZE_MB" -gt 10 ]; then
                echo -e "  ${RED}●${NC} $log - ${RED}${SIZE_MB} MB${NC} - $PERMS - $OWNER"
            else
                echo -e "  ${GREEN}●${NC} $log - ${SIZE_MB} MB - $PERMS - $OWNER"
            fi
        else
            echo -e "  ${YELLOW}○${NC} $log - ${YELLOW}Not found${NC}"
        fi
    done
    
    echo -e "\n${BOLD}Rotated log files (compressed):${NC}"
    docker exec "$CONTAINER_NAME" ls -lh "$LOG_PATH" | grep ".gz" | tail -10 || echo "  No compressed logs found"
}

check_rotatelog_job_code() {
    print_section "RotateLogs Job Implementation"
    
    echo -e "${BOLD}Checking RotateLogs.php for permission-related code:${NC}"
    
    # Check if the file exists
    if docker exec "$CONTAINER_NAME" test -f /var/www/html/app/Jobs/RotateLogs.php; then
        print_success "RotateLogs.php exists"
        
        # Check for chown/chgrp calls (these require root)
        echo -e "\n${BOLD}Checking for problematic permission calls:${NC}"
        if docker exec "$CONTAINER_NAME" grep -n "chown\|chgrp" /var/www/html/app/Jobs/RotateLogs.php 2>/dev/null; then
            print_error "Found chown/chgrp calls - these require root privileges!"
            echo -e "${YELLOW}These calls will fail when running as www-data${NC}"
        else
            print_success "No chown/chgrp calls found"
        fi
        
        # Check for chmod calls
        echo -e "\n${BOLD}Checking for chmod calls:${NC}"
        docker exec "$CONTAINER_NAME" grep -n "chmod" /var/www/html/app/Jobs/RotateLogs.php 2>/dev/null || echo "  No chmod calls found"
        
        # Show the file creation section
        echo -e "\n${BOLD}File creation logic:${NC}"
        docker exec "$CONTAINER_NAME" grep -A 5 -B 2 "touch\|file_put_contents.*fullPath" /var/www/html/app/Jobs/RotateLogs.php 2>/dev/null || echo "  Could not extract file creation logic"
    else
        print_error "RotateLogs.php not found"
    fi
}

check_supervisor_config() {
    print_section "Supervisor Configuration"
    
    echo -e "${BOLD}Log rotation queue worker configuration:${NC}"
    docker exec "$CONTAINER_NAME" grep -A 20 "\[program:laravel-queue-log-rotation\]" /etc/supervisor/conf.d/laravel-queue.conf 2>/dev/null || print_error "Could not read supervisor config"
    
    echo -e "\n${BOLD}Supervisor logs for log-rotation worker:${NC}"
    docker exec "$CONTAINER_NAME" tail -20 /var/www/html/storage/logs/log-rotation-queue.log 2>/dev/null || echo "  No logs found"
    
    echo -e "\n${BOLD}Supervisor error logs for log-rotation worker:${NC}"
    docker exec "$CONTAINER_NAME" tail -20 /var/www/html/storage/logs/log-rotation-queue-error.log 2>/dev/null || echo "  No error logs found"
}

check_laravel_logs() {
    print_section "Laravel Application Logs"
    
    echo -e "${BOLD}Recent log rotation related errors:${NC}"
    docker exec "$CONTAINER_NAME" grep -i "rotatelog\|log.rotation\|chown\|chgrp\|permission denied" /var/www/html/storage/logs/laravel.log 2>/dev/null | tail -20 || echo "  No relevant errors found"
}

check_docker_user() {
    print_section "Docker Container User Configuration"
    
    echo -e "${BOLD}Container user configuration (from docker-compose.yml):${NC}"
    grep -A 5 "traidnet-backend:" ../docker-compose.yml | grep "user:" || echo "  No user override in docker-compose.yml"
    
    echo -e "\n${BOLD}Processes running in container:${NC}"
    docker exec "$CONTAINER_NAME" ps aux | head -10
    
    echo -e "\n${BOLD}PHP-FPM user:${NC}"
    docker exec "$CONTAINER_NAME" grep -E "^user|^group" /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || echo "  Could not read PHP-FPM config"
}

analyze_root_cause() {
    print_section "Root Cause Analysis"
    
    echo -e "${BOLD}Analyzing potential issues:${NC}\n"
    
    ISSUES_FOUND=0
    
    # Check 1: Permission issues
    if ! docker exec "$CONTAINER_NAME" su -s /bin/bash www-data -c "touch /var/www/html/storage/logs/.test-write && rm /var/www/html/storage/logs/.test-write" 2>/dev/null; then
        print_error "Issue 1: www-data cannot write to logs directory"
        echo -e "  ${YELLOW}Solution: Fix directory permissions${NC}"
        echo -e "  ${CYAN}Command: docker exec $CONTAINER_NAME chown -R www-data:www-data /var/www/html/storage${NC}"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Check 2: chown/chgrp in code
    if docker exec "$CONTAINER_NAME" grep -q "chown\|chgrp" /var/www/html/app/Jobs/RotateLogs.php 2>/dev/null; then
        print_error "Issue 2: RotateLogs.php contains chown/chgrp calls"
        echo -e "  ${YELLOW}Solution: Remove chown/chgrp calls from RotateLogs.php${NC}"
        echo -e "  ${CYAN}These operations require root privileges and will fail as www-data${NC}"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Check 3: Worker not running
    if ! docker exec "$CONTAINER_NAME" supervisorctl status | grep -q "laravel-queue-log-rotation.*RUNNING"; then
        print_error "Issue 3: Log rotation worker is not running"
        echo -e "  ${YELLOW}Solution: Restart the worker${NC}"
        echo -e "  ${CYAN}Command: docker exec $CONTAINER_NAME supervisorctl restart laravel-queue-log-rotation:*${NC}"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Check 4: Failed jobs accumulating
    FAILED=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM failed_jobs WHERE queue = 'log-rotation';
    " | xargs)
    
    if [ "$FAILED" -gt 0 ]; then
        print_warning "Issue 4: $FAILED failed log-rotation jobs in database"
        echo -e "  ${YELLOW}Solution: Clear failed jobs after fixing root cause${NC}"
        echo -e "  ${CYAN}Command: docker exec $DB_CONTAINER psql -U $DB_USER -d $DB_NAME -c \"DELETE FROM failed_jobs WHERE queue = 'log-rotation';\"${NC}"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    if [ "$ISSUES_FOUND" -eq 0 ]; then
        print_success "No obvious issues detected"
    else
        echo -e "\n${BOLD}${RED}Total issues found: $ISSUES_FOUND${NC}"
    fi
}

show_fix_commands() {
    print_section "Recommended Fix Commands"
    
    echo -e "${BOLD}Step 1: Fix storage permissions${NC}"
    echo "docker exec $CONTAINER_NAME chown -R www-data:www-data /var/www/html/storage"
    echo "docker exec $CONTAINER_NAME chmod -R 775 /var/www/html/storage"
    echo ""
    
    echo -e "${BOLD}Step 2: Clear failed log-rotation jobs${NC}"
    echo "docker exec $DB_CONTAINER psql -U $DB_USER -d $DB_NAME -c \"DELETE FROM failed_jobs WHERE queue = 'log-rotation';\""
    echo ""
    
    echo -e "${BOLD}Step 3: Restart log-rotation worker${NC}"
    echo "docker exec $CONTAINER_NAME supervisorctl restart laravel-queue-log-rotation:*"
    echo ""
    
    echo -e "${BOLD}Step 4: Monitor the worker${NC}"
    echo "docker exec $CONTAINER_NAME supervisorctl status | grep log-rotation"
    echo "docker exec $CONTAINER_NAME tail -f /var/www/html/storage/logs/log-rotation-queue.log"
    echo ""
    
    echo -e "${BOLD}Step 5: Test log rotation manually${NC}"
    echo "docker exec $CONTAINER_NAME php artisan queue:work database --queue=log-rotation --once"
    echo ""
}

offer_auto_fix() {
    print_section "Auto-Fix Option"
    
    echo -e "${YELLOW}Would you like to automatically fix the detected issues? [y/N]${NC} "
    read -r response
    
    if [[ "$response" =~ ^[Yy]$ ]]; then
        echo -e "\n${BOLD}Applying fixes...${NC}\n"
        
        # Fix 1: Permissions
        echo -n "Fixing storage permissions... "
        if docker exec "$CONTAINER_NAME" chown -R www-data:www-data /var/www/html/storage 2>/dev/null && \
           docker exec "$CONTAINER_NAME" chmod -R 775 /var/www/html/storage 2>/dev/null; then
            print_success "Done"
        else
            print_error "Failed"
        fi
        
        # Fix 2: Clear failed jobs
        echo -n "Clearing failed log-rotation jobs... "
        if docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c "DELETE FROM failed_jobs WHERE queue = 'log-rotation';" >/dev/null 2>&1; then
            print_success "Done"
        else
            print_error "Failed"
        fi
        
        # Fix 3: Restart worker
        echo -n "Restarting log-rotation worker... "
        if docker exec "$CONTAINER_NAME" supervisorctl restart laravel-queue-log-rotation:* >/dev/null 2>&1; then
            print_success "Done"
        else
            print_error "Failed"
        fi
        
        # Wait and check status
        sleep 2
        echo -e "\n${BOLD}Worker status after fixes:${NC}"
        docker exec "$CONTAINER_NAME" supervisorctl status | grep log-rotation
        
        echo -e "\n${GREEN}Auto-fix completed. Monitor the logs to verify the fix.${NC}"
    else
        echo -e "${YELLOW}Skipping auto-fix. Use the commands above to fix manually.${NC}"
    fi
}

###############################################################################
# Main Execution
###############################################################################

main() {
    print_header "Log Rotation Queue Diagnostic Tool"
    
    # Run all diagnostic checks
    check_prerequisites
    check_storage_permissions
    check_log_rotation_queue
    check_log_files
    check_rotatelog_job_code
    check_supervisor_config
    check_laravel_logs
    check_docker_user
    
    # Analyze and provide recommendations
    analyze_root_cause
    show_fix_commands
    
    # Offer to auto-fix
    offer_auto_fix
    
    echo -e "\n${GREEN}${BOLD}Diagnostic complete!${NC}\n"
}

# Run main function
main
