#!/bin/bash

# Unblock IP Script
# This script unblocks IPs that have been blocked by fail2ban or iptables

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
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

# Function to check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then 
        print_error "This script must be run as root (use sudo)"
        exit 1
    fi
}

# Function to unblock IP from fail2ban
unblock_fail2ban() {
    local ip=$1
    
    if ! command -v fail2ban-client &> /dev/null; then
        print_warning "fail2ban is not installed"
        return 1
    fi
    
    print_info "Checking fail2ban jails for IP: $ip"
    
    # Get list of active jails
    jails=$(fail2ban-client status | grep "Jail list" | sed -E 's/^[^:]+:[ \t]+//' | sed 's/,//g')
    
    if [ -z "$jails" ]; then
        print_warning "No active fail2ban jails found"
        return 1
    fi
    
    local unblocked=false
    
    for jail in $jails; do
        # Check if IP is banned in this jail
        if fail2ban-client status "$jail" | grep -q "$ip"; then
            print_info "Unblocking $ip from jail: $jail"
            fail2ban-client set "$jail" unbanip "$ip"
            print_success "IP $ip unblocked from jail: $jail"
            unblocked=true
        fi
    done
    
    if [ "$unblocked" = false ]; then
        print_warning "IP $ip not found in any fail2ban jail"
    fi
}

# Function to unblock IP from iptables
unblock_iptables() {
    local ip=$1
    
    print_info "Checking iptables rules for IP: $ip"
    
    # Check if IP is blocked in iptables
    if iptables -L -n | grep -q "$ip"; then
        print_info "Found IP $ip in iptables, removing..."
        
        # Remove from INPUT chain
        iptables -D INPUT -s "$ip" -j DROP 2>/dev/null || true
        iptables -D INPUT -s "$ip" -j REJECT 2>/dev/null || true
        
        # Remove from FORWARD chain
        iptables -D FORWARD -s "$ip" -j DROP 2>/dev/null || true
        iptables -D FORWARD -s "$ip" -j REJECT 2>/dev/null || true
        
        print_success "IP $ip removed from iptables"
    else
        print_warning "IP $ip not found in iptables rules"
    fi
}

# Function to unblock IP from Docker iptables
unblock_docker_iptables() {
    local ip=$1
    
    print_info "Checking Docker iptables rules for IP: $ip"
    
    # Check DOCKER-USER chain
    if iptables -L DOCKER-USER -n 2>/dev/null | grep -q "$ip"; then
        print_info "Found IP $ip in DOCKER-USER chain, removing..."
        iptables -D DOCKER-USER -s "$ip" -j DROP 2>/dev/null || true
        iptables -D DOCKER-USER -s "$ip" -j REJECT 2>/dev/null || true
        print_success "IP $ip removed from DOCKER-USER chain"
    fi
}

# Function to check nginx/apache logs for the IP
check_web_logs() {
    local ip=$1
    
    print_info "Checking web server logs for IP: $ip"
    
    # Check nginx logs
    if [ -f "/var/log/nginx/access.log" ]; then
        local nginx_count=$(grep -c "$ip" /var/log/nginx/access.log 2>/dev/null || echo "0")
        print_info "Found $nginx_count entries in nginx access.log"
    fi
    
    # Check nginx error logs
    if [ -f "/var/log/nginx/error.log" ]; then
        local nginx_error_count=$(grep -c "$ip" /var/log/nginx/error.log 2>/dev/null || echo "0")
        print_info "Found $nginx_error_count entries in nginx error.log"
    fi
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

# Function to list all blocked IPs
list_blocked_ips() {
    echo ""
    print_info "=== Blocked IPs in fail2ban ==="
    
    if command -v fail2ban-client &> /dev/null; then
        jails=$(fail2ban-client status | grep "Jail list" | sed -E 's/^[^:]+:[ \t]+//' | sed 's/,//g')
        
        for jail in $jails; do
            echo ""
            print_info "Jail: $jail"
            fail2ban-client status "$jail" | grep "Banned IP list"
        done
    else
        print_warning "fail2ban not installed"
    fi
    
    echo ""
    print_info "=== Blocked IPs in iptables ==="
    iptables -L INPUT -n --line-numbers | grep -E "DROP|REJECT" | grep -oE "\b([0-9]{1,3}\.){3}[0-9]{1,3}\b" | sort -u
    
    echo ""
}

# Main script
main() {
    echo ""
    echo "========================================="
    echo "       IP Unblock Utility Script        "
    echo "========================================="
    echo ""
    
    # Check if running as root
    check_root
    
    # Check if IP argument is provided
    if [ $# -eq 0 ]; then
        print_info "Usage: $0 <IP_ADDRESS>"
        print_info "   or: $0 list    (to list all blocked IPs)"
        echo ""
        print_info "Example: $0 192.168.1.100"
        echo ""
        
        # Ask if user wants to list blocked IPs
        read -p "Do you want to list all blocked IPs? (y/n): " -n 1 -r
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
    
    # Check web logs first
    check_web_logs "$IP_ADDRESS"
    echo ""
    
    # Unblock from fail2ban
    unblock_fail2ban "$IP_ADDRESS"
    echo ""
    
    # Unblock from iptables
    unblock_iptables "$IP_ADDRESS"
    echo ""
    
    # Unblock from Docker iptables
    unblock_docker_iptables "$IP_ADDRESS"
    echo ""
    
    print_success "Unblock process completed for IP: $IP_ADDRESS"
    echo ""
    
    # Verify IP is unblocked
    print_info "Verifying IP is unblocked..."
    if iptables -L -n | grep -q "$IP_ADDRESS"; then
        print_warning "IP still appears in iptables rules"
    else
        print_success "IP successfully removed from iptables"
    fi
    
    echo ""
    print_info "You may need to restart services for changes to take full effect:"
    echo "  - sudo systemctl restart fail2ban"
    echo "  - sudo systemctl restart nginx"
    echo ""
}

# Run main function
main "$@"
