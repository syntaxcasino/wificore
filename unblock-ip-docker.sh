#!/bin/bash

# Unblock IP Script for Docker Environment
# This script unblocks IPs in a Dockerized application

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to validate IP address
validate_ip() {
    local ip=$1
    if [[ $ip =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
        return 0
    else
        return 1
    fi
}

# Function to unblock IP in nginx container
unblock_nginx() {
    local ip=$1
    
    print_info "Checking nginx container for IP blocks..."
    
    # Check if nginx container exists
    if ! docker ps --format '{{.Names}}' | grep -q "traidnet-nginx"; then
        print_warning "nginx container not found"
        return 1
    fi
    
    # Check nginx configuration for IP blocks
    print_info "Checking nginx deny rules..."
    docker exec traidnet-nginx grep -r "deny $ip" /etc/nginx/ 2>/dev/null || print_info "No deny rules found for $ip"
    
    # Check nginx access logs
    print_info "Recent nginx access attempts from $ip:"
    docker exec traidnet-nginx tail -n 20 /var/log/nginx/access.log 2>/dev/null | grep "$ip" || print_info "No recent access from $ip"
}

# Function to unblock IP in fail2ban (if running in container)
unblock_fail2ban_docker() {
    local ip=$1
    
    print_info "Checking for fail2ban in containers..."
    
    # Check if fail2ban is running in any container
    for container in $(docker ps --format '{{.Names}}'); do
        if docker exec "$container" which fail2ban-client &>/dev/null; then
            print_info "Found fail2ban in container: $container"
            
            # Get jails
            jails=$(docker exec "$container" fail2ban-client status | grep "Jail list" | sed -E 's/^[^:]+:[ \t]+//' | sed 's/,//g')
            
            for jail in $jails; do
                if docker exec "$container" fail2ban-client status "$jail" | grep -q "$ip"; then
                    print_info "Unblocking $ip from jail: $jail in container: $container"
                    docker exec "$container" fail2ban-client set "$jail" unbanip "$ip"
                    print_success "IP unblocked from $jail"
                fi
            done
        fi
    done
}

# Function to check and unblock from host iptables
unblock_host_iptables() {
    local ip=$1
    
    if [ "$EUID" -ne 0 ]; then 
        print_warning "Not running as root, skipping iptables check"
        return 1
    fi
    
    print_info "Checking host iptables for IP: $ip"
    
    if iptables -L -n | grep -q "$ip"; then
        print_info "Found IP in iptables, removing..."
        
        # Remove from various chains
        iptables -D INPUT -s "$ip" -j DROP 2>/dev/null || true
        iptables -D INPUT -s "$ip" -j REJECT 2>/dev/null || true
        iptables -D DOCKER-USER -s "$ip" -j DROP 2>/dev/null || true
        iptables -D DOCKER-USER -s "$ip" -j REJECT 2>/dev/null || true
        
        print_success "IP removed from iptables"
    else
        print_info "IP not found in iptables"
    fi
}

# Function to clear Laravel rate limiting cache
clear_laravel_rate_limit() {
    local ip=$1
    
    print_info "Clearing Laravel rate limiting cache for IP: $ip"
    
    if ! docker ps --format '{{.Names}}' | grep -q "traidnet-backend"; then
        print_warning "Backend container not found"
        return 1
    fi
    
    # Clear rate limiting cache keys
    print_info "Clearing rate limit cache keys..."
    docker exec traidnet-backend php artisan tinker --execute="
        \$keys = Cache::get('illuminate:cache:*');
        \$pattern = '*rate-limit*$ip*';
        foreach (Cache::getRedis()->keys(\$pattern) as \$key) {
            Cache::forget(str_replace('laravel_cache:', '', \$key));
            echo 'Cleared: ' . \$key . PHP_EOL;
        }
        echo 'Rate limit cache cleared for IP: $ip' . PHP_EOL;
    " 2>/dev/null || print_warning "Could not clear cache via tinker"
    
    # Alternative: flush all rate limit cache
    print_info "Flushing all rate limit cache..."
    docker exec traidnet-backend php artisan cache:forget "rate-limit:*" 2>/dev/null || true
    
    print_success "Laravel rate limit cache cleared"
}

# Function to check Redis for IP blocks
check_redis_blocks() {
    local ip=$1
    
    print_info "Checking Redis for IP-related keys..."
    
    if ! docker ps --format '{{.Names}}' | grep -q "traidnet-redis"; then
        print_warning "Redis container not found"
        return 1
    fi
    
    # Search for IP-related keys in Redis
    print_info "Searching Redis keys for IP: $ip"
    docker exec traidnet-redis redis-cli KEYS "*$ip*" 2>/dev/null || print_info "No Redis keys found for $ip"
    
    # Check for rate limit keys
    print_info "Checking rate limit keys..."
    docker exec traidnet-redis redis-cli KEYS "*rate-limit*" 2>/dev/null | head -10 || true
}

# Function to list blocked IPs
list_blocked_ips() {
    echo ""
    print_info "=== Checking for blocked IPs ==="
    echo ""
    
    # Check host iptables
    if [ "$EUID" -eq 0 ]; then
        print_info "Host iptables blocked IPs:"
        iptables -L INPUT -n --line-numbers | grep -E "DROP|REJECT" | grep -oE "\b([0-9]{1,3}\.){3}[0-9]{1,3}\b" | sort -u || print_info "None found"
        echo ""
    fi
    
    # Check nginx logs for 403 errors
    print_info "Recent 403 errors in nginx (last 20):"
    docker exec traidnet-nginx tail -n 100 /var/log/nginx/access.log 2>/dev/null | grep " 403 " | tail -20 || print_info "None found"
    echo ""
    
    # Check Laravel logs for rate limiting
    print_info "Recent rate limit events in Laravel:"
    docker exec traidnet-backend tail -n 100 /var/www/html/storage/logs/laravel.log 2>/dev/null | grep -i "rate" | tail -10 || print_info "None found"
    echo ""
}

# Main script
main() {
    echo ""
    echo "========================================="
    echo "   Docker IP Unblock Utility Script     "
    echo "========================================="
    echo ""
    
    # Check if IP argument is provided
    if [ $# -eq 0 ]; then
        print_info "Usage: $0 <IP_ADDRESS>"
        print_info "   or: $0 list    (to list blocked IPs)"
        echo ""
        print_info "Example: $0 192.168.1.100"
        echo ""
        
        read -p "Do you want to list blocked IPs? (y/n): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            list_blocked_ips
        fi
        exit 0
    fi
    
    # Check if user wants to list blocked IPs
    if [ "$1" = "list" ] || [ "$1" = "-l" ] || [ "$1" = "--list" ]; then
        list_blocked_ips
        exit 0
    fi
    
    IP_ADDRESS=$1
    
    # Validate IP address
    if ! validate_ip "$IP_ADDRESS"; then
        print_error "Invalid IP address format: $IP_ADDRESS"
        exit 1
    fi
    
    print_info "Starting unblock process for IP: $IP_ADDRESS"
    echo ""
    
    # Check nginx container
    unblock_nginx "$IP_ADDRESS"
    echo ""
    
    # Check fail2ban in containers
    unblock_fail2ban_docker "$IP_ADDRESS"
    echo ""
    
    # Check host iptables
    unblock_host_iptables "$IP_ADDRESS"
    echo ""
    
    # Clear Laravel rate limiting
    clear_laravel_rate_limit "$IP_ADDRESS"
    echo ""
    
    # Check Redis
    check_redis_blocks "$IP_ADDRESS"
    echo ""
    
    print_success "Unblock process completed for IP: $IP_ADDRESS"
    echo ""
    
    print_info "Additional steps you can take:"
    echo "  1. Restart nginx: docker restart traidnet-nginx"
    echo "  2. Clear all Laravel cache: docker exec traidnet-backend php artisan cache:clear"
    echo "  3. Check logs: docker logs traidnet-nginx --tail 50"
    echo ""
}

# Run main function
main "$@"
