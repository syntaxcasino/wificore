#!/bin/bash
set -e

echo "=========================================="
echo "Automated VPN Fix Deployment"
echo "=========================================="
echo ""

cd /opt/wificore

echo "Step 1: Pulling latest code..."
git pull origin main

echo ""
echo "Step 2: Rebuilding WireGuard controller with port fix..."
docker compose -f docker-compose.production.yml build --no-cache wificore-wireguard

echo ""
echo "Step 3: Rebuilding backend with key generation fix..."
docker compose -f docker-compose.production.yml build --no-cache wificore-backend

echo ""
echo "Step 4: Stopping WireGuard container..."
docker compose -f docker-compose.production.yml stop wificore-wireguard

echo ""
echo "Step 5: Removing old container and volume..."
docker compose -f docker-compose.production.yml rm -f wificore-wireguard
docker volume rm wificore_wireguard-config 2>/dev/null || echo "Volume already removed"

echo ""
echo "Step 6: Starting WireGuard with auto-fix entrypoint..."
docker compose -f docker-compose.production.yml up -d wificore-wireguard

echo ""
echo "Step 7: Restarting backend..."
docker compose -f docker-compose.production.yml restart wificore-backend

echo ""
echo "Waiting 15 seconds for services to initialize..."
sleep 15

echo ""
echo "=========================================="
echo "Verification"
echo "=========================================="
echo ""

echo "WireGuard interface status:"
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg show wg0 || echo "Failed to get status"

echo ""
echo "=========================================="
echo "Deployment Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Delete the current router via UI or API"
echo "2. Create a new router"
echo "3. Apply the fetch command on MikroTik"
echo "4. Test: /ping 10.8.0.1 count=4"
echo ""
echo "Expected: Port 51830, keys match, ping succeeds"
echo "=========================================="
