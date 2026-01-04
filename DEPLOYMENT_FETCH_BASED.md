# 🚀 FETCH-BASED DEPLOYMENT GUIDE

## ✅ IMPLEMENTATION COMPLETE

### Minimal Provisioning Command Approach

The system now uses a **minimal bootstrap command** that fetches the complete configuration from the server, keeping all secrets server-side.

---

## 📋 HOW IT WORKS

### 1. Router Creation

When you create a router, the API returns:

```json
{
  "id": "uuid",
  "name": "router-name",
  "config_token": "secure-token",
  "vpn_ip": "10.100.x.x",
  
  "connectivity_script": "Minimal /tool fetch command",
  "sanitized_script": "Safe preview of the command",
  "complete_script": "Full inline config (fallback)"
}
```

### 2. Provisioning Command

**Minimal command to copy:**

```routeros
/tool fetch mode=https url="https://wificore.traidsolutions.com/api/routers/{config-token}/fetch-config" dst-path=config.rsc; :delay 2s; /import config.rsc
```

**What it does:**
1. Fetches complete configuration from server via HTTPS
2. Saves it as `config.rsc` on the router
3. Waits 2 seconds for download to complete
4. Imports and executes the configuration

### 3. Server-Side Configuration

The `/api/routers/{config-token}/fetch-config` endpoint returns:

```routeros
# ============================================
# ROUTER CONFIGURATION SCRIPT
# ============================================
# Router: router-name
# Generated: timestamp
# Config Token: secure-token

# ============================================
# STEP 1: Basic Connectivity Setup
# ============================================
/ip service set api disabled=no port=8728
/ip service set ssh disabled=no port=22 address=""
/user add name=admin-user password="secure-password" group=full
/ip firewall filter add chain=input protocol=tcp dst-port=22 action=accept place-before=0 comment="Allow SSH access"
/system identity set name="router-name"
/system note set note="Managed by Traidnet Solution LTD"

# ============================================
# STEP 2: VPN Configuration
# ============================================

# WireGuard VPN Configuration for MikroTik RouterOS
# Generated for Tenant: tenant-id
# Router IP: 10.100.x.x
# Generated: timestamp

# Step 1: Create WireGuard interface
/interface/wireguard
add name=wg-xxxxxxxx listen-port=xxxxx private-key="client-private-key"

# Step 2: Add IP address to WireGuard interface
/ip/address
add address=10.100.x.x/16 interface=wg-xxxxxxxx

# Step 3: Add WireGuard peer (server)
/interface/wireguard/peers
add interface=wg-xxxxxxxx \
    public-key="server-public-key" \
    preshared-key="preshared-key" \
    endpoint-address=144.91.71.208 \
    endpoint-port=51830 \
    allowed-address=0.0.0.0/0 \
    persistent-keepalive=00:00:25

# Step 4: Add route through VPN
/ip/route
add dst-address=10.0.0.0/8 gateway=wg-xxxxxxxx

# Step 5: Add firewall rule to allow VPN traffic
/ip/firewall/filter
add chain=input action=accept protocol=udp dst-port=xxxxx comment="Allow WireGuard VPN"

# Step 6: Enable interface
/interface/wireguard
enable wg-xxxxxxxx

# ============================================
# CONFIGURATION COMPLETE
# ============================================
# Your router is now configured and connected to the management VPN
# Server can reach this router at: 10.100.x.x
```

---

## 🔧 DEPLOYMENT STEPS

### Step 1: Deploy Code

```bash
ssh root@144.91.71.208
cd /opt/wificore

# Pull latest code
git pull origin main

# Should show commit: ff0a885

# Rebuild backend
docker compose -f docker-compose.production.yml build wificore-backend

# Restart services
docker compose -f docker-compose.production.yml down
docker compose -f docker-compose.production.yml up -d

# Clear caches
docker compose -f docker-compose.production.yml exec wificore-backend php artisan cache:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan config:clear
```

### Step 2: Verify Fetch Endpoint

```bash
# Test the fetch endpoint
curl -k https://wificore.traidsolutions.com/api/routers/test-token/fetch-config

# Should return plain text configuration script
# Or error message if token invalid
```

---

## ✅ VERIFICATION TESTS

### Test 1: Create Router

1. Login as tenant admin
2. Create new router
3. Check API response

**Expected:**
- ✅ `connectivity_script` contains `/tool fetch` command
- ✅ `sanitized_script` shows safe preview
- ✅ URL includes correct config token
- ✅ Mode is `https`

### Test 2: Fetch Configuration

```bash
# Get config token from router creation response
CONFIG_TOKEN="your-config-token-here"

# Fetch configuration
curl -k "https://wificore.traidsolutions.com/api/routers/$CONFIG_TOKEN/fetch-config"

# Expected:
# - Returns plain text script
# - Includes connectivity setup
# - Includes VPN configuration
# - No JSON formatting
```

### Test 3: Apply on MikroTik

**Prerequisites:**
- Router has internet connectivity
- `/tool fetch` is enabled (not blocked by license)
- Can reach wificore.traidsolutions.com

**Steps:**

1. Copy the provisioning command from UI
2. Paste into MikroTik terminal
3. Execute

**Expected output:**
```
[admin@router] > /tool fetch mode=https url="https://..." dst-path=config.rsc; :delay 2s; /import config.rsc
      status: finished
  downloaded: 2048 bytes
       total: 2048 bytes

Importing configuration from config.rsc...
```

**Verification:**
```routeros
# Check if user was created
/user print

# Check if WireGuard interface exists
/interface/wireguard print

# Check if VPN IP assigned
/ip/address print where interface~"wg-"

# Check if peer added
/interface/wireguard/peers print

# Try to ping VPN gateway
/ping 10.8.0.1 count=4

# Try to ping own VPN IP
/ping 10.100.x.x count=4
```

---

## ⚠️ TROUBLESHOOTING

### Issue 1: "/tool fetch not allowed by device-mode"

**Cause:** MikroTik CHR free license blocks `/tool fetch`

**Solutions:**

**Option A: Upgrade License**
```routeros
# Purchase and install license
/system license renew
```

**Option B: Use Complete Script (Fallback)**
- Use `complete_script` field from API response
- Copy entire script
- Paste into terminal
- No fetch required

### Issue 2: "Could not resolve host"

**Cause:** DNS not configured or router has no internet

**Fix:**
```routeros
# Check internet connectivity
/ping 8.8.8.8 count=4

# Check DNS
/ip dns print

# Set DNS servers
/ip dns set servers=8.8.8.8,1.1.1.1
```

### Issue 3: "Download failed"

**Cause:** HTTPS certificate issue or firewall blocking

**Fix:**
```routeros
# Try without certificate validation (not recommended for production)
/tool fetch mode=https check-certificate=no url="..." dst-path=config.rsc

# Check if port 443 is blocked
/ping 144.91.71.208 count=4

# Check firewall rules
/ip firewall filter print
```

### Issue 4: "Configuration not found" (404)

**Cause:** Invalid config token or router deleted

**Fix:**
- Verify config token is correct
- Check if router exists in database
- Recreate router if necessary

### Issue 5: Import fails

**Cause:** Syntax error in generated script

**Fix:**
```routeros
# View the downloaded file
/file print file=config.rsc

# Check for errors
# Manually execute sections if needed
```

---

## 🔍 CHECKING /tool fetch AVAILABILITY

### Method 1: Try the Command

```routeros
/tool fetch mode=https url="https://google.com" dst-path=test.txt

# If successful:
#   status: finished
#   downloaded: XXX bytes

# If blocked:
#   failure: not allowed by device-mode
```

### Method 2: Check License Level

```routeros
/system license print

# Check "level" field:
# - free: /tool fetch BLOCKED
# - p1 or higher: /tool fetch ALLOWED
```

### Method 3: Check System Resources

```routeros
/system resource print

# Look for:
# board-name: CHR (Cloud Hosted Router)
# 
# CHR with free license = /tool fetch blocked
# Physical hardware = /tool fetch allowed
```

---

## 📊 API ENDPOINTS

### Create Router
```
POST /api/routers
Authorization: Bearer {token}

Response:
{
  "connectivity_script": "/tool fetch mode=https url=...",
  "sanitized_script": "Safe preview",
  "complete_script": "Full inline config (fallback)",
  "config_token": "secure-token"
}
```

### Fetch Configuration (Public)
```
GET /api/routers/{config-token}/fetch-config

Response: (text/plain)
# Complete configuration script
# Ready to import on MikroTik
```

---

## 🎯 FRONTEND INTEGRATION

### Display Provisioning Command

```vue
<template>
  <div class="provisioning-section">
    <h3>Router Provisioning</h3>
    
    <!-- Show minimal command by default -->
    <div class="command-box">
      <h4>📋 Provisioning Command</h4>
      <p class="help-text">
        Copy and paste this command into your MikroTik terminal
      </p>
      
      <pre class="command">{{ router.connectivity_script }}</pre>
      
      <button @click="copyToClipboard(router.connectivity_script)">
        📋 Copy Command
      </button>
    </div>
    
    <!-- Fallback for CHR free license -->
    <div class="fallback-section" v-if="showFallback">
      <h4>⚠️ /tool fetch not available?</h4>
      <p class="help-text">
        If you're using CHR free license, use the complete script instead:
      </p>
      
      <button @click="showCompleteScript = !showCompleteScript">
        {{ showCompleteScript ? 'Hide' : 'Show' }} Complete Script
      </button>
      
      <pre v-if="showCompleteScript" class="complete-script">
        {{ router.complete_script }}
      </pre>
    </div>
    
    <!-- Explanation -->
    <div class="explanation">
      <h4>ℹ️ What happens when you run this command?</h4>
      <ol>
        <li>Router fetches configuration from server (HTTPS)</li>
        <li>Configuration saved as config.rsc</li>
        <li>Script automatically imported and executed</li>
        <li>Router connects to management VPN</li>
        <li>Status updates to "Online" automatically</li>
      </ol>
    </div>
    
    <!-- Requirements -->
    <div class="requirements">
      <h4>✓ Requirements</h4>
      <ul>
        <li>Router has internet connectivity</li>
        <li>/tool fetch is enabled (licensed router)</li>
        <li>Can reach wificore.traidsolutions.com</li>
        <li>HTTPS (port 443) not blocked</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  router: Object
})

const showFallback = ref(false)
const showCompleteScript = ref(false)

const copyToClipboard = (text) => {
  navigator.clipboard.writeText(text)
  // Show success notification
}
</script>
```

---

## 📝 COMMIT HISTORY

| Commit | Description |
|--------|-------------|
| `a3ae590` | Remove /tool fetch (inline approach) |
| `ff0a885` | **Implement fetch-based deployment** ✅ |

---

## 🆘 SUPPORT

### Check Logs

```bash
# Backend logs
docker compose -f docker-compose.production.yml logs --tail=100 wificore-backend | grep "fetch"

# Check if fetch endpoint is being called
docker compose -f docker-compose.production.yml logs --tail=100 wificore-backend | grep "Router configuration fetched"
```

### Test Fetch Endpoint

```bash
# Get a valid config token from database
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker

$router = App\Models\Router::first();
echo "Config Token: " . $router->config_token . "\n";
echo "Fetch URL: " . config('app.url') . '/api/routers/' . $router->config_token . '/fetch-config' . "\n";
exit

# Test the URL
curl -k "https://wificore.traidsolutions.com/api/routers/{TOKEN}/fetch-config"
```

---

## ✅ POST-DEPLOYMENT CHECKLIST

- [ ] Code deployed (commit ff0a885)
- [ ] Backend container rebuilt
- [ ] Services restarted
- [ ] Caches cleared
- [ ] Fetch endpoint returns plain text
- [ ] Provisioning command includes `/tool fetch mode=https`
- [ ] Config token is valid UUID
- [ ] URL is correct (https://wificore.traidsolutions.com)
- [ ] Complete script available as fallback
- [ ] Frontend displays minimal command
- [ ] Copy button works
- [ ] Tested on licensed MikroTik router

---

**DEPLOYMENT READY - FETCH-BASED APPROACH IMPLEMENTED**

Commit: `ff0a885`
