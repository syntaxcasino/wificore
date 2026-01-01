# VPN Configuration Guide

**Date:** January 1, 2026  
**Component:** WireGuard VPN Server  
**Purpose:** Router connectivity and tenant isolation

---

## Overview

The WiFiCore application uses WireGuard VPN to securely connect remote routers to the management platform. Each tenant gets isolated VPN subnets for security.

---

## Required Environment Variables

### VPN_SERVER_ENDPOINT

**Critical:** This variable must be set in your `.env.production` file.

```env
# VPN Server Endpoint - MUST be your public IP or domain
VPN_SERVER_ENDPOINT=144.91.71.208:51830
# OR
VPN_SERVER_ENDPOINT=vpn.yourdomain.com:51830
```

**What it does:**
- This is the endpoint that routers will connect to
- Must be your server's public IP address or domain name
- Must include the port number (default: 51830)
- Used in WireGuard peer configuration generation

**Common Issues:**

❌ **Error:** `WARN The "VPN_SERVER_ENDPOINT" variable is not set. Defaulting to a blank string.`

**Solution:**
1. Edit `/opt/wificore/.env.production`
2. Add or update: `VPN_SERVER_ENDPOINT=YOUR_PUBLIC_IP:51830`
3. Restart containers: `docker compose -f docker-compose.production.yml restart`

---

## Complete VPN Configuration

### Required Variables

```env
# VPN Mode (always use 'host' for production)
VPN_MODE=host

# VPN Interface name
VPN_INTERFACE_NAME=wg0

# Your server's public IP address
VPN_SERVER_PUBLIC_IP=144.91.71.208

# VPN Server Endpoint (public IP or domain + port)
VPN_SERVER_ENDPOINT=144.91.71.208:51830

# VPN Listen Port
VPN_LISTEN_PORT=51830

# WireGuard Server Keys (generate with: wg genkey | tee privatekey | wg pubkey > publickey)
VPN_SERVER_PRIVATE_KEY=your_private_key_here
VPN_SERVER_PUBLIC_KEY=your_public_key_here

# VPN Subnet Base (for tenant isolation)
VPN_SUBNET_BASE=10.0.0.0/8
```

---

## Initial Setup

### 1. Generate WireGuard Keys

```bash
# Generate server private key
wg genkey | tee /opt/wificore/vpn_private.key

# Generate server public key from private key
cat /opt/wificore/vpn_private.key | wg pubkey | tee /opt/wificore/vpn_public.key

# Display keys
echo "Private Key: $(cat /opt/wificore/vpn_private.key)"
echo "Public Key: $(cat /opt/wificore/vpn_public.key)"
```

### 2. Update .env.production

```bash
cd /opt/wificore
nano .env.production
```

Add/update these lines:
```env
VPN_SERVER_PUBLIC_IP=YOUR_PUBLIC_IP
VPN_SERVER_ENDPOINT=YOUR_PUBLIC_IP:51830
VPN_SERVER_PRIVATE_KEY=YOUR_GENERATED_PRIVATE_KEY
VPN_SERVER_PUBLIC_KEY=YOUR_GENERATED_PUBLIC_KEY
```

### 3. Configure Firewall

```bash
# Allow WireGuard port
sudo ufw allow 51830/udp

# Enable IP forwarding
sudo sysctl -w net.ipv4.ip_forward=1
sudo sysctl -w net.ipv4.conf.all.src_valid_mark=1

# Make persistent
echo "net.ipv4.ip_forward=1" | sudo tee -a /etc/sysctl.conf
echo "net.ipv4.conf.all.src_valid_mark=1" | sudo tee -a /etc/sysctl.conf
```

### 4. Restart Services

```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml restart wificore-backend
docker compose -f docker-compose.production.yml restart wificore-wireguard
```

---

## Verification

### Check VPN Server Status

```bash
# Check WireGuard interface
sudo wg show

# Check if port is listening
sudo netstat -tulpn | grep 51830

# Check backend logs
docker compose -f docker-compose.production.yml logs wificore-backend | grep VPN
```

### Test Router Connection

1. Provision a router through the UI
2. Download the generated WireGuard configuration
3. Apply to router
4. Check connection:
   ```bash
   sudo wg show wg0
   ```

---

## Tenant VPN Isolation

Each tenant gets a unique `/16` subnet:
- Tenant 1: `10.1.0.0/16`
- Tenant 2: `10.2.0.0/16`
- Tenant 3: `10.3.0.0/16`
- etc.

**VPN Gateway:** `10.X.0.1` (where X is tenant number)  
**Router IPs:** `10.X.0.2` - `10.X.255.254`

---

## Troubleshooting

### Issue: VPN_SERVER_ENDPOINT Warning

**Symptom:**
```
WARN The "VPN_SERVER_ENDPOINT" variable is not set
```

**Fix:**
```bash
# Edit production environment
nano /opt/wificore/.env.production

# Add this line (replace with your IP)
VPN_SERVER_ENDPOINT=144.91.71.208:51830

# Restart
docker compose -f docker-compose.production.yml restart
```

### Issue: Routers Can't Connect

**Check:**
1. Firewall allows UDP port 51830
2. VPN_SERVER_ENDPOINT matches your public IP
3. WireGuard service is running
4. Keys are correctly configured

```bash
# Check firewall
sudo ufw status | grep 51830

# Check WireGuard
sudo wg show

# Check backend logs
docker compose logs wificore-backend | grep -i vpn
```

### Issue: Cross-Tenant Communication

**This should NOT be possible.** If it is:
1. Check firewall rules in WireGuard config
2. Verify tenant subnet isolation
3. Check router VPN IP assignments

```bash
# View WireGuard config
sudo cat /etc/wireguard/wg0.conf

# Should have rules like:
# PostUp = iptables -A FORWARD -i wg0.1 -o wg0.2 -j DROP
```

---

## Security Best Practices

1. **Never commit VPN keys to git**
   - Keys are in `.env.production` (gitignored)
   - Keep backups in secure location

2. **Use strong keys**
   - Always generate new keys (don't reuse)
   - Rotate keys periodically

3. **Restrict access**
   - Only allow VPN port (51830/udp) from router IPs
   - Use fail2ban for additional protection

4. **Monitor connections**
   - Check `wg show` regularly
   - Monitor for unauthorized peers
   - Review logs for suspicious activity

---

## Production Deployment Checklist

- [ ] Generate WireGuard server keys
- [ ] Set VPN_SERVER_PUBLIC_IP to your public IP
- [ ] Set VPN_SERVER_ENDPOINT to your public IP:51830
- [ ] Add VPN_SERVER_PRIVATE_KEY
- [ ] Add VPN_SERVER_PUBLIC_KEY
- [ ] Configure firewall (allow 51830/udp)
- [ ] Enable IP forwarding
- [ ] Restart services
- [ ] Test router connection
- [ ] Verify tenant isolation

---

## Reference

**Default Values:**
- VPN Mode: `host`
- Interface: `wg0`
- Listen Port: `51830`
- Subnet Base: `10.0.0.0/8`
- Server IP: `10.8.0.1`

**Related Documentation:**
- `docs/LOAD_BALANCING_GUIDE.md`
- `docs/DEVELOPER_SECURITY_GUIDELINES.md`

---

**Last Updated:** January 1, 2026
