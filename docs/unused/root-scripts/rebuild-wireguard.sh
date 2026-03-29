#!/bin/bash
# Rebuild and deploy WireGuard Controller with persistence fix
# Run this on the production VPS

set -e

echo "=== Rebuilding WireGuard Controller ==="
echo ""

# Build the updated image
echo "Building wireguard-controller with updated code..."
docker compose -f docker-compose.production.yml build wificore-wireguard

# Stop the current container
echo "Stopping current container..."
docker compose -f docker-compose.production.yml stop wificore-wireguard

# Remove the old container
echo "Removing old container..."
docker compose -f docker-compose.production.yml rm -f wificore-wireguard

# Start the updated container
echo "Starting updated container..."
docker compose -f docker-compose.production.yml up -d wificore-wireguard

# Wait for container to be healthy
echo "Waiting for container to be healthy..."
sleep 10

# Check status
echo ""
echo "=== Container Status ==="
docker compose -f docker-compose.production.yml ps wificore-wireguard

echo ""
echo "=== Recent Logs ==="
docker compose -f docker-compose.production.yml logs --tail=30 wificore-wireguard

echo ""
echo "=== Verification ==="
echo "Checking if fix is deployed..."
if docker compose exec wificore-wireguard grep -q "def add_peer_to_config" /app/controller.py; then
    echo "✓ Fix is deployed successfully!"
else
    echo "✗ Fix not found - rebuild may have failed"
    exit 1
fi

echo ""
echo "=== Current WireGuard Config ==="
docker compose exec wificore-wireguard cat /etc/wireguard/wg0.conf

echo ""
echo "=== WireGuard Status ==="
docker compose exec wificore-wireguard wg show wg0

echo ""
echo "Deployment complete!"
echo ""
echo "Next steps:"
echo "1. Add a new router to test peer persistence"
echo "2. Verify peer appears in config: docker compose exec wificore-wireguard cat /etc/wireguard/wg0.conf"
echo "3. Reboot VPS to test persistence: sudo reboot"
echo "4. After reboot, check peers are still there: docker compose exec wificore-wireguard wg show wg0"
