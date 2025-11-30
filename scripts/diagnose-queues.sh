#!/bin/bash

###############################################################################
# WiFi Hotspot Queue Diagnostic Script
# 
# This script performs comprehensive diagnostics on the Laravel queue system
# including worker status, job counts, failed jobs, and log analysis.
#
# Features:
# - Detailed queue and worker status
# - Failed job analysis with automatic fixes
# - Interactive troubleshooting mode
# - Comprehensive logging and reporting
# - Performance metrics and monitoring
#
# Usage: ./diagnose-queues.sh [options]
# Options:
#   --detailed       Show detailed job information
#   --logs           Show recent log entries
#   --fix-failed     Attempt to fix common issues
#   --interactive    Run in interactive mode
#   --report         Generate a detailed report file
#   --help           Show this help message
###############################################################################

set -euo pipefail

# Check for required commands and Docker access
check_requirements() {
    local missing=()
    
    # Only require Docker on the host system
    local required_commands=(
        "docker"
        "grep"
        "awk"
        "sed"
        "date"
        "mkdir"
        "uname"
        "tail"
        "head"
    )
    
    # Check each command
    for cmd in "${required_commands[@]}"; do
        if ! command -v "$cmd" >/dev/null 2>&1; then
            missing+=("$cmd")
        fi
    done
    
    # Check if Docker is running
    if ! docker info >/dev/null 2>&1; then
        echo -e "${RED}Error: Docker is not running. Please start Docker and try again.${NC}"
        exit 1
    fi
    
    # Check if we can access the database container
    if ! docker ps --filter "name=^${DB_CONTAINER}$" --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
        echo -e "${RED}Error: Database container '$DB_CONTAINER' is not running.${NC}"
        echo -e "Please start the application with 'docker-compose up -d'"
        exit 1
    fi
    
    # Check if we can access the app container
    if ! docker ps --filter "name=^${CONTAINER_NAME}$" --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        echo -e "${YELLOW}Warning: App container '$CONTAINER_NAME' is not running. Some checks will be skipped.${NC}"
    fi
    
    # If any required commands are missing, show error and exit
    if [ ${#missing[@]} -gt 0 ]; then
        echo -e "${RED}Error: The following required commands are missing:${NC}"
        for cmd in "${missing[@]}"; do
            echo " - $cmd"
        done
        echo -e "\nPlease install the missing commands and try again."
        exit 1
    fi
    
    # Check if we can execute commands in the database container
    if ! docker exec "$DB_CONTAINER" psql --version >/dev/null 2>&1; then
        echo -e "${YELLOW}Warning: Cannot execute psql in the database container. Some database checks will be limited.${NC}"
    fi
    
    # Check if we can execute commands in the app container
    if ! docker exec "$CONTAINER_NAME" php --version >/dev/null 2>&1; then
        echo -e "${YELLOW}Warning: Cannot execute commands in the app container. Some checks will be limited.${NC}"
    fi
}

# Script version
VERSION="1.1.0"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color
BOLD='\033[1m'
UNDERLINE='\033[4m'

# Log levels
LOG_LEVEL_INFO=1
LOG_LEVEL_WARN=2
LOG_LEVEL_ERROR=3
LOG_LEVEL_DEBUG=4

# Default log level (can be overridden with --debug)
LOG_LEVEL=$LOG_LEVEL_INFO

# Timestamp for logs
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

# Configuration
CONFIG_FILE="$(dirname "$0")/queue-diagnostics.conf"
LOG_DIR="$(dirname "$0")/../storage/logs"
REPORT_DIR="$(dirname "$0")/../storage/reports"
REPORT_FILE="$REPORT_DIR/queue-diagnostic-report_${TIMESTAMP}.log"

# Default values (can be overridden in config file)
CONTAINER_NAME="traidnet-backend"
DB_CONTAINER="traidnet-postgres"
DB_USER="admin"
DB_NAME="wifi_hotspot"

# Load configuration if exists
if [ -f "$CONFIG_FILE" ]; then
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
fi

# Ensure required directories exist
mkdir -p "$LOG_DIR"
mkdir -p "$REPORT_DIR"

# Check for required commands before proceeding
check_requirements

# Initialize report file
init_report() {
    echo "# Queue Diagnostic Report" > "$REPORT_FILE"
    echo "# Generated: $(date)" >> "$REPORT_FILE"
    echo "# System: $(uname -a)" >> "$REPORT_FILE"
    echo "# Script Version: $VERSION" >> "$REPORT_FILE"
    echo "" >> "$REPORT_FILE"
}

# Logging function
log() {
    local level=$1
    local message=$2
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Log to console with colors
    case $level in
        "INFO") 
            echo -e "${CYAN}[${timestamp}] [INFO] ${message}${NC}"
            ;;
        "WARN") 
            echo -e "${YELLOW}[${timestamp}] [WARN] ${message}${NC}" 
            ;;
        "ERROR") 
            echo -e "${RED}[${timestamp}] [ERROR] ${message}${NC}" 
            ;;
        "SUCCESS") 
            echo -e "${GREEN}[${timestamp}] [SUCCESS] ${message}${NC}"
            ;;
        "DEBUG") 
            [ "$LOG_LEVEL" -ge "$LOG_LEVEL_DEBUG" ] && 
                echo -e "${MAGENTA}[${timestamp}] [DEBUG] ${message}${NC}"
            ;;
        *) 
            echo "[${timestamp}] [${level}] ${message}" 
            ;;
    esac
    
    # Always log to report file
    echo "[${timestamp}] [${level}] ${message}" >> "$REPORT_FILE"
}

# Show script header
show_header() {
    echo -e "${BOLD}${CYAN}"
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║              WiFi Hotspot Queue Diagnostic Tool               ║"
    echo "║                     Version: $VERSION                      ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"
}

# Show help message
show_help() {
    show_header
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  --detailed       Show detailed job information"
    echo "  --logs           Show recent log entries"
    echo "  --fix-failed     Attempt to fix common issues"
    echo "  --interactive    Run in interactive mode"
    echo "  --report         Generate a detailed report file"
    echo "  --debug          Enable debug output"
    echo "  --help           Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 --detailed --logs"
    echo "  $0 --fix-failed"
    echo "  $0 --interactive"
    echo "  $0 --report"
    exit 0
}

# Configuration
CONTAINER_NAME="traidnet-backend"
DB_CONTAINER="traidnet-postgres"
DB_USER="admin"
DB_NAME="wifi_hotspot"

# Parse command line arguments
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --detailed)
                DETAILED=true
                shift
                ;;
            --logs)
                SHOW_LOGS=true
                shift
                ;;
            --fix-failed)
                FIX_FAILED=true
                shift
                ;;
            --interactive)
                INTERACTIVE=true
                shift
                ;;
            --report)
                GENERATE_REPORT=true
                shift
                ;;
            --debug)
                LOG_LEVEL=$LOG_LEVEL_DEBUG
                shift
                ;;
            --help|-h)
                show_help
                ;;
            *)
                log "ERROR" "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
}

# Initialize variables
DETAILED=false
SHOW_LOGS=false
FIX_FAILED=false
INTERACTIVE=false
GENERATE_REPORT=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --detailed)
            DETAILED=true
            shift
            ;;
        --logs)
            SHOW_LOGS=true
            shift
            ;;
        --fix-failed)
            FIX_FAILED=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

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
# Main Diagnostic Functions
###############################################################################

check_prerequisites() {
    print_section "Checking Prerequisites"
    
    # Check Docker
    if command -v docker &> /dev/null; then
        print_success "Docker is installed"
    else
        print_error "Docker is not installed"
        exit 1
    fi
    
    # Check containers
    if check_container "$CONTAINER_NAME"; then
        print_success "Backend container is running"
    else
        print_error "Backend container is not running"
        exit 1
    fi
    
    if check_container "$DB_CONTAINER"; then
        print_success "Database container is running"
    else
        print_error "Database container is not running"
        exit 1
    fi
}

check_supervisor_status() {
    print_section "Supervisor & Queue Workers Status"
    
    echo -e "${BOLD}Supervisor Status:${NC}"
    docker exec "$CONTAINER_NAME" supervisorctl status | while read line; do
        if echo "$line" | grep -q "RUNNING"; then
            echo -e "  ${GREEN}●${NC} $line"
        elif echo "$line" | grep -q "STOPPED"; then
            echo -e "  ${RED}●${NC} $line"
        elif echo "$line" | grep -q "FATAL"; then
            echo -e "  ${RED}✗${NC} $line"
        else
            echo -e "  ${YELLOW}●${NC} $line"
        fi
    done
    
    echo ""
    WORKER_COUNT=$(docker exec "$CONTAINER_NAME" supervisorctl status | grep -c "RUNNING" || true)
    print_info "Total running workers: ${BOLD}$WORKER_COUNT${NC}"
}

check_queue_sizes() {
    print_section "Queue Sizes & Status"
    
    # Get queue statistics from database
    QUEUE_STATS=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT 
            queue,
            COUNT(*) as pending,
            COUNT(CASE WHEN reserved_at IS NOT NULL THEN 1 END) as reserved,
            COUNT(CASE WHEN reserved_at IS NULL THEN 1 END) as available
        FROM jobs 
        GROUP BY queue
        ORDER BY pending DESC;
    ")
    
    if [ -z "$QUEUE_STATS" ]; then
        print_success "All queues are empty (no pending jobs)"
    else
        echo -e "${BOLD}Queue Name${NC}              ${BOLD}Pending${NC}  ${BOLD}Reserved${NC}  ${BOLD}Available${NC}"
        echo "────────────────────────────────────────────────────"
        echo "$QUEUE_STATS" | while IFS='|' read -r queue pending reserved available; do
            queue=$(echo "$queue" | xargs)
            pending=$(echo "$pending" | xargs)
            reserved=$(echo "$reserved" | xargs)
            available=$(echo "$available" | xargs)
            
            if [ -n "$queue" ]; then
                if [ "$pending" -gt 100 ]; then
                    echo -e "${RED}${queue}${NC}" | awk '{printf "%-25s", $0}'
                    echo -e "${RED}${pending}${NC}" | awk '{printf "%8s", $0}'
                elif [ "$pending" -gt 10 ]; then
                    echo -e "${YELLOW}${queue}${NC}" | awk '{printf "%-25s", $0}'
                    echo -e "${YELLOW}${pending}${NC}" | awk '{printf "%8s", $0}'
                else
                    echo -e "${queue}" | awk '{printf "%-25s", $0}'
                    echo -e "${pending}" | awk '{printf "%8s", $0}'
                fi
                echo -e "${reserved}" | awk '{printf "%10s", $0}'
                echo -e "${available}" | awk '{printf "%11s", $0}'
                echo ""
            fi
        done
    fi
}

check_failed_jobs() {
    print_section "Failed Jobs Analysis"
    
    # Get failed job counts by queue
    FAILED_STATS=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT 
            queue,
            COUNT(*) as count,
            MAX(failed_at) as last_failure
        FROM failed_jobs 
        GROUP BY queue
        ORDER BY count DESC;
    ")
    
    TOTAL_FAILED=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT COUNT(*) FROM failed_jobs;")
    TOTAL_FAILED=$(echo "$TOTAL_FAILED" | xargs)
    
    if [ "$TOTAL_FAILED" -eq 0 ]; then
        print_success "No failed jobs found"
    else
        print_warning "Total failed jobs: ${BOLD}${TOTAL_FAILED}${NC}"
        echo ""
        echo -e "${BOLD}Queue Name${NC}              ${BOLD}Failed Count${NC}  ${BOLD}Last Failure${NC}"
        echo "────────────────────────────────────────────────────────────────"
        echo "$FAILED_STATS" | while IFS='|' read -r queue count last_failure; do
            queue=$(echo "$queue" | xargs)
            count=$(echo "$count" | xargs)
            last_failure=$(echo "$last_failure" | xargs)
            
            if [ -n "$queue" ]; then
                if [ "$count" -gt 100 ]; then
                    echo -e "${RED}${queue}${NC}" | awk '{printf "%-25s", $0}'
                    echo -e "${RED}${count}${NC}" | awk '{printf "%13s", $0}'
                elif [ "$count" -gt 10 ]; then
                    echo -e "${YELLOW}${queue}${NC}" | awk '{printf "%-25s", $0}'
                    echo -e "${YELLOW}${count}${NC}" | awk '{printf "%13s", $0}'
                else
                    echo -e "${queue}" | awk '{printf "%-25s", $0}'
                    echo -e "${count}" | awk '{printf "%13s", $0}'
                fi
                echo -e "  ${last_failure}"
            fi
        done
    fi
    
    # Show recent failed jobs if detailed mode
    if [ "$DETAILED" = true ] && [ "$TOTAL_FAILED" -gt 0 ]; then
        echo ""
        print_info "Recent failed jobs (last 5):"
        docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c "
            SELECT 
                LEFT(uuid, 8) as id,
                queue,
                LEFT(connection, 15) as conn,
                failed_at
            FROM failed_jobs 
            ORDER BY failed_at DESC 
            LIMIT 5;
        "
    fi
}

check_job_throughput() {
    print_section "Job Processing Metrics"
    
    # Calculate job processing rate (jobs per minute)
    echo -e "${BOLD}Recent Job Activity (last hour):${NC}"
    
    RECENT_JOBS=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) 
        FROM failed_jobs 
        WHERE failed_at > NOW() - INTERVAL '1 hour';
    ")
    RECENT_JOBS=$(echo "$RECENT_JOBS" | xargs)
    
    echo "  Failed in last hour: ${RECENT_JOBS}"
    
    # Check for stuck jobs (reserved for too long)
    STUCK_JOBS=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) 
        FROM jobs 
        WHERE reserved_at IS NOT NULL 
        AND reserved_at < EXTRACT(EPOCH FROM NOW() - INTERVAL '10 minutes');
    ")
    STUCK_JOBS=$(echo "$STUCK_JOBS" | xargs)
    
    if [ "$STUCK_JOBS" -gt 0 ]; then
        print_warning "Potentially stuck jobs: ${BOLD}${STUCK_JOBS}${NC}"
    else
        print_success "No stuck jobs detected"
    fi
}

check_stuck_jobs() {
    print_section "Stuck Jobs Check"
    
    # Check if we can access the database container
    if ! docker ps --filter "name=^${DB_CONTAINER}$" --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
        print_error "Database container '$DB_CONTAINER' is not running. Cannot check for stuck jobs."
        return 1
    fi
    
    # Check if psql is available in the container
    if ! docker exec "$DB_CONTAINER" which psql >/dev/null 2>&1; then
        print_error "psql is not available in the database container. Cannot check for stuck jobs."
        return 1
    fi
    
    # Check for jobs that have been reserved for too long
    local query="
        SELECT COUNT(*) 
        FROM jobs 
        WHERE reserved_at IS NOT NULL 
        AND reserved_at < EXTRACT(EPOCH FROM NOW() - INTERVAL '10 minutes');
    "
    
    # Execute query and handle potential errors
    local stuck_jobs_str
    if ! stuck_jobs_str=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "$query" 2>&1); then
        print_error "Failed to query stuck jobs: $stuck_jobs_str"
        return 1
    fi
    
    # Clean up the output and handle potential errors
    local stuck_jobs
    stuck_jobs=$(echo "$stuck_jobs_str" | tr -d '[:space:]')
    stuck_jobs=${stuck_jobs:-0}
    
    # Ensure stuck_jobs is a number
    if ! [[ "$stuck_jobs" =~ ^[0-9]+$ ]]; then
        print_error "Unexpected result when checking for stuck jobs: $stuck_jobs_str"
        return 1
    fi
    
    if [ "$stuck_jobs" -gt 0 ]; then
        print_warning "Found $stuck_jobs jobs stuck in processing"
        
        if [ "$DETAILED" = true ]; then
            echo -e "\n${YELLOW}Stuck Jobs:${NC}"
            
            # Use a simpler query that's more likely to work in all PostgreSQL versions
            local detail_query="
                SELECT 
                    id, 
                    queue, 
                    attempts, 
                    to_char(to_timestamp(reserved_at), 'YYYY-MM-DD HH24:MI:SS') as reserved_since,
                    (EXTRACT(EPOCH FROM (NOW() - to_timestamp(reserved_at)))/60)::int || ' minutes' as stuck_duration
                FROM jobs 
                WHERE reserved_at IS NOT NULL 
                AND reserved_at < EXTRACT(EPOCH FROM NOW() - INTERVAL '10 minutes')
                ORDER BY reserved_at ASC
                LIMIT 5;
            "
            
            # Execute detailed query with error handling
            local result
            if ! result=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -P pager=off -P border=2 -H -c "$detail_query" 2>&1); then
                print_error "Failed to get stuck job details: $result"
            else
                # Clean up the output
                echo "$result" | grep -v "^$" | sed 's/^/  /'
                
                # Show count if there are more stuck jobs
                if [ "$stuck_jobs" -gt 5 ]; then
                    echo -e "\n  ... and $((stuck_jobs - 5)) more stuck jobs"
                fi
            fi
        fi
        
        # Add recommendation
        echo -e "\n${YELLOW}Recommendation:${NC}"
        echo "  - Check the queue workers with: docker exec $CONTAINER_NAME supervisorctl status"
        echo "  - Restart stuck workers: docker exec $CONTAINER_NAME supervisorctl restart laravel-queue:*"
        echo "  - If the problem persists, you may need to manually release stuck jobs"
        
        # If in interactive mode, offer to restart the workers
        if [ "$INTERACTIVE" = true ]; then
            echo -e "\n${YELLOW}Would you like to restart the queue workers? [y/N]${NC} "
            read -r response
            if [[ "$response" =~ ^[Yy]$ ]]; then
                echo -n "Restarting queue workers... "
                if docker exec "$CONTAINER_NAME" supervisorctl restart laravel-queue:* >/dev/null 2>&1; then
                    echo -e "${GREEN}Done${NC}"
                else
                    echo -e "${RED}Failed${NC}"
                fi
            fi
        fi
    else
        print_success "No stuck jobs found"
    fi
}

check_queue_logs() {
    print_section "Queue Log Analysis"
    
    LOG_PATH="/var/www/html/storage/logs"
    
    # Check each queue log file
    QUEUE_LOGS=(
        "default-queue.log"
        "payments-queue.log"
        "provisioning-queue.log"
        "router-checks-queue.log"
        "router-data-queue.log"
        "hotspot-sms-queue.log"
        "hotspot-sessions-queue.log"
        "hotspot-accounting-queue.log"
        "dashboard-queue.log"
        "log-rotation-queue.log"
    )
    
    echo -e "${BOLD}Log File Status:${NC}"
    for log in "${QUEUE_LOGS[@]}"; do
        # Get file size in bytes
        SIZE_STR=$(docker exec "$CONTAINER_NAME" stat -c%s "$LOG_PATH/$log" 2>/dev/null || echo "0")
        SIZE=${SIZE_STR:-0}
        
        # Convert to MB (integer division)
        SIZE_KB=$((SIZE / 1024))
        SIZE_MB=$((SIZE_KB / 1024))
        
        # Calculate decimal part (first decimal place)
        DECIMAL=$(( (SIZE_KB % 1024) / 100 ))
        
        if [ "$SIZE" -eq 0 ]; then
            echo -e "  ${YELLOW}○${NC} $log - ${YELLOW}Empty${NC}"
        elif [ "$SIZE_MB" -gt 5 ]; then
            echo -e "  ${RED}●${NC} $log - ${RED}${SIZE_MB}.${DECIMAL} MB${NC}"
        else
            echo -e "  ${GREEN}●${NC} $log - ${SIZE_MB}.${DECIMAL} MB"
        fi
        
        # If logs flag is set, show recent errors
        if [ "$SHOW_LOGS" = true ]; then
            local error_count=$(docker exec "$CONTAINER_NAME" grep -c -i "error\|exception\|fatal\|failed" "$LOG_PATH/$log" 2>/dev/null || echo "0")
            if [ "$error_count" -gt 0 ]; then
                echo -e "    ${YELLOW}⚠ Found $error_count errors in $log${NC}"
                if [ "$DETAILED" = true ]; then
                    echo -e "    ${YELLOW}Last 3 errors:${NC}"
                    docker exec "$CONTAINER_NAME" grep -i "error\|exception\|fatal\|failed" "$LOG_PATH/$log" | tail -3 | sed 's/^/      /'
                fi
            fi
        fi
    done
    
    if [ "$SHOW_LOGS" = true ]; then
        echo ""
        print_info "Recent errors from laravel.log:"
        docker exec "$CONTAINER_NAME" tail -n 50 "$LOG_PATH/laravel.log" | grep -i "error\|exception\|fatal" | tail -10 || echo "  No recent errors"
        
        echo ""
        print_info "Recent log rotation errors:"
        docker exec "$CONTAINER_NAME" grep -i "rotatelog\|chown\|chgrp\|permission" "$LOG_PATH/laravel.log" 2>/dev/null | tail -5 || echo "  No log rotation errors found"
    fi
}

check_database_health() {
    print_section "Database Health Check"
    
    # Check database connection
    if docker exec "$DB_CONTAINER" pg_isready -U "$DB_USER" -d "$DB_NAME" &> /dev/null; then
        print_success "Database connection is healthy"
    else
        print_error "Database connection failed"
        return 1
    fi
    
    # Check table sizes
    echo ""
    echo -e "${BOLD}Queue Table Statistics:${NC}"
    docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c "
        SELECT 
            'jobs' as table_name,
            pg_size_pretty(pg_total_relation_size('jobs')) as size,
            (SELECT COUNT(*) FROM jobs) as row_count
        UNION ALL
        SELECT 
            'failed_jobs' as table_name,
            pg_size_pretty(pg_total_relation_size('failed_jobs')) as size,
            (SELECT COUNT(*) FROM failed_jobs) as row_count;
    "
}

analyze_common_issues() {
    print_section "Common Issues Detection"
    
    ISSUES_FOUND=0
    
    # Check for RotateLogs failures
    ROTATE_FAILURES=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM failed_jobs WHERE queue = 'log-rotation';
    ")
    ROTATE_FAILURES=$(echo "$ROTATE_FAILURES" | xargs)
    
    if [ "$ROTATE_FAILURES" -gt 0 ]; then
        print_warning "RotateLogs job failures detected: ${ROTATE_FAILURES}"
        print_info "  Issue: Permission errors with chown/chgrp operations"
        print_info "  Impact: Low - Log rotation not critical for queue operation"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Check for payment processing issues
    PAYMENT_FAILURES=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM failed_jobs WHERE queue = 'payments' AND failed_at > NOW() - INTERVAL '1 hour';
    ")
    PAYMENT_FAILURES=$(echo "$PAYMENT_FAILURES" | xargs)
    
    if [ "$PAYMENT_FAILURES" -gt 0 ]; then
        print_error "Recent payment processing failures: ${PAYMENT_FAILURES}"
        print_info "  Impact: HIGH - Affects customer payments"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Check for provisioning issues
    PROVISION_FAILURES=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM failed_jobs WHERE queue = 'provisioning' AND failed_at > NOW() - INTERVAL '1 hour';
    ")
    PROVISION_FAILURES=$(echo "$PROVISION_FAILURES" | xargs)
    
    if [ "$PROVISION_FAILURES" -gt 0 ]; then
        print_error "Recent provisioning failures: ${PROVISION_FAILURES}"
        print_info "  Impact: HIGH - Affects user activation"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    if [ "$ISSUES_FOUND" -eq 0 ]; then
        print_success "No critical issues detected"
    fi
}

show_recommendations() {
    print_section "Recommendations"
    
    TOTAL_FAILED=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT COUNT(*) FROM failed_jobs;")
    TOTAL_FAILED=$(echo "$TOTAL_FAILED" | xargs)
    
    if [ "$TOTAL_FAILED" -gt 100 ]; then
        echo "1. Clear old failed jobs:"
        echo "   docker exec $CONTAINER_NAME php artisan queue:flush"
        echo ""
    fi
    
    echo "2. Restart queue workers:"
    echo "   docker exec $CONTAINER_NAME supervisorctl restart laravel-queues:*"
    echo ""
    
    echo "3. Monitor queue in real-time:"
    echo "   docker exec $CONTAINER_NAME php artisan queue:monitor database:default,database:payments,database:provisioning --max=100"
    echo ""
    
    echo "4. View specific queue logs:"
    echo "   docker exec $CONTAINER_NAME tail -f /var/www/html/storage/logs/payments-queue.log"
    echo ""
    
    if [ "$TOTAL_FAILED" -gt 0 ]; then
        echo "5. Retry failed jobs:"
        echo "   docker exec $CONTAINER_NAME php artisan queue:retry all"
        echo ""
    fi
}

fix_common_issues() {
    print_section "Fixing Common Issues"
    
    print_info "Clearing log-rotation failed jobs..."
    docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c "DELETE FROM failed_jobs WHERE queue = 'log-rotation';"
    print_success "Cleared log-rotation failed jobs"
    
    print_info "Restarting queue workers..."
    docker exec "$CONTAINER_NAME" supervisorctl restart laravel-queues:*
    sleep 2
    print_success "Queue workers restarted"
    
    print_info "Checking worker status..."
    docker exec "$CONTAINER_NAME" supervisorctl status | grep "RUNNING" | wc -l
}

###############################################################################
# Main Execution
###############################################################################

main() {
    show_header
    
    # Initialize report file if report generation is enabled
    if [ "$GENERATE_REPORT" = true ]; then
        init_report
        log "INFO" "Starting queue diagnostics with report generation enabled"
    fi
    
    # Check if containers are running
    if ! check_container "$CONTAINER_NAME" || ! check_container "$DB_CONTAINER"; then
        log "ERROR" "Required containers are not running. Please start the application with 'docker-compose up -d'"
        exit 1
    fi
    
    # Run diagnostics
    log "INFO" "Starting queue diagnostics..."
    
    # Basic checks first
    check_prerequisites
    check_supervisor_status
    
    # Core diagnostics
    check_queue_sizes
    check_failed_jobs
    check_stuck_jobs
    check_job_throughput
    check_queue_logs
    
    # System health checks
    check_database_health
    
    # Analysis and recommendations
    analyze_common_issues
    show_recommendations
    
    # Fix common issues if requested
    if [ "$FIX_FAILED" = true ]; then
        log "INFO" "Attempting to fix common issues..."
        fix_common_issues
    fi
    
    # Final status
    log "INFO" "Diagnostics completed. Check the output above for any issues."
    
    if [ "$GENERATE_REPORT" = true ]; then
        log "INFO" "Detailed report generated at: $REPORT_FILE"
    fi
}
# Run main function
main
