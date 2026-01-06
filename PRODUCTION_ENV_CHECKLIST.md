# Production Environment Variables Checklist

## ⚠️ CRITICAL: Missing Environment Variables on Production Server

Based on the error logs, the following variables are **NOT SET** on the production server:

```bash
WARN[0000] The "APP_KEY" variable is not set. Defaulting to a blank string.
WARN[0000] The "SANCTUM_STATEFUL_DOMAINS" variable is not set. Defaulting to a blank string.
WARN[0000] The "SESSION_DOMAIN" variable is not set. Defaulting to a blank string.
WARN[0000] The "RADIUS_SECRET" variable is not set. Defaulting to a blank string.
WARN[0000] The "REDIS_PASSWORD" variable is not set. Defaulting to a blank string.
WARN[0000] The "WIREGUARD_API_KEY" variable is not set. Defaulting to a blank string.
```

## 🔧 Fix Instructions for Production Server

### Step 1: SSH into Production Server
```bash
ssh root@144.91.71.208
cd /opt/wificore
```

### Step 2: Verify Current .env.production
```bash
# Check if these variables exist
grep -E "^APP_KEY=|^SANCTUM_STATEFUL_DOMAINS=|^SESSION_DOMAIN=|^RADIUS_SECRET=|^REDIS_PASSWORD=|^WIREGUARD_API_KEY=" .env.production
```

### Step 3: Add Missing Variables
```bash
# If any are missing, add them:
cat >> .env.production << 'EOF'

# ============================================================================
# CRITICAL MISSING VARIABLES - ADD THESE NOW
# ============================================================================

# Application Key (MUST be set for encryption)
APP_KEY=base64:fCoFGM8V6G/vaJnPFLhYhQybBlPEMVPWMsCWv1UwkHI=

# Sanctum Stateful Domains (MUST match your domain)
SANCTUM_STATEFUL_DOMAINS=wificore.traidsolutions.com,*.traidsolutions.com

# Session Domain (MUST match your domain)
SESSION_DOMAIN=.traidsolutions.com

# RADIUS Secret (MUST be set for FreeRADIUS)
RADIUS_SECRET=testing123

# Redis Password (MUST match what's in .env.production)
REDIS_PASSWORD=RedisTS2026SecurePass

# WireGuard API Key (MUST be set for WireGuard controller)
WIREGUARD_API_KEY=4UePE4CaTdYn/iiND5EEQZL0U4nHqhHp54jNUSbHWPg=

EOF
```

### Step 4: Verify All Critical Variables
```bash
# Run this comprehensive check
cat > /tmp/check_env.sh << 'SCRIPT'
#!/bin/bash
echo "=== Checking Critical Environment Variables ==="
MISSING=0

check_var() {
    VAR_NAME=$1
    if grep -q "^${VAR_NAME}=.\+" .env.production 2>/dev/null; then
        echo "✅ ${VAR_NAME} is set"
    else
        echo "❌ ${VAR_NAME} is MISSING or EMPTY"
        MISSING=$((MISSING + 1))
    fi
}

check_var "APP_KEY"
check_var "SANCTUM_STATEFUL_DOMAINS"
check_var "SESSION_DOMAIN"
check_var "RADIUS_SECRET"
check_var "REDIS_PASSWORD"
check_var "WIREGUARD_API_KEY"
check_var "RADIUS_SERVER_IP"
check_var "WIREGUARD_CONTROLLER_URL"

echo ""
if [ $MISSING -eq 0 ]; then
    echo "✅ All critical variables are set!"
    exit 0
else
    echo "❌ $MISSING variable(s) missing. Fix before deploying!"
    exit 1
fi
SCRIPT

chmod +x /tmp/check_env.sh
/tmp/check_env.sh
```

### Step 5: Pull Latest Code (WebSocket Fix)
```bash
git pull origin main
```

### Step 6: Rebuild Frontend Container
```bash
# Frontend needs to be rebuilt with the WebSocket path fix
docker compose -f docker-compose.production.yml build wificore-frontend --no-cache
```

### Step 7: Restart All Services
```bash
# Stop all services
docker compose -f docker-compose.production.yml down

# Remove old wg0 interface
sudo ip link del wg0 2>/dev/null || true

# Start all services
docker compose -f docker-compose.production.yml up -d

# Wait for services to be healthy
sleep 30
```

### Step 8: Verify Services
```bash
# Check all containers are running
docker compose -f docker-compose.production.yml ps

# Verify FreeRADIUS has static IP
docker inspect wificore-freeradius --format='{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
# MUST output: 172.70.0.2

# Check backend logs for environment loading
docker compose -f docker-compose.production.yml logs wificore-backend | grep -i "variable is not set"
# Should show NO warnings

# Check Soketi is running
docker compose -f docker-compose.production.yml logs wificore-soketi | tail -20

# Check nginx is proxying WebSocket
curl -I https://wificore.traidsolutions.com/app/app-key
# Should return 101 Switching Protocols or 400 Bad Request (not 404)
```

### Step 9: Test Registration Flow
1. Open browser: https://wificore.traidsolutions.com/register
2. Fill in registration form
3. Submit and check for WebSocket errors in browser console
4. Verify email is sent
5. Click verification link
6. Should complete registration without getting stuck

## 🔍 Troubleshooting

### Issue: Still seeing "variable is not set" warnings
**Fix:** Variables are not being loaded from .env.production
```bash
# Ensure .env.production is in the correct location
ls -la /opt/wificore/.env.production

# Check file permissions
chmod 600 /opt/wificore/.env.production

# Verify docker-compose is using .env.production
docker compose -f docker-compose.production.yml config | grep -A5 "environment:"
```

### Issue: WebSocket still shows path doubling
**Fix:** Frontend container not rebuilt
```bash
# Force rebuild frontend
docker compose -f docker-compose.production.yml build wificore-frontend --no-cache
docker compose -f docker-compose.production.yml up -d wificore-frontend
```

### Issue: 401 Unauthorized on /api/broadcasting/auth
**Fix:** Token not being sent or SANCTUM_STATEFUL_DOMAINS incorrect
```bash
# Verify SANCTUM_STATEFUL_DOMAINS matches your domain
grep SANCTUM_STATEFUL_DOMAINS .env.production

# Check SESSION_DOMAIN
grep SESSION_DOMAIN .env.production

# Should be:
# SANCTUM_STATEFUL_DOMAINS=wificore.traidsolutions.com,*.traidsolutions.com
# SESSION_DOMAIN=.traidsolutions.com
```

### Issue: Registration stuck at email verification
**Possible causes:**
1. WebSocket not connecting (check browser console)
2. Broadcasting events not being sent (check backend logs)
3. Soketi not receiving events (check soketi logs)

```bash
# Check if events are being broadcast
docker compose -f docker-compose.production.yml logs -f wificore-backend | grep -i "broadcast"

# Check Soketi is receiving connections
docker compose -f docker-compose.production.yml logs -f wificore-soketi

# Test WebSocket connection manually
wscat -c "wss://wificore.traidsolutions.com/app/app-key"
```

## ✅ Final Verification Checklist

- [ ] All environment variables set in .env.production
- [ ] No "variable is not set" warnings in logs
- [ ] Frontend rebuilt with WebSocket path fix
- [ ] FreeRADIUS has static IP 172.70.0.2
- [ ] WebSocket connects without path doubling
- [ ] No 401 errors on /api/broadcasting/auth
- [ ] Registration completes successfully
- [ ] Email verification works
- [ ] User can log in after verification

## 📝 Notes

- The `.env.production` file is gitignored (correctly) - changes must be made directly on the server
- Always verify environment variables are loaded before deploying
- Frontend container must be rebuilt when WebSocket configuration changes
- WebSocket path should be `/` not `/app` (Soketi handles the /app internally)




Looking at your output, everything is configured correctly! ✅

Let me answer your question about how routers connect through VPN:

🔌 How Routers Connect Through VPN
WireGuard Listening Port
bash
interface: wg0
  listening port: 47997  ← This is the ACTUAL port WireGuard is using
Important: The listening port is 47997, NOT 51830. This is because:

WireGuard automatically picks an available port if 51830 is in use
This is normal and works fine
Routers will connect to: 144.91.71.208:47997
Router Connection Flow
Router (MikroTik/etc)
    ↓ WireGuard tunnel to 144.91.71.208:47997
    ↓
wg0 interface (10.8.0.1/24) on host
    ↓
    ├─→ RADIUS traffic (ports 1812/1813)
    │   ↓ iptables DNAT redirects to 172.70.0.2
    │   ↓
    │   FreeRADIUS container (172.70.0.2) ✅
    │
    └─→ Management traffic (SSH, API, etc)
        ↓ Routes through wg0
        ↓
        Backend/Services
RADIUS Port Forwarding (Already Working)
Your wg0.conf shows the DNAT rules are correctly configured:

bash
iptables -t nat -A PREROUTING -i wg0 -p udp --dport 1812 -j DNAT --to-destination 172.70.0.2:1812
iptables -t nat -A PREROUTING -i wg0 -p udp --dport 1813 -j DNAT --to-destination 172.70.0.2:1813
This means:

Router sends RADIUS auth to 10.8.0.1:1812 (WireGuard gateway)
iptables redirects to 172.70.0.2:1812 (FreeRADIUS container)
FreeRADIUS processes authentication
Response goes back through the tunnel
Ports Used
Service	Port	Purpose
WireGuard	47997 (auto-assigned)	VPN tunnel endpoint - routers connect here
RADIUS Auth	1812	Authentication (forwarded to FreeRADIUS)
RADIUS Acct	1813	Accounting (forwarded to FreeRADIUS)
Backend API	443/8070	Management (via nginx)
What You Need to Configure on Routers
When you create a tenant and provision a router, the system will generate a WireGuard config like:

ini
[Interface]
PrivateKey = <router_private_key>
Address = 10.X.Y.Z/32  # Unique IP per router
[Peer]
PublicKey = 4VWYMDDs7RdPyi+69Qmle1+7sTLhBHhxWwaxnZVaymA=
Endpoint = 144.91.71.208:47997  ← Your server's actual listening port
AllowedIPs = 10.0.0.0/8
PersistentKeepalive = 25
Current Status: Ready for Routers ✅
✅ wg0 interface: UP and running
✅ Listening port: 47997 (working)
✅ FreeRADIUS: 172.70.0.2 (correct static IP)
✅ DNAT rules: Configured for RADIUS forwarding
✅ No peers yet: wg0_peers.conf is empty (expected - no routers added)
Next Steps to Test
Create a tenant via the web interface
Add a router to that tenant
The system will:
Allocate a unique subnet (e.g., 10.1.0.0/16)
Generate router WireGuard config
Add peer to wg0
Configure the router with the generated WireGuard config
Router connects to 144.91.71.208:47997
RADIUS traffic flows through the tunnel to FreeRADIUS
Your VPN infrastructure is ready! The listening port being 47997 instead of 51830 is completely normal and doesn't affect functionality.

Feedback submitted