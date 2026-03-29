#!/bin/bash
# Filter and show only deployment-critical logs from Laravel
# Usage: ./show-deployment-logs.sh [lines]
# Default shows last 500 lines, or use -f for follow mode

LINES=${1:-500}
LOG_FILE="storage/logs/laravel.log"

# Keywords that indicate deployment-critical logs
DEPLOYMENT_PATTERNS=(
    "Starting.*deployment"
    "Provisioning.*failed"
    "Provisioning.*error"
    "Deployment.*failed"
    "Deployment.*completed"
    "Router.*deployment"
    "applyConfigs.*failed"
    "SSH.*failed"
    "VPN.*failed"
    "VPN.*unhealthy"
    "Batch.*failed"
    "Configuration.*failed"
    "Validation.*failed"
    "Lock.*timeout"
    "Connectivity.*failed"
    "API.*failed"
    "REST API.*fallback"
    "SSH.*fallback"
    "Service deployment validation failed"
    "ProvisioningFailed"
    "ProvisioningProgress"
    "deployment-critical"
    "ERROR:"
    "CRITICAL:"
    "WARNING:.*deployment"
    "wireguard.*down"
    "VPN reconnection failed"
)

# Build grep pattern
PATTERN=""
for ((i=0; i<${#DEPLOYMENT_PATTERNS[@]}; i++)); do
    if [ $i -gt 0 ]; then
        PATTERN="${PATTERN}|"
    fi
    PATTERN="${PATTERN}${DEPLOYMENT_PATTERNS[$i]}"
done

# Check if running in Docker or locally
if [ -f "docker-compose.production.yml" ]; then
    # Production Docker environment
    if [ "$1" == "-f" ] || [ "$1" == "--follow" ]; then
        echo "Following deployment logs (Ctrl+C to exit)..."
        docker-compose -f docker-compose.production.yml exec -T wificore-backend tail -f "$LOG_FILE" | grep -E "$PATTERN"
    else
        echo "Showing last $LINES lines of deployment-critical logs..."
        docker-compose -f docker-compose.production.yml exec -T wificore-backend tail -n "$LINES" "$LOG_FILE" | grep -E "$PATTERN"
    fi
else
    # Local environment
    if [ "$1" == "-f" ] || [ "$1" == "--follow" ]; then
        echo "Following deployment logs (Ctrl+C to exit)..."
        tail -f "$LOG_FILE" | grep -E "$PATTERN"
    else
        echo "Showing last $LINES lines of deployment-critical logs..."
        tail -n "$LINES" "$LOG_FILE" | grep -E "$PATTERN"
    fi
fi
