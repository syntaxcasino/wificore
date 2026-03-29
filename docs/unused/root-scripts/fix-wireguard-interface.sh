#!/bin/bash
set -e

echo "==================================="
echo "Fixing WireGuard Interface"
echo "==================================="
echo ""

cd /opt/wificore

echo "1. Stopping WireGuard interface wg0..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg-quick down wg0 || true
echo "✓ Interface stopped"
echo ""

echo "2. Removing old configuration..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard rm -f /etc/wireguard/wg0.conf
echo "✓ Old config removed"
echo ""

echo "3. Creating new configuration with correct keys and port..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard sh -c 'cat > /etc/wireguard/wg0.conf << EOF
[Interface]
Address = 10.8.0.1/24
ListenPort = 51830
PrivateKey = sBy8lokQfg4s9PXwstwzUCijJ9OB4B+M6GHfDrGCcmw=
PostUp = iptables -A FORWARD -i wg0 -o eth0 -j ACCEPT; iptables -A FORWARD -i wg0 -o wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE; ip route add 10.0.0.0/8 dev wg0; iptables -t nat -A PREROUTING -i wg0 -p udp --dport 1812 -j DNAT --to-destination 172.70.0.2:1812; iptables -t nat -A PREROUTING -i wg0 -p udp --dport 1813 -j DNAT --to-destination 172.70.0.2:1813
PostDown = iptables -D FORWARD -i wg0 -o eth0 -j ACCEPT; iptables -D FORWARD -i wg0 -o wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE; ip route del 10.0.0.0/8 dev wg0; iptables -t nat -D PREROUTING -i wg0 -p udp --dport 1812 -j DNAT --to-destination 172.70.0.2:1812; iptables -t nat -D PREROUTING -i wg0 -p udp --dport 1813 -j DNAT --to-destination 172.70.0.2:1813

# Peers will be added dynamically
EOF'
echo "✓ New config created"
echo ""

echo "4. Setting correct permissions..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard chmod 600 /etc/wireguard/wg0.conf
echo "✓ Permissions set"
echo ""

echo "5. Bringing up interface with correct configuration..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg-quick up wg0
echo "✓ Interface up"
echo ""

echo "6. Verifying configuration..."
docker compose -f docker-compose.production.yml exec -T wificore-wireguard wg show wg0
echo ""

echo "==================================="
echo "✅ WireGuard Interface Fixed"
echo "==================================="
echo ""
echo "Expected output:"
echo "  listening port: 51830"
echo "  public key: 79EZlTBo190wG9xH+5ebUzwRzWT1X5yaabiqOvanW0A="
echo ""
echo "Next: Delete router and create new one to get matching peer configuration"
echo ""
