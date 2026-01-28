#!/bin/bash
# Rollback Network Isolation
# Removes firewall rules to restore direct SSH access

set -e

echo "=========================================="
echo "Removing Network Isolation"
echo "=========================================="
echo ""

# Backend Container
echo "1. Removing backend firewall rules..."
docker exec wificore-backend sh -c '
    iptables -F OUTPUT 2>/dev/null || true
    echo "  ✓ Backend firewall rules removed"
'

echo ""

# Provisioning Service Container
echo "2. Removing provisioning service firewall rules..."
docker exec wificore-provisioning sh -c '
    iptables -F OUTPUT 2>/dev/null || true
    echo "  ✓ Provisioning service firewall rules removed"
'

echo ""
echo "=========================================="
echo "Network Isolation Removed"
echo "=========================================="
echo ""
echo "Backend can now access routers directly again."
echo "Provisioning service still works but is not enforced."
echo ""
