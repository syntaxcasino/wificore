#!/bin/bash
set -e

echo "Starting WireGuard Controller..."

# Validate required environment variables
if [ -z "$WIREGUARD_API_KEY" ]; then
    echo "ERROR: WIREGUARD_API_KEY environment variable is not set!"
    echo "Please set WIREGUARD_API_KEY in your .env.production file"
    exit 1
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

echo "WireGuard Controller initialization complete"
echo "API Key: ${WIREGUARD_API_KEY:0:8}... (masked)"

# Execute the main command
exec "$@"
