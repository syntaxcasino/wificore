#!/bin/bash

# WireGuard Setup Script for Hotspot Billing System
# Run as root or with sudo

set -e

echo "=========================================="
echo "WireGuard VPN Setup for RADIUS"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root or with sudo"
    exit 1
fi

# Detect network interface
INTERFACE=$(ip route | grep default | awk '{print $5}' | head -n1)
echo "Detected network interface: $INTERFACE"
echo ""

# Install WireGuard
echo "Installing WireGuard..."
apt update
apt install -y wireguard wireguard-tools

# Enable IP forwarding
echo "Enabling IP forwarding..."
sysctl -w net.ipv4.ip_forward=1
sysctl -w net.ipv6.conf.all.forwarding=1

# Make permanent
if ! grep -q "net.ipv4.ip_forward=1" /etc/sysctl.conf; then
    echo "net.ipv4.ip_forward=1" >> /etc/sysctl.conf
fi
if ! grep -q "net.ipv6.conf.all.forwarding=1" /etc/sysctl.conf; then
    echo "net.ipv6.conf.all.forwarding=1" >> /etc/sysctl.conf
fi

# Create WireGuard directory
mkdir -p /etc/wireguard
cd /etc/wireguard

# Generate server keys
echo "Generating WireGuard keys..."
wg genkey | tee server_private.key | wg pubkey | tee server_public.key
chmod 600 server_private.key
chmod 644 server_public.key

PRIVATE_KEY=$(cat server_private.key)
PUBLIC_KEY=$(cat server_public.key)

# Create WireGuard configuration
echo "Creating WireGuard configuration..."
cat > /etc/wireguard/wg0.conf << EOF
[Interface]
# Server private key
PrivateKey = $PRIVATE_KEY

# Server WireGuard IP
Address = 10.10.10.1/24

# WireGuard port
ListenPort = 51820

# Post-up: Enable routing and NAT
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT
PostUp = iptables -A FORWARD -o wg0 -j ACCEPT
PostUp = iptables -t nat -A POSTROUTING -o $INTERFACE -j MASQUERADE

# Post-down: Cleanup
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT
PostDown = iptables -D FORWARD -o wg0 -j ACCEPT
PostDown = iptables -t nat -D POSTROUTING -o $INTERFACE -j MASQUERADE

# ============================================================================
# MIKROTIK ROUTER PEERS
# ============================================================================
# Add router peers below using the format:
#
# [Peer]
# PublicKey = <ROUTER_PUBLIC_KEY>
# AllowedIPs = 10.10.10.X/32
# PersistentKeepalive = 25
#
# ============================================================================

EOF

chmod 600 /etc/wireguard/wg0.conf

# Configure firewall
echo "Configuring firewall..."
ufw allow 51820/udp comment "WireGuard VPN"
ufw allow from 10.10.10.0/24 to any port 1812 proto udp comment "RADIUS Auth"
ufw allow from 10.10.10.0/24 to any port 1813 proto udp comment "RADIUS Acct"
ufw reload

# Enable and start WireGuard
echo "Starting WireGuard service..."
systemctl enable wg-quick@wg0
systemctl start wg-quick@wg0

# Display status
echo ""
echo "=========================================="
echo "WireGuard Setup Complete!"
echo "=========================================="
echo ""
echo "Server Public Key:"
echo "$PUBLIC_KEY"
echo ""
echo "Server VPN IP: 10.10.10.1"
echo "WireGuard Port: 51820"
echo ""
echo "Service Status:"
systemctl status wg-quick@wg0 --no-pager
echo ""
echo "Interface Status:"
wg show wg0
echo ""
echo "=========================================="
echo "Next Steps:"
echo "1. Save the server public key above"
echo "2. Add router peers to /etc/wireguard/wg0.conf"
echo "3. Reload WireGuard: systemctl restart wg-quick@wg0"
echo "4. Configure FreeRADIUS clients"
echo "=========================================="
