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

# Auto-create/fix WireGuard interface configuration
CONFIG_FILE="/etc/wireguard/${VPN_INTERFACE_NAME}.conf"
RADIUS_IP="${RADIUS_SERVER_IP:-172.70.0.2}"
NEEDS_RECREATION=false

# Function to create config file
create_config() {
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
}

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Creating initial WireGuard configuration for ${VPN_INTERFACE_NAME}..."
    create_config
    echo "✓ Configuration created at $CONFIG_FILE"
    NEEDS_RECREATION=true
else
    echo "Configuration exists, checking if it needs update..."
    
    # Check if interface is up
    if ip link show "${VPN_INTERFACE_NAME}" > /dev/null 2>&1; then
        # Get current listen port
        CURRENT_PORT=$(wg show "${VPN_INTERFACE_NAME}" listen-port 2>/dev/null || echo "0")
        
        # Check if port matches configured port
        if [ "$CURRENT_PORT" != "$VPN_LISTEN_PORT" ]; then
            echo "⚠ Port mismatch detected: current=$CURRENT_PORT, expected=$VPN_LISTEN_PORT"
            echo "Recreating interface with correct port..."
            
            # Bring down interface
            wg-quick down "${VPN_INTERFACE_NAME}" 2>/dev/null || true
            
            # Recreate config with correct port
            create_config
            echo "✓ Configuration updated"
            NEEDS_RECREATION=true
        else
            echo "✓ Port is correct ($CURRENT_PORT)"
        fi
        
        # Check if private key matches
        CURRENT_PUBKEY=$(wg show "${VPN_INTERFACE_NAME}" public-key 2>/dev/null || echo "")
        EXPECTED_PUBKEY=$(echo "$VPN_SERVER_PRIVATE_KEY" | wg pubkey 2>/dev/null || echo "")
        
        if [ -n "$CURRENT_PUBKEY" ] && [ -n "$EXPECTED_PUBKEY" ] && [ "$CURRENT_PUBKEY" != "$EXPECTED_PUBKEY" ]; then
            echo "⚠ Public key mismatch detected"
            echo "Current: $CURRENT_PUBKEY"
            echo "Expected: $EXPECTED_PUBKEY"
            echo "Recreating interface with correct keys..."
            
            # Bring down interface
            wg-quick down "${VPN_INTERFACE_NAME}" 2>/dev/null || true
            
            # Recreate config
            create_config
            echo "✓ Configuration updated"
            NEEDS_RECREATION=true
        else
            echo "✓ Keys are correct"
        fi
    else
        echo "Interface is down, will bring it up"
        NEEDS_RECREATION=true
    fi
fi

# Bring up interface if needed
if [ "$NEEDS_RECREATION" = true ]; then
    echo "Bringing up ${VPN_INTERFACE_NAME} interface..."
    
    # First, ensure any existing interface is completely removed
    if ip link show "${VPN_INTERFACE_NAME}" > /dev/null 2>&1; then
        echo "Removing existing ${VPN_INTERFACE_NAME} interface..."
        ip link delete "${VPN_INTERFACE_NAME}" 2>/dev/null || true
        sleep 1
    fi
    
    # Now bring up fresh interface
    wg-quick up "${VPN_INTERFACE_NAME}" || {
        echo "wg-quick failed, retrying after cleanup..."
        ip link delete "${VPN_INTERFACE_NAME}" 2>/dev/null || true
        sleep 2
        wg-quick up "${VPN_INTERFACE_NAME}"
    }
    echo "✓ ${VPN_INTERFACE_NAME} interface is up"
else
    echo "✓ ${VPN_INTERFACE_NAME} interface is already correctly configured"
fi

echo "WireGuard Controller initialization complete"
echo "API Key: ${WIREGUARD_API_KEY:0:8}... (masked)"
echo "Interface: ${VPN_INTERFACE_NAME}"
echo "Listen Port: ${VPN_LISTEN_PORT}"
echo "Server IP: ${VPN_SERVER_IP}"

# Execute the main command
exec "$@"
