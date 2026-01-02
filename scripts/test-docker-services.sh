#!/bin/bash

# Docker Services Health Check Test
# Tests all Docker containers health and connectivity

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "PASS")
            echo -e "${GREEN}✅ PASS${NC}: $message"
            ;;
        "FAIL")
            echo -e "${RED}❌ FAIL${NC}: $message"
            ;;
        "WARN")
            echo -e "${YELLOW}⚠️  WARN${NC}: $message"
            ;;
        "INFO")
            echo -e "${BLUE}ℹ️  INFO${NC}: $message"
            ;;
    esac
}

# Function to test container health
test_container_health() {
    local container_name=$1
    local service_name=$2

    if docker ps --format "table {{.Names}}" | grep -q "^${container_name}$"; then
        print_status "PASS" "$service_name container is running"

        # Test health check if available
        if docker inspect "$container_name" | grep -q '"Health"'; then
            local health_status=$(docker inspect "$container_name" --format='{{.State.Health.Status}}')
            if [ "$health_status" = "healthy" ]; then
                print_status "PASS" "$service_name health check passed"
            else
                print_status "FAIL" "$service_name health check failed (status: $health_status)"
                return 1
            fi
        fi
    else
        print_status "FAIL" "$service_name container is not running"
        return 1
    fi
}

# Function to test network connectivity
test_network_connectivity() {
    local container_name=$1
    local service_name=$2

    if docker exec "$container_name" ping -c 1 google.com > /dev/null 2>&1; then
        print_status "PASS" "$service_name has internet connectivity"
    else
        print_status "WARN" "$service_name has no internet connectivity"
    fi
}

# Function to test inter-container connectivity
test_inter_container_connectivity() {
    local source_container=$1
    local target_container=$2
    local target_ip=$3

    if docker exec "$source_container" ping -c 1 "$target_ip" > /dev/null 2>&1; then
        print_status "PASS" "Connectivity between $source_container and $target_container"
    else
        print_status "FAIL" "No connectivity between $source_container and $target_container"
        return 1
    fi
}

echo "🔍 Testing Docker Services Health..."
echo "==================================="

# Test all containers
SERVICES=(
    "traidnet-nginx:nginx"
    "traidnet-frontend:frontend"
    "traidnet-backend:backend"
    "traidnet-postgres:postgres"
    "traidnet-redis:redis"
    "traidnet-soketi:soketi"
    "traidnet-freeradius:freeradius"
)

ALL_HEALTHY=true

for service in "${SERVICES[@]}"; do
    container_name=$(echo $service | cut -d: -f1)
    service_name=$(echo $service | cut -d: -f2)

    if ! test_container_health "$container_name" "$service_name"; then
        ALL_HEALTHY=false
    fi
done

echo ""
echo "🌐 Testing Network Connectivity..."
echo "=================================="

# Test internet connectivity for key services
test_network_connectivity "traidnet-backend" "Backend"
test_network_connectivity "traidnet-frontend" "Frontend"

echo ""
echo "🔗 Testing Inter-Container Connectivity..."
echo "=========================================="

# Test connectivity between services (using network aliases)
if ! test_inter_container_connectivity "traidnet-backend" "postgres" "traidnet-postgres"; then
    ALL_HEALTHY=false
fi

if ! test_inter_container_connectivity "traidnet-backend" "redis" "traidnet-redis"; then
    ALL_HEALTHY=false
fi

if ! test_inter_container_connectivity "traidnet-backend" "soketi" "traidnet-soketi"; then
    ALL_HEALTHY=false
fi

if ! test_inter_container_connectivity "traidnet-backend" "freeradius" "traidnet-freeradius"; then
    ALL_HEALTHY=false
fi

echo ""
echo "💾 Testing Volume Mounts..."
echo "==========================="

# Test volume mounts for backend (Laravel storage)
if docker exec traidnet-backend test -d /var/www/html/storage/logs; then
    print_status "PASS" "Backend storage volume mounted correctly"
else
    print_status "FAIL" "Backend storage volume not mounted"
    ALL_HEALTHY=false
fi

# Test postgres data volume
if docker exec traidnet-postgres test -d /var/lib/postgresql/data; then
    print_status "PASS" "Postgres data volume mounted correctly"
else
    print_status "FAIL" "Postgres data volume not mounted"
    ALL_HEALTHY=false
fi

echo ""
if [ "$ALL_HEALTHY" = true ]; then
    print_status "PASS" "All Docker services health checks passed"
    exit 0
else
    print_status "FAIL" "Some Docker services health checks failed"
    exit 1
fi
