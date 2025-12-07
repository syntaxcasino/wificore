#!/bin/bash

# Cross-Platform WireGuard Client Setup Script (Windows Git Bash & Linux)
# Run as Administrator (Windows) or with sudo (Linux). Assumes WireGuard is installed.

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== Cross-Platform WireGuard Client Setup ===${NC}"
echo "Detected OS: $(uname -s)"
echo "This will generate keys, create a config, and install/start the tunnel."
echo "Press Ctrl+C to abort."

# OS Detection
OS=$(uname -s)
case "$OS" in
    MINGW*|CYGWIN*) IS_WINDOWS=true ;;
    Linux*) IS_WINDOWS=false ;;
    *) echo -e "${RED}Unsupported OS: $OS${NC}"; exit 1 ;;
esac

# WireGuard paths and tools
if $IS_WINDOWS; then
    WG_PATH="/c/Program Files/WireGuard/wg.exe"
    WG_QUICK_PATH="/c/Program Files/WireGuard/wg-quick.exe"
    if [[ ! -f "$WG_PATH" ]]; then
        echo -e "${RED}Error: WireGuard not found. Install from https://www.wireguard.com/install${NC}"
        exit 1
    fi
    export PATH="/c/Program Files/WireGuard:$PATH"
    CONFIG_DIR="$HOME/wg-setup"
    SERVICE_INSTALL="wireguard /installtunnelservice"
    SERVICE_START="net start \"WireGuardTunnel\$${TUNNEL_NAME}\""
    SERVICE_STOP="net stop \"WireGuardTunnel\$${TUNNEL_NAME}\""
    SERVICE_UNINSTALL="wireguard /uninstalltunnelservice"
    SUDO=""
else  # Linux
    WG_PATH="/usr/bin/wg"
    if [[ ! -f "$WG_PATH" ]]; then
        echo -e "${RED}Error: WireGuard not found. Install with 'sudo apt install wireguard' (or equivalent).${NC}"
        exit 1
    fi
    CONFIG_DIR="/etc/wireguard"
    SERVICE_INSTALL="true"  # Handled by wg-quick/systemctl
    SERVICE_START="systemctl enable --now wg-quick@\$TUNNEL_NAME"
    SERVICE_STOP="systemctl disable --now wg-quick@\$TUNNEL_NAME"
    SERVICE_UNINSTALL="rm /etc/wireguard/\$TUNNEL_NAME.conf && wg-quick down \$TUNNEL_NAME"
    SUDO="sudo "
fi

# Create setup directory (use sudo on Linux if needed)
mkdir -p "$CONFIG_DIR"
cd "$CONFIG_DIR"

# Secure umask for keys/files
umask 077

# Generate keys (quoted paths to handle spaces on Windows)
echo -e "${YELLOW}Generating client keys...${NC}"
"${WG_PATH}" genkey | tee client-private.key | "${WG_PATH}" pubkey > client-public.key
echo -e "${GREEN}Private key saved to client-private.key (KEEP SECRET!)${NC}"
echo -e "${GREEN}Public key: $(cat client-public.key) - Share this with your server admin.${NC}"

# Prompt for config details
read -p "Enter tunnel name (e.g., myvpn): " TUNNEL_NAME
read -p "Enter your assigned VPN IP (e.g., 10.0.0.2/32): " CLIENT_IP
read -p "Enter server public key: " SERVER_PUBKEY
read -p "Enter server endpoint (e.g., vpn.example.com:51820): " SERVER_ENDPOINT
read -p "Route all traffic? (y/n, default y): " ALL_TRAFFIC

if [[ "$ALL_TRAFFIC" != "n" ]]; then
    ALLOWED_IPS="0.0.0.0/0"
else
    read -p "Enter allowed IPs (e.g., 10.0.0.0/24): " ALLOWED_IPS
fi

read -p "Enter DNS (optional, e.g., 1.1.1.1, or Enter for none): " DNS_SERVER
KEEPALIVE=25  # Default

# Create config file
CONFIG_FILE="${TUNNEL_NAME}.conf"
cat > "$CONFIG_FILE" << EOF
[Interface]
PrivateKey = $(cat client-private.key)
Address = $CLIENT_IP
$( [[ -n "$DNS_SERVER" ]] && echo "DNS = $DNS_SERVER" )

[Peer]
PublicKey = $SERVER_PUBKEY
AllowedIPs = $ALLOWED_IPS
Endpoint = $SERVER_ENDPOINT
PersistentKeepalive = $KEEPALIVE
EOF

# Apply sudo if Linux for config (but since we cd'd and mkdir'd, assume privileges)
if ! $IS_WINDOWS; then
    chown root:root "$CONFIG_FILE"  # Secure ownership on Linux
fi

echo -e "${GREEN}Config created: $CONFIG_FILE${NC}"
echo "Contents:"
cat "$CONFIG_FILE"
echo

# Test config syntax (quoted paths)
echo -e "${YELLOW}Testing config...${NC}"
if $IS_WINDOWS; then
    "${WG_QUICK_PATH}" up "$CONFIG_FILE" --dry-run
else
    ${SUDO} wg-quick up "$CONFIG_FILE" --dry-run
fi
if [[ $? -eq 0 ]]; then
    echo -e "${GREEN}Config syntax OK.${NC}"
else
    echo -e "${RED}Config test failed. Check details.${NC}"
    exit 1
fi

# Install/Start service
echo -e "${YELLOW}Installing/starting tunnel...${NC}"
if $IS_WINDOWS; then
    $SERVICE_INSTALL "$CONFIG_FILE"
    if [[ $? -eq 0 ]]; then
        $SERVICE_START
    fi
else
    # On Linux, wg-quick handles install/enable
    ${SUDO} wg-quick up "$TUNNEL_NAME"
    eval $SERVICE_START  # Uses the variable with $TUNNEL_NAME expanded
fi

if [[ $? -eq 0 ]]; then
    echo -e "${GREEN}Tunnel started!${NC}"
else
    echo -e "${RED}Start failed.${NC}"
fi

# Verify (quoted path)
echo -e "${YELLOW}Verifying...${NC}"
${SUDO} "${WG_PATH}" show "$TUNNEL_NAME"
echo
echo -e "${GREEN}Setup complete! Config in $CONFIG_DIR.${NC}"
if $IS_WINDOWS; then
    echo -e "${YELLOW}To stop: $SERVICE_STOP${NC}"
    echo -e "${YELLOW}To uninstall: $SERVICE_UNINSTALL $TUNNEL_NAME${NC}"
else
    echo -e "${YELLOW}To stop: $SERVICE_STOP${NC}"
    echo -e "${YELLOW}To uninstall: $SERVICE_UNINSTALL${NC}"
fi
echo -e "${YELLOW}Check IP: curl ifconfig.me${NC}"