#!/bin/bash
# Deploy WireGuard Controller Fix for Peer Persistence
# This script rebuilds and deploys the updated wireguard-controller container

set -e

echo "=== WireGuard Controller Deployment ==="
echo ""

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Check if running on production server
if [ ! -f ".env.production" ]; then
    echo -e "${RED}Error: .env.production not found${NC}"
    echo "This script must be run from the project root directory"
    exit 1
fi

echo -e "${YELLOW}Step 1: Building wireguard-controller image...${NC}"
docker-compose -f docker-compose.production.yml build wireguard-controller

if [ $? -ne 0 ]; then
    echo -e "${RED}Failed to build wireguard-controller image${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Build successful${NC}"
echo ""

echo -e "${YELLOW}Step 2: Stopping wireguard-controller container...${NC}"
docker-compose -f docker-compose.production.yml stop wireguard-controller

echo -e "${GREEN}✓ Container stopped${NC}"
echo ""

echo -e "${YELLOW}Step 3: Backing up current WireGuard configuration...${NC}"
# Backup is done automatically by the container mounting /etc/wireguard
echo -e "${GREEN}✓ Configuration is persisted in /etc/wireguard on host${NC}"
echo ""

echo -e "${YELLOW}Step 4: Starting updated wireguard-controller container...${NC}"
docker-compose -f docker-compose.production.yml up -d wireguard-controller

if [ $? -ne 0 ]; then
    echo -e "${RED}Failed to start wireguard-controller container${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Container started${NC}"
echo ""

echo -e "${YELLOW}Step 5: Waiting for container to initialize...${NC}"
sleep 5

echo -e "${YELLOW}Step 6: Checking container status...${NC}"
docker-compose -f docker-compose.production.yml ps wireguard-controller

echo ""
echo -e "${YELLOW}Step 7: Checking container logs...${NC}"
docker-compose -f docker-compose.production.yml logs --tail=20 wireguard-controller

echo ""
echo -e "${GREEN}=== Deployment Complete ===${NC}"
echo ""
echo -e "${YELLOW}What was fixed:${NC}"
echo "  - Peers are now manually persisted to /etc/wireguard/wg0.conf"
echo "  - Peers will survive container restarts and VPS reboots"
echo "  - Both add and remove operations update the config file"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Test by adding a new router with VPN"
echo "  2. Verify peer appears in /etc/wireguard/wg0.conf"
echo "  3. Reboot the VPS to confirm persistence"
echo "  4. Check that all peers reconnect after reboot"
echo ""
echo -e "${YELLOW}To verify peers in config file:${NC}"
echo "  docker exec wireguard-controller cat /etc/wireguard/wg0.conf"
echo ""
echo -e "${YELLOW}To check running WireGuard status:${NC}"
echo "  docker exec wireguard-controller wg show wg0"
echo ""
