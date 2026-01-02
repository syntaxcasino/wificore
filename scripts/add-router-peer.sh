#!/bin/bash

# Add MikroTik Router Peer to WireGuard
# Usage: ./add-router-peer.sh <router_name> <router_public_key> <vpn_ip>

set -e

if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root or with sudo"
    exit 1
fi

if [ "$#" -ne 3 ]; then
    echo "Usage: $0 <router_name> <router_public_key> <vpn_ip>"
    echo "Example: $0 router1-nairobi ABC123...XYZ 10.10.10.2"
    exit 1
fi

ROUTER_NAME=$1
PUBLIC_KEY=$2
VPN_IP=$3

echo "Adding router peer: $ROUTER_NAME"
echo "Public Key: $PUBLIC_KEY"
echo "VPN IP: $VPN_IP"
echo ""

# Validate IP format
if ! [[ $VPN_IP =~ ^10\.10\.10\.[0-9]+$ ]]; then
    echo "Error: VPN IP must be in format 10.10.10.X"
    exit 1
fi

# Add peer to WireGuard config
cat >> /etc/wireguard/wg0.conf << EOF

# $ROUTER_NAME
[Peer]
PublicKey = $PUBLIC_KEY
AllowedIPs = $VPN_IP/32
PersistentKeepalive = 25

EOF

echo "Peer added to configuration"

# Reload WireGuard without disrupting existing connections
echo "Reloading WireGuard..."
wg syncconf wg0 <(wg-quick strip wg0)

echo ""
echo "=========================================="
echo "Router Peer Added Successfully!"
echo "=========================================="
echo ""
echo "Router: $ROUTER_NAME"
echo "VPN IP: $VPN_IP"
echo ""
echo "Current WireGuard Status:"
wg show wg0
echo ""
echo "=========================================="
echo "Next Steps:"
echo "1. Configure the MikroTik router with:"
echo "   - Server Public Key: $(cat /etc/wireguard/server_public.key)"
echo "   - Server Endpoint: YOUR_PUBLIC_IP:51820"
echo "   - Router VPN IP: $VPN_IP"
echo "2. Add router to FreeRADIUS clients.conf"
echo "3. Add router to database nas table"
echo "=========================================="
