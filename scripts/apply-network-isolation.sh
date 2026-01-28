#!/bin/bash
# Network Isolation Script for Hospital Production
# Implements network segmentation for security compliance
# 
# WARNING: Only run this after Phase 3 is fully validated
# Ensure USE_PROVISIONING_SERVICE=true and PROVISIONING_SERVICE_ROUTERS=all

set -e

echo "=========================================="
echo "Network Isolation for Hospital Production"
echo "=========================================="
echo ""

# Check if provisioning service is running
if ! docker ps | grep -q wificore-provisioning; then
    echo "ERROR: Provisioning service is not running!"
    echo "Start it with: docker compose -f docker-compose.production.yml up -d wificore-provisioning"
    exit 1
fi

# Check if provisioning service is healthy
if ! docker exec wificore-backend curl -sf http://wificore-provisioning:8080/health > /dev/null; then
    echo "ERROR: Provisioning service is not healthy!"
    echo "Check logs: docker logs wificore-provisioning"
    exit 1
fi

echo "✓ Provisioning service is running and healthy"
echo ""

# Confirm with user
read -p "Have you validated Phase 3 with all routers? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "Aborted. Complete Phase 3 validation first."
    exit 1
fi

echo ""
echo "Applying firewall rules..."
echo ""

# ============================================================================
# Backend Container: Block access to VPN subnets (routers)
# ============================================================================
echo "1. Configuring backend container firewall..."

docker exec wificore-backend sh -c '
    # Install iptables if not present
    if ! command -v iptables > /dev/null 2>&1; then
        echo "  Installing iptables..."
        apt-get update -qq && apt-get install -y -qq iptables > /dev/null 2>&1
    fi
    
    # Flush existing OUTPUT rules
    iptables -F OUTPUT 2>/dev/null || true
    
    # Block backend from accessing VPN subnets (routers)
    iptables -A OUTPUT -d 10.0.0.0/8 -j REJECT --reject-with icmp-host-unreachable
    
    # Allow backend to reach provisioning service
    iptables -A OUTPUT -d 172.70.0.30 -p tcp --dport 8080 -j ACCEPT
    
    # Allow backend to reach internal services
    iptables -A OUTPUT -d 172.70.0.0/16 -j ACCEPT
    
    # Allow loopback
    iptables -A OUTPUT -o lo -j ACCEPT
    
    # Allow established connections
    iptables -A OUTPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
    
    # Allow DNS
    iptables -A OUTPUT -p udp --dport 53 -j ACCEPT
    iptables -A OUTPUT -p tcp --dport 53 -j ACCEPT
    
    # Allow HTTPS for external APIs
    iptables -A OUTPUT -p tcp --dport 443 -j ACCEPT
    iptables -A OUTPUT -p tcp --dport 80 -j ACCEPT
    
    echo "  ✓ Backend firewall rules applied"
    echo "  Rules:"
    iptables -L OUTPUT -n -v | head -15
'

echo ""

# ============================================================================
# Provisioning Service Container: Allow VPN access only
# ============================================================================
echo "2. Configuring provisioning service firewall..."

docker exec wificore-provisioning sh -c '
    # Install iptables if not present
    if ! command -v iptables > /dev/null 2>&1; then
        echo "  Installing iptables..."
        apk add --no-cache iptables > /dev/null 2>&1
    fi
    
    # Flush existing OUTPUT rules
    iptables -F OUTPUT 2>/dev/null || true
    
    # Allow access to VPN subnets (routers)
    iptables -A OUTPUT -d 10.0.0.0/8 -j ACCEPT
    
    # Allow access to Docker network (backend, etc.)
    iptables -A OUTPUT -d 172.70.0.0/16 -j ACCEPT
    
    # Allow loopback
    iptables -A OUTPUT -o lo -j ACCEPT
    
    # Allow established connections
    iptables -A OUTPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
    
    # Allow DNS
    iptables -A OUTPUT -p udp --dport 53 -j ACCEPT
    
    echo "  ✓ Provisioning service firewall rules applied"
    echo "  Rules:"
    iptables -L OUTPUT -n -v | head -10
'

echo ""
echo "=========================================="
echo "Network Isolation Applied Successfully"
echo "=========================================="
echo ""

# ============================================================================
# Verification Tests
# ============================================================================
echo "Running verification tests..."
echo ""

echo "Test 1: Backend CANNOT reach router (10.1.1.1)"
if docker exec wificore-backend timeout 2 ping -c 1 10.1.1.1 2>/dev/null; then
    echo "  ✗ FAILED: Backend can still reach routers!"
    echo "  This is a security issue. Check firewall rules."
    exit 1
else
    echo "  ✓ PASSED: Backend blocked from routers"
fi

echo ""
echo "Test 2: Backend CAN reach provisioning service"
if docker exec wificore-backend curl -sf http://wificore-provisioning:8080/health > /dev/null; then
    echo "  ✓ PASSED: Backend can reach provisioning service"
else
    echo "  ✗ FAILED: Backend cannot reach provisioning service!"
    echo "  This will break router operations. Check firewall rules."
    exit 1
fi

echo ""
echo "Test 3: Provisioning service CAN reach router (if router exists)"
# This test is optional as it depends on router availability
echo "  ⊙ SKIPPED: Requires active router with VPN connection"

echo ""
echo "=========================================="
echo "Verification Complete"
echo "=========================================="
echo ""
echo "Network segmentation is now active!"
echo ""
echo "IMPORTANT: Monitor the following for 24 hours:"
echo "  - Backend logs: docker logs -f wificore-backend"
echo "  - Provisioning service logs: docker logs -f wificore-provisioning"
echo "  - Test all router operations in UI"
echo ""
echo "To rollback, run: ./scripts/remove-network-isolation.sh"
echo ""
