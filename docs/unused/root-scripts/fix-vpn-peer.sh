#!/bin/bash
set -e

echo "==================================="
echo "Fixing VPN Peer Configuration"
echo "==================================="
echo ""

cd /opt/wificore

echo "1. Checking current WireGuard configuration..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg show
echo ""

echo "2. Removing wrong peer (y3bta8AzwieGPrZSLNXuRAMvd16HeO4rsItXLBNJuJM=)..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard sh -c "wg set wg0 peer y3bta8AzwieGPrZSLNXuRAMvd16HeO4rsItXLBNJuJM= remove || true"
echo "✓ Wrong peer removed"
echo ""

echo "3. Adding correct peer (qwDgX2KCyLsljgQ/i3VZdz/wAPhiqujuqRiA13MdBkM=)..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard sh -c "echo 'l7kYLUAKDxt5CVO6kr/8UQcO+70oUQBKvh034MTAt+4=' | wg set wg0 peer qwDgX2KCyLsljgQ/i3VZdz/wAPhiqujuqRiA13MdBkM= preshared-key /dev/stdin allowed-ips 10.100.1.1/32 persistent-keepalive 25"
echo "✓ Correct peer added"
echo ""

echo "4. Saving WireGuard configuration..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg-quick save wg0
echo "✓ Configuration saved"
echo ""

echo "5. Verifying new configuration..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg show
echo ""

echo "==================================="
echo "✅ VPN Peer Fixed Successfully"
echo "==================================="
echo ""
echo "Next steps:"
echo "1. On MikroTik, run: /ping 10.100.0.1 count=4"
echo "2. Check handshake: /interface wireguard peers print detail"
echo "3. Should see: rx=XXXX tx=XXXX (both non-zero)"
echo ""
