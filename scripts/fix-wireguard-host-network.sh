#!/bin/bash
set -e

echo "=========================================="
echo "WireGuard Host Network Fix Script"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Step 1: Checking current WireGuard container configuration${NC}"
echo "---"
CONTAINER_ID=$(docker ps -aq -f name=wificore-wireguard)
if [ -z "$CONTAINER_ID" ]; then
    echo -e "${RED}ERROR: WireGuard container not found!${NC}"
    exit 1
fi

echo "Container ID: $CONTAINER_ID"
echo ""

# Check network mode
NETWORK_MODE=$(docker inspect $CONTAINER_ID --format='{{.HostConfig.NetworkMode}}')
echo "Current Network Mode: $NETWORK_MODE"

if [ "$NETWORK_MODE" != "host" ]; then
    echo -e "${RED}WARNING: Container is NOT using host network mode!${NC}"
    echo "This is the problem - the container needs to be recreated."
    echo ""
else
    echo -e "${GREEN}✓ Container is using host network mode${NC}"
    echo ""
fi

echo -e "${YELLOW}Step 2: Checking if wg0 exists inside container${NC}"
echo "---"
docker exec wificore-wireguard ip link show wg0 2>/dev/null && echo -e "${GREEN}✓ wg0 exists inside container${NC}" || echo -e "${RED}✗ wg0 does NOT exist inside container${NC}"
echo ""

echo -e "${YELLOW}Step 3: Checking if wg0 exists on host${NC}"
echo "---"
ip link show wg0 2>/dev/null && echo -e "${GREEN}✓ wg0 exists on host${NC}" || echo -e "${RED}✗ wg0 does NOT exist on host${NC}"
echo ""

echo -e "${YELLOW}Step 4: Stopping and removing WireGuard container${NC}"
echo "---"
cd /opt/wificore
docker compose -f docker-compose.production.yml stop wificore-wireguard
docker compose -f docker-compose.production.yml rm -f wificore-wireguard
echo -e "${GREEN}✓ Container stopped and removed${NC}"
echo ""

echo -e "${YELLOW}Step 5: Recreating WireGuard container with host network${NC}"
echo "---"
docker compose -f docker-compose.production.yml up -d wificore-wireguard
echo -e "${GREEN}✓ Container recreated${NC}"
echo ""

echo -e "${YELLOW}Step 6: Waiting for container to start (15 seconds)${NC}"
echo "---"
sleep 15
echo -e "${GREEN}✓ Wait complete${NC}"
echo ""

echo -e "${YELLOW}Step 7: Verifying network mode${NC}"
echo "---"
CONTAINER_ID=$(docker ps -aq -f name=wificore-wireguard)
NETWORK_MODE=$(docker inspect $CONTAINER_ID --format='{{.HostConfig.NetworkMode}}')
echo "New Network Mode: $NETWORK_MODE"

if [ "$NETWORK_MODE" = "host" ]; then
    echo -e "${GREEN}✓ Container is now using host network mode!${NC}"
else
    echo -e "${RED}✗ ERROR: Container still not using host network mode${NC}"
    echo "Please check docker-compose.production.yml for syntax errors"
    exit 1
fi
echo ""

echo -e "${YELLOW}Step 8: Checking WireGuard controller logs${NC}"
echo "---"
docker compose -f docker-compose.production.yml logs wificore-wireguard --tail=20
echo ""

echo -e "${YELLOW}Step 9: Testing WireGuard controller API${NC}"
echo "---"
curl -s http://localhost:8080/health | jq . || echo "API not responding or jq not installed"
echo ""

echo -e "${YELLOW}Step 10: Checking if wg0 exists on host now${NC}"
echo "---"
if ip link show wg0 2>/dev/null; then
    echo -e "${GREEN}✓ wg0 EXISTS on host!${NC}"
    echo ""
    echo "WireGuard interface details:"
    wg show wg0 || echo "wg command not available, but interface exists"
else
    echo -e "${YELLOW}⚠ wg0 does NOT exist yet${NC}"
    echo "This is normal - the interface will be created when you register the first tenant."
    echo ""
    echo "To trigger interface creation, you need to:"
    echo "1. Register a new tenant through the web interface"
    echo "2. Or manually call the WireGuard controller API"
fi
echo ""

echo "=========================================="
echo -e "${GREEN}Fix script completed!${NC}"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. If wg0 doesn't exist yet, create a tenant to trigger interface creation"
echo "2. After tenant creation, run: sudo wg show wg0"
echo "3. Check backend logs: docker compose -f docker-compose.production.yml logs wificore-backend --tail=50 | grep -i wireguard"
