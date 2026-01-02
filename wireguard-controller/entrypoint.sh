#!/bin/bash
set -e

echo "Starting WireGuard Controller..."

# Load WireGuard kernel module
echo "Loading WireGuard kernel module..."
modprobe wireguard || echo "WireGuard module already loaded or not available"

# Enable IP forwarding
echo "Enabling IP forwarding..."
sysctl -w net.ipv4.ip_forward=1 > /dev/null
sysctl -w net.ipv6.conf.all.forwarding=1 > /dev/null

# Create WireGuard directory if not exists
mkdir -p /etc/wireguard
chmod 755 /etc/wireguard

echo "WireGuard Controller initialization complete"

# Execute the main command
exec "$@"
