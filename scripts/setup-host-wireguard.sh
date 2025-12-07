#!/bin/bash

# Host-Based WireGuard Setup for 100K+ Tenants
# This script sets up WireGuard on the host system (not in container)
# Supports 100,000+ tenant routers on a single interface

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}=== Host-Based WireGuard Setup for 100K+ Tenants ===${NC}"
echo ""

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}Error: This script must be run as root${NC}"
   echo "Usage: sudo bash setup-host-wireguard.sh"
   exit 1
fi

# Detect OS
if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS=$ID
else
    echo -e "${RED}Cannot detect OS${NC}"
    exit 1
fi

echo -e "${YELLOW}Detected OS: $OS${NC}"

# Install WireGuard
echo -e "${YELLOW}Installing WireGuard...${NC}"
case "$OS" in
    ubuntu|debian)
        apt update
        apt install -y wireguard wireguard-tools
        ;;
    centos|rhel|fedora)
        yum install -y epel-release
        yum install -y wireguard-tools
        ;;
    *)
        echo -e "${RED}Unsupported OS: $OS${NC}"
        exit 1
        ;;
esac

# Verify installation
if ! command -v wg &> /dev/null; then
    echo -e "${RED}WireGuard installation failed${NC}"
    exit 1
fi

echo -e "${GREEN}WireGuard installed successfully${NC}"

# Create WireGuard directory
mkdir -p /etc/wireguard
chmod 700 /etc/wireguard

# Generate server keys
echo -e "${YELLOW}Generating server keys...${NC}"
wg genkey | tee /etc/wireguard/server-private.key | wg pubkey > /etc/wireguard/server-public.key
chmod 600 /etc/wireguard/server-private.key
chmod 644 /etc/wireguard/server-public.key

SERVER_PRIVATE_KEY=$(cat /etc/wireguard/server-private.key)
SERVER_PUBLIC_KEY=$(cat /etc/wireguard/server-public.key)

echo -e "${GREEN}Server keys generated${NC}"
echo -e "Public Key: ${YELLOW}$SERVER_PUBLIC_KEY${NC}"

# Get server's public IP
echo -e "${YELLOW}Detecting server public IP...${NC}"
PUBLIC_IP=$(curl -s ifconfig.me || curl -s icanhazip.com || echo "UNKNOWN")
echo -e "Public IP: ${YELLOW}$PUBLIC_IP${NC}"

# Get primary network interface
PRIMARY_INTERFACE=$(ip route | grep default | awk '{print $5}' | head -n1)
echo -e "Primary Interface: ${YELLOW}$PRIMARY_INTERFACE${NC}"

# Create WireGuard configuration
echo -e "${YELLOW}Creating WireGuard configuration...${NC}"
cat > /etc/wireguard/wg0.conf << EOF
[Interface]
# Server configuration for 100K+ tenant routers
Address = 10.0.0.1/8
ListenPort = 51820
PrivateKey = $SERVER_PRIVATE_KEY

# Enable IP forwarding and NAT
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o $PRIMARY_INTERFACE -j MASQUERADE; ip6tables -A FORWARD -i wg0 -j ACCEPT; ip6tables -t nat -A POSTROUTING -o $PRIMARY_INTERFACE -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o $PRIMARY_INTERFACE -j MASQUERADE; ip6tables -D FORWARD -i wg0 -j ACCEPT; ip6tables -t nat -D POSTROUTING -o $PRIMARY_INTERFACE -j MASQUERADE

# Peers will be added dynamically via 'wg set' command
# Laravel backend will manage peer addition/removal
# No need to restart WireGuard when adding/removing peers
EOF

chmod 600 /etc/wireguard/wg0.conf

echo -e "${GREEN}WireGuard configuration created${NC}"

# Configure kernel parameters for 100K+ connections
echo -e "${YELLOW}Optimizing kernel parameters for 100K+ connections...${NC}"
cat >> /etc/sysctl.conf << EOF

# WireGuard optimization for 100K+ peers
net.ipv4.ip_forward = 1
net.ipv6.conf.all.forwarding = 1
net.core.netdev_max_backlog = 5000
net.core.rmem_max = 134217728
net.core.wmem_max = 134217728
net.ipv4.tcp_rmem = 4096 87380 67108864
net.ipv4.tcp_wmem = 4096 65536 67108864
net.ipv4.tcp_congestion_control = bbr
net.core.default_qdisc = fq
net.ipv4.tcp_mtu_probing = 1
net.ipv4.tcp_slow_start_after_idle = 0
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_max_syn_backlog = 8192
net.core.somaxconn = 8192
EOF

# Apply sysctl settings
sysctl -p

echo -e "${GREEN}Kernel parameters optimized${NC}"

# Configure firewall
echo -e "${YELLOW}Configuring firewall...${NC}"
if command -v ufw &> /dev/null; then
    ufw allow 51820/udp
    echo -e "${GREEN}UFW firewall configured${NC}"
elif command -v firewall-cmd &> /dev/null; then
    firewall-cmd --permanent --add-port=51820/udp
    firewall-cmd --reload
    echo -e "${GREEN}Firewalld configured${NC}"
else
    echo -e "${YELLOW}No firewall detected, skipping firewall configuration${NC}"
fi

# Start WireGuard
echo -e "${YELLOW}Starting WireGuard...${NC}"
wg-quick up wg0

# Enable WireGuard on boot
systemctl enable wg-quick@wg0

echo -e "${GREEN}WireGuard started and enabled on boot${NC}"

# Verify WireGuard is running
if wg show wg0 &> /dev/null; then
    echo -e "${GREEN}WireGuard is running successfully${NC}"
    wg show wg0
else
    echo -e "${RED}WireGuard failed to start${NC}"
    exit 1
fi

# Create helper scripts
echo -e "${YELLOW}Creating helper scripts...${NC}"

# Script to add peer
cat > /usr/local/bin/wg-add-peer << 'EOFSCRIPT'
#!/bin/bash
# Add peer to WireGuard
# Usage: wg-add-peer <public-key> <allowed-ip>

if [[ $# -ne 2 ]]; then
    echo "Usage: wg-add-peer <public-key> <allowed-ip>"
    exit 1
fi

PUBLIC_KEY=$1
ALLOWED_IP=$2

wg set wg0 peer "$PUBLIC_KEY" allowed-ips "$ALLOWED_IP/32" persistent-keepalive 25
wg-quick save wg0

echo "Peer added: $PUBLIC_KEY -> $ALLOWED_IP"
EOFSCRIPT

chmod +x /usr/local/bin/wg-add-peer

# Script to remove peer
cat > /usr/local/bin/wg-remove-peer << 'EOFSCRIPT'
#!/bin/bash
# Remove peer from WireGuard
# Usage: wg-remove-peer <public-key>

if [[ $# -ne 1 ]]; then
    echo "Usage: wg-remove-peer <public-key>"
    exit 1
fi

PUBLIC_KEY=$1

wg set wg0 peer "$PUBLIC_KEY" remove
wg-quick save wg0

echo "Peer removed: $PUBLIC_KEY"
EOFSCRIPT

chmod +x /usr/local/bin/wg-remove-peer

# Script to list peers
cat > /usr/local/bin/wg-list-peers << 'EOFSCRIPT'
#!/bin/bash
# List all WireGuard peers
wg show wg0 peers
EOFSCRIPT

chmod +x /usr/local/bin/wg-list-peers

echo -e "${GREEN}Helper scripts created:${NC}"
echo -e "  - ${YELLOW}/usr/local/bin/wg-add-peer${NC}"
echo -e "  - ${YELLOW}/usr/local/bin/wg-remove-peer${NC}"
echo -e "  - ${YELLOW}/usr/local/bin/wg-list-peers${NC}"

# Save configuration to file for Laravel
echo -e "${YELLOW}Saving configuration for Laravel...${NC}"
cat > /etc/wireguard/server-config.env << EOF
VPN_MODE=host
VPN_INTERFACE=wg0
VPN_SERVER_IP=10.0.0.1
VPN_SERVER_PORT=51820
VPN_SERVER_PUBLIC_KEY=$SERVER_PUBLIC_KEY
VPN_SERVER_PRIVATE_KEY=$SERVER_PRIVATE_KEY
VPN_SERVER_ENDPOINT=$PUBLIC_IP:51820
VPN_SUBNET_BASE=10.0.0.0/8
EOF

chmod 600 /etc/wireguard/server-config.env

echo -e "${GREEN}Configuration saved to /etc/wireguard/server-config.env${NC}"

# Summary
echo ""
echo -e "${GREEN}=== WireGuard Setup Complete ===${NC}"
echo ""
echo -e "${YELLOW}Server Configuration:${NC}"
echo -e "  Interface: wg0"
echo -e "  Server IP: 10.0.0.1/8"
echo -e "  Listen Port: 51820"
echo -e "  Public Key: $SERVER_PUBLIC_KEY"
echo -e "  Public Endpoint: $PUBLIC_IP:51820"
echo ""
echo -e "${YELLOW}Capacity:${NC}"
echo -e "  Max Tenants: 100,000+"
echo -e "  Max Routers: Unlimited (limited by subnet)"
echo -e "  Subnet Range: 10.0.0.0/8 (16,777,216 IPs)"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo -e "  1. Update Laravel .env with server configuration"
echo -e "  2. Add VPN_SERVER_PUBLIC_KEY=$SERVER_PUBLIC_KEY"
echo -e "  3. Add VPN_SERVER_ENDPOINT=$PUBLIC_IP:51820"
echo -e "  4. Restart Laravel backend"
echo -e "  5. Create first router and test VPN connection"
echo ""
echo -e "${YELLOW}Useful Commands:${NC}"
echo -e "  - View status: ${GREEN}wg show wg0${NC}"
echo -e "  - Add peer: ${GREEN}wg-add-peer <public-key> <ip>${NC}"
echo -e "  - Remove peer: ${GREEN}wg-remove-peer <public-key>${NC}"
echo -e "  - List peers: ${GREEN}wg-list-peers${NC}"
echo -e "  - Restart: ${GREEN}wg-quick down wg0 && wg-quick up wg0${NC}"
echo ""
echo -e "${GREEN}Setup complete!${NC}"
