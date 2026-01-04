#!/bin/bash
set -e

echo "Starting WireGuard Controller..."

# Validate required environment variables
if [ -z "$WIREGUARD_API_KEY" ]; then
    echo "ERROR: WIREGUARD_API_KEY environment variable is not set!"
    echo "Please set WIREGUARD_API_KEY in your .env.production file"
    exit 1
fi

if [ -z "$VPN_SERVER_PRIVATE_KEY" ]; then
    echo "ERROR: VPN_SERVER_PRIVATE_KEY environment variable is not set!"
    echo "Please set VPN_SERVER_PRIVATE_KEY in your .env.production file"
    exit 1
fi

if [ -z "$VPN_LISTEN_PORT" ]; then
    echo "WARNING: VPN_LISTEN_PORT not set, using default 51830"
    VPN_LISTEN_PORT=51830
fi

if [ -z "$VPN_SERVER_IP" ]; then
    echo "WARNING: VPN_SERVER_IP not set, using default 10.8.0.1"
    VPN_SERVER_IP="10.8.0.1"
fi

if [ -z "$VPN_INTERFACE_NAME" ]; then
    echo "WARNING: VPN_INTERFACE_NAME not set, using default wg0"
    VPN_INTERFACE_NAME="wg0"
fi

# Load WireGuard kernel module (optional, may already be loaded)
echo "Loading WireGuard kernel module..."
modprobe wireguard 2>/dev/null || echo "WireGuard module already loaded or not available"

# Enable IP forwarding (handled by docker-compose sysctls, so errors are non-fatal)
echo "Verifying IP forwarding..."
sysctl -w net.ipv4.ip_forward=1 > /dev/null 2>&1 || echo "IP forwarding already configured via docker-compose"
sysctl -w net.ipv6.conf.all.forwarding=1 > /dev/null 2>&1 || echo "IPv6 forwarding already configured via docker-compose"

# Create WireGuard directory if not exists
mkdir -p /etc/wireguard
chmod 755 /etc/wireguard

# Auto-create WireGuard interface configuration if it doesn't exist
CONFIG_FILE="/etc/wireguard/${VPN_INTERFACE_NAME}.conf"
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Creating initial WireGuard configuration for ${VPN_INTERFACE_NAME}..."
    
    # Get RADIUS server IP from environment or use default
    RADIUS_IP="${RADIUS_SERVER_IP:-172.70.0.2}"
    
    cat > "$CONFIG_FILE" << EOF
[Interface]
Address = ${VPN_SERVER_IP}/24
ListenPort = ${VPN_LISTEN_PORT}
PrivateKey = ${VPN_SERVER_PRIVATE_KEY}
PostUp = iptables -A FORWARD -i ${VPN_INTERFACE_NAME} -o eth0 -j ACCEPT; iptables -A FORWARD -i ${VPN_INTERFACE_NAME} -o ${VPN_INTERFACE_NAME} -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE; ip route add 10.0.0.0/8 dev ${VPN_INTERFACE_NAME}; iptables -t nat -A PREROUTING -i ${VPN_INTERFACE_NAME} -p udp --dport 1812 -j DNAT --to-destination ${RADIUS_IP}:1812; iptables -t nat -A PREROUTING -i ${VPN_INTERFACE_NAME} -p udp --dport 1813 -j DNAT --to-destination ${RADIUS_IP}:1813
PostDown = iptables -D FORWARD -i ${VPN_INTERFACE_NAME} -o eth0 -j ACCEPT; iptables -D FORWARD -i ${VPN_INTERFACE_NAME} -o ${VPN_INTERFACE_NAME} -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE; ip route del 10.0.0.0/8 dev ${VPN_INTERFACE_NAME}; iptables -t nat -D PREROUTING -i ${VPN_INTERFACE_NAME} -p udp --dport 1812 -j DNAT --to-destination ${RADIUS_IP}:1812; iptables -t nat -D PREROUTING -i ${VPN_INTERFACE_NAME} -p udp --dport 1813 -j DNAT --to-destination ${RADIUS_IP}:1813

# Peers will be added dynamically via API
EOF
    
    chmod 600 "$CONFIG_FILE"
    echo "✓ Configuration created at $CONFIG_FILE"
    
    # Bring up the interface
    echo "Bringing up ${VPN_INTERFACE_NAME} interface..."
    wg-quick up "${VPN_INTERFACE_NAME}" || echo "Interface may already be up"
    
    echo "✓ ${VPN_INTERFACE_NAME} interface initialized"
else
    echo "Configuration already exists at $CONFIG_FILE"
    
    # Check if interface is up, if not bring it up
    if ! ip link show "${VPN_INTERFACE_NAME}" > /dev/null 2>&1; then
        echo "Interface ${VPN_INTERFACE_NAME} is down, bringing it up..."
        wg-quick up "${VPN_INTERFACE_NAME}" || echo "Failed to bring up interface"
    else
        echo "Interface ${VPN_INTERFACE_NAME} is already up"
    fi
fi

echo "WireGuard Controller initialization complete"
echo "API Key: ${WIREGUARD_API_KEY:0:8}... (masked)"
echo "Interface: ${VPN_INTERFACE_NAME}"
echo "Listen Port: ${VPN_LISTEN_PORT}"
echo "Server IP: ${VPN_SERVER_IP}"

# Execute the main command
exec "$@"
