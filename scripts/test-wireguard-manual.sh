#!/bin/bash
# Manual WireGuard Interface Creation Test
# Run this on the production server to manually create wg0 and verify it works

set -e

echo "=== Manual WireGuard Interface Creation Test ==="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "ERROR: Please run as root (sudo)"
    exit 1
fi

echo "Step 1: Check if WireGuard kernel module is loaded"
if lsmod | grep -q wireguard; then
    echo "✓ WireGuard module is loaded"
else
    echo "⚠ WireGuard module not loaded, attempting to load..."
    modprobe wireguard || echo "✗ Failed to load WireGuard module"
fi
echo ""

echo "Step 2: Check if wg0 already exists"
if ip link show wg0 &>/dev/null; then
    echo "⚠ wg0 already exists, removing it first..."
    ip link del wg0
    echo "✓ Removed existing wg0"
fi
echo ""

echo "Step 3: Create wg0 interface manually"
ip link add dev wg0 type wireguard
if [ $? -eq 0 ]; then
    echo "✓ wg0 interface created successfully"
else
    echo "✗ Failed to create wg0 interface"
    exit 1
fi
echo ""

echo "Step 4: Generate WireGuard keys"
PRIVATE_KEY=$(wg genkey)
PUBLIC_KEY=$(echo "$PRIVATE_KEY" | wg pubkey)
echo "Private Key: $PRIVATE_KEY"
echo "Public Key: $PUBLIC_KEY"
echo ""

echo "Step 5: Configure wg0 interface"
wg set wg0 private-key <(echo "$PRIVATE_KEY") listen-port 51830
ip addr add 10.8.0.1/24 dev wg0
ip link set wg0 up
echo "✓ wg0 configured and brought up"
echo ""

echo "Step 6: Verify interface is up"
echo "--- ip link show wg0 ---"
ip link show wg0
echo ""

echo "--- wg show wg0 ---"
wg show wg0
echo ""

echo "Step 7: Check if interface is visible in network namespaces"
echo "--- ip netns list ---"
ip netns list || echo "(No network namespaces)"
echo ""

echo "=== Test Complete ==="
echo ""
echo "If you can see wg0 above with 'state UP', the interface works on the host!"
echo ""
echo "To clean up:"
echo "  sudo ip link del wg0"
echo ""
echo "If this test worked but the container still can't create interfaces,"
echo "the issue is that the container is NOT using host network mode."
