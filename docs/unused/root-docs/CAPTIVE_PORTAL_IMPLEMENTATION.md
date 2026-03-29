# Captive Portal & Dynamic Package Loading - Implementation Guide

**Date:** February 6, 2026  
**Status:** ✅ IMPLEMENTED

---

## Overview

This document describes the complete implementation of the tenant-specific captive portal with dynamic package loading for the WiFi Hotspot SaaS system.

---

## Architecture

### Multi-Tenant Domain Structure

**Format:** `https://<tenant_slug>.wificore.traidsolutions.com/api/portal/config`

- Each tenant has a unique subdomain based on their `slug` or `subdomain` field
- Portal URL is dynamically generated during router provisioning
- Walled garden configuration allows portal access without authentication

### Components

1. **Backend API** - Captive portal endpoints for package listing, payment, login
2. **Router Provisioning** - MikroTik configuration with tenant-specific portal URL
3. **FreeRADIUS Integration** - RADIUS-based authentication and accounting
4. **Payment Gateway** - MPesa and voucher support
5. **Billing Enforcement** - Session activation only after payment verification

---

## Phase 1: Tenant Identification

### Database Schema

**Tenant Model** (`app/Models/Tenant.php`):
- `subdomain` - Unique subdomain for tenant (e.g., "acme")
- `slug` - Alternative identifier
- `schema_name` - PostgreSQL schema for tenant isolation (e.g., "ts_abc123")

### URL Generation Logic

**Location:** `ZeroConfigHotspotGenerator.php` (lines 38-49)

```php
// CAPTIVE PORTAL: Generate tenant-specific portal URL
$tenant = $router->tenant;
$baseHost = config('app.base_domain', parse_url(config('app.url'), PHP_URL_HOST));
$captivePortalUrl = null;
$portalHost = null;

if ($tenant && $tenant->subdomain) {
    // Portal URL for login redirect
    $captivePortalUrl = "https://{$tenant->subdomain}.{$baseHost}/api/portal/config?router_id={$router->id}";
    $portalHost = "{$tenant->subdomain}.{$baseHost}";
}
```

**Configuration Required:**
- `config/app.php`: Set `base_domain` to `wificore.traidsolutions.com`
- DNS: Wildcard A record `*.wificore.traidsolutions.com` pointing to server IP

---

## Phase 2: Captive Portal Configuration

### Hotspot Profile Setup

**Location:** `ZeroConfigHotspotGenerator.php::generateHotspotSetup()` (lines 306-332)

**Key Features:**
- Login methods: HTTP-CHAP, HTTP-PAP (secure)
- RADIUS-only authentication (no local users)
- Cookie lifetime: 1 day
- Session timeout: 6 hours
- Addresses per MAC: 2 (multi-device support)
- Idle timeout: 5 minutes
- Keepalive timeout: 2 minutes

**MikroTik Configuration Generated:**

```routeros
# Hotspot Profile
/ip hotspot profile add name="hs-profile-{router_id}-0" \
    hotspot-address={gateway_ip} \
    login-by=http-chap,http-pap \
    use-radius=yes \
    html-directory=hotspot \
    dns-name=hotspot.local \
    http-cookie-lifetime=1d

# Redirect to tenant portal
/file set hotspot/login.html contents="<html><head><meta http-equiv='refresh' content='0;url={portal_url}'></head><body>Redirecting to portal...</body></html>"

# Hotspot Server
/ip hotspot add name="hs-server-{router_id}-0" \
    interface="{bridge_name}" \
    profile="hs-profile-{router_id}-0" \
    address-pool={pool_name} \
    addresses-per-mac=2 \
    idle-timeout=5m \
    keepalive-timeout=2m \
    disabled=no
```

### Walled Garden Configuration

**Location:** `ZeroConfigHotspotGenerator.php::generateWalledGarden()` (lines 384-404)

**Purpose:** Allow users to access the captive portal without authentication to:
- View available packages
- Make payments
- Redeem vouchers

**MikroTik Configuration Generated:**

```routeros
# Walled Garden - Allow Portal Access Without Authentication
/ip hotspot walled-garden remove [find comment="WiFiCore Portal"]
/ip hotspot walled-garden add dst-host={tenant_subdomain}.wificore.traidsolutions.com \
    action=allow \
    comment="WiFiCore Portal"
```

**Critical:** Without walled garden, users cannot access the portal to purchase packages.

---

## Phase 3: Package/Resource Loading

### API Endpoint

**Route:** `GET /api/portal/config`  
**Controller:** `CaptivePortalController::getPortalConfig()`  
**Location:** `app/Http/Controllers/Api/CaptivePortalController.php` (lines 46-142)

**Request Parameters:**
- `router_id` (required) - Router UUID from MikroTik redirect
- `mac` (optional) - Client MAC address
- `ip` (optional) - Client IP address
- `link_login` (optional) - MikroTik login link
- `link_orig` (optional) - Original URL user tried to access

**Response Structure:**

```json
{
  "success": true,
  "data": {
    "tenant": {
      "name": "Acme WiFi",
      "logo": "https://cdn.example.com/logo.png",
      "primary_color": "#3B82F6",
      "support_phone": "+254700000000",
      "support_email": "support@acme.com"
    },
    "router": {
      "id": "uuid",
      "name": "Router 1",
      "location": "Main Office"
    },
    "packages": [
      {
        "id": "uuid",
        "name": "1 Hour - 10Mbps",
        "description": "Fast browsing for 1 hour",
        "price": 50,
        "duration": "1h",
        "upload_speed": "10M",
        "download_speed": "10M",
        "data_limit": null
      }
    ],
    "payment_methods": ["mpesa", "voucher"],
    "client": {
      "mac": "00:11:22:33:44:55",
      "ip": "10.10.10.100"
    }
  }
}
```

### Package Query Logic

**Location:** `CaptivePortalController.php` (lines 88-102)

```php
// Get available packages for this router
$packages = Package::where('is_active', true)
    ->where('is_public', true)
    ->where('type', 'hotspot')
    ->where(function ($query) use ($router) {
        $query->where('is_global', true)
            ->orWhereHas('routers', function ($q) use ($router) {
                $q->where('router_id', $router->id);
            });
    })
    ->orderBy('price', 'asc')
    ->get();
```

**Package Filtering:**
1. **Active packages only** - `is_active = true`
2. **Public packages** - `is_public = true` (visible on portal)
3. **Hotspot type** - `type = 'hotspot'`
4. **Router-specific or global** - Either `is_global = true` OR assigned to this router

**Tenant Isolation:**
- Packages are in **public schema** with `tenant_id` column
- `TenantScope` automatically filters by authenticated tenant
- Router lookup sets tenant context via `TenantContext::setTenant()`

---

## Phase 4: Payment Verification & Session Activation

### Payment Flow

**Endpoint:** `POST /api/portal/payment/initiate`  
**Controller:** `CaptivePortalController::initiatePayment()`  
**Location:** `app/Http/Controllers/Api/CaptivePortalController.php` (lines 263-358)

**Request:**
```json
{
  "router_id": "uuid",
  "package_id": "uuid",
  "phone": "0700000000",
  "payment_method": "mpesa"
}
```

**Process:**
1. Validate request parameters
2. Find router and set tenant context
3. Verify package is active and available
4. Create pending payment record
5. Trigger MPesa STK push (via existing payment service)
6. Return payment reference for status polling

**Response:**
```json
{
  "success": true,
  "message": "Payment initiated. Please complete the payment on your phone.",
  "data": {
    "payment_id": "uuid",
    "reference": "HS123ABC",
    "amount": 50,
    "phone": "254700000000",
    "status": "pending"
  }
}
```

### Payment Status Polling

**Endpoint:** `GET /api/portal/payment/{paymentId}/status`  
**Controller:** `CaptivePortalController::checkPaymentStatus()`

**Response (Pending):**
```json
{
  "success": true,
  "data": {
    "status": "pending",
    "reference": "HS123ABC"
  }
}
```

**Response (Completed):**
```json
{
  "success": true,
  "data": {
    "status": "completed",
    "reference": "HS123ABC",
    "credentials": {
      "username": "hs_user_123",
      "expires_at": "2026-02-07T10:00:00Z"
    }
  }
}
```

### Session Activation

**Trigger:** Payment webhook from MPesa (existing implementation)

**Process:**
1. Payment status updated to `completed`
2. `CreateHotspotUserJob` dispatched
3. User created in `hotspot_users` table (tenant schema)
4. RADIUS credentials created in `radcheck` table (tenant schema)
5. Rate limits set in `radreply` table based on package speed
6. User can now login with generated credentials

**RADIUS Integration:**

```sql
-- Authentication (radcheck table)
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('hs_user_123', 'Cleartext-Password', ':=', 'generated_password');

-- Rate Limit (radreply table)
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_user_123', 'Mikrotik-Rate-Limit', ':=', '10M/10M');

-- Session Timeout (radreply table)
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_user_123', 'Session-Timeout', ':=', '3600');
```

---

## Phase 5: Hybrid Mode Support

### Hybrid Configuration

**Location:** `ZeroConfigHybridGenerator.php`

**Key Changes:**
- Lines 152-166: Tenant-specific portal URL generation
- Lines 194-195: Captive portal redirect in login.html
- Lines 202-203: Walled garden for portal access

**VLAN Separation:**
- Hotspot VLAN: Captive portal with package selection
- PPPoE VLAN: Direct PPPoE authentication (no portal)

**Traffic Isolation:**
- Hotspot and PPPoE VLANs are completely isolated
- No inter-VLAN traffic allowed
- Both VLANs can access WAN independently

**Firewall Rules:**
```routeros
# Block Hotspot -> PPPoE
/ip firewall filter add chain=forward action=drop \
    in-interface=vlan-hotspot-{vlan_id} \
    out-interface=vlan-pppoe-{vlan_id} \
    comment="Hybrid: Block Hotspot->PPPoE"

# Block PPPoE -> Hotspot
/ip firewall filter add chain=forward action=drop \
    in-interface=vlan-pppoe-{vlan_id} \
    out-interface=vlan-hotspot-{vlan_id} \
    comment="Hybrid: Block PPPoE->Hotspot"
```

---

## Phase 6: Billing & Rate Limit Enforcement

### RADIUS AAA Integration

**Authentication:**
- FreeRADIUS queries `radcheck` table in tenant schema
- Username/password verified
- Subscription status checked (must be active)
- Expiration date verified

**Authorization:**
- Rate limits retrieved from `radreply` table
- `Mikrotik-Rate-Limit` attribute sent to router
- Format: `{upload}M/{download}M` (e.g., "10M/10M")

**Accounting:**
- Session start/stop logged to `radacct` table
- Interim updates every 5 minutes
- Data usage tracked
- Session duration tracked

### Rate Limit Application

**MikroTik Side:**
- RADIUS returns `Mikrotik-Rate-Limit` attribute
- Router applies rate limit to user's PPP/Hotspot interface
- No static rate limits in profile (all dynamic from RADIUS)

**Package Configuration:**
```php
// Package model
'upload_speed' => '10M',
'download_speed' => '10M',
'speed' => '10M/10M',  // Combined format
```

**RADIUS Reply:**
```sql
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_user_123', 'Mikrotik-Rate-Limit', ':=', '10M/10M');
```

### Session Timeout Enforcement

**Duration Calculation:**
```php
// CaptivePortalController::calculateExpiry()
if (preg_match('/^(\d+)\s*h/i', $duration, $m)) {
    return now()->addHours((int) $m[1]);
}
if (preg_match('/^(\d+)\s*d/i', $duration, $m)) {
    return now()->addDays((int) $m[1]);
}
```

**RADIUS Enforcement:**
```sql
-- Session timeout in seconds
INSERT INTO radreply (username, attribute, op, value)
VALUES ('hs_user_123', 'Session-Timeout', ':=', '3600');  -- 1 hour
```

---

## Safety & Performance Considerations

### hAP Lite Optimization

**CPU-Friendly Settings:**
- Connection tracking timeout: 1h TCP, 30s UDP
- Session timeout: 6h (prevents excessive re-auth)
- Idle timeout: 5m (frees resources)
- Keepalive timeout: 2m (detects disconnects quickly)
- Addresses per MAC: 2 (reasonable multi-device limit)

**Avoided:**
- Per-user firewall rules (CPU intensive)
- Complex queue trees (use simple queues via RADIUS)
- Excessive logging
- Frequent interim updates (5min is optimal)

### Multi-Tenancy Isolation

**Schema-Based Isolation:**
- Each tenant has dedicated PostgreSQL schema
- `hotspot_users`, `radcheck`, `radreply`, `radacct` in tenant schema
- No cross-tenant data leakage possible

**Tenant Context Setting:**
```php
// Set PostgreSQL search_path
DB::statement("SET search_path TO {$tenant->schema_name}, public");

// All queries now scoped to tenant
$packages = Package::where('is_active', true)->get();
```

**Router-Tenant Mapping:**
- Router model has `tenant_id` foreign key
- Router lookup automatically sets tenant context
- Portal API validates router belongs to tenant

---

## Validation Steps

### 1. Tenant Domain Resolution

```bash
# Test DNS resolution
nslookup acme.wificore.traidsolutions.com

# Expected: Resolves to server IP
```

### 2. Portal API Access

```bash
# Test portal config endpoint
curl "https://acme.wificore.traidsolutions.com/api/portal/config?router_id={uuid}"

# Expected: Returns tenant info and packages
```

### 3. Walled Garden Verification

```bash
# From MikroTik router
/ip hotspot walled-garden print

# Expected: Entry for tenant subdomain with action=allow
```

### 4. RADIUS Authentication

```bash
# Test RADIUS auth
radtest hs_user_123 password localhost 1812 testing123

# Expected: Access-Accept with Mikrotik-Rate-Limit attribute
```

### 5. Payment Flow

```bash
# Initiate payment
curl -X POST "https://acme.wificore.traidsolutions.com/api/portal/payment/initiate" \
  -H "Content-Type: application/json" \
  -d '{"router_id":"uuid","package_id":"uuid","phone":"0700000000","payment_method":"mpesa"}'

# Check status
curl "https://acme.wificore.traidsolutions.com/api/portal/payment/{payment_id}/status?router_id={uuid}"
```

---

## Files Modified

### Backend Services

1. **`app/Services/MikroTik/ZeroConfigHotspotGenerator.php`**
   - Lines 38-49: Tenant-specific portal URL generation
   - Lines 162: Added `portal_host` parameter
   - Lines 313-323: Enhanced hotspot profile with captive portal redirect
   - Lines 384-404: NEW - Walled garden configuration method

2. **`app/Services/MikroTik/ZeroConfigHybridGenerator.php`**
   - Lines 152-166: Tenant-specific portal URL for hybrid mode
   - Lines 194-195: Captive portal redirect
   - Lines 202-203: Walled garden configuration

### Backend Controllers (Already Implemented)

3. **`app/Http/Controllers/Api/CaptivePortalController.php`**
   - Complete captive portal API implementation
   - Package listing, payment, login endpoints

### Configuration

4. **`config/app.php`**
   - Add: `'base_domain' => env('APP_BASE_DOMAIN', 'wificore.traidsolutions.com')`

5. **`.env`**
   - Add: `APP_BASE_DOMAIN=wificore.traidsolutions.com`

---

## Deployment Checklist

- [ ] Configure DNS wildcard: `*.wificore.traidsolutions.com`
- [ ] Set `APP_BASE_DOMAIN` in `.env`
- [ ] Verify SSL certificate covers wildcard domain
- [ ] Test portal access from client device
- [ ] Verify walled garden allows portal access
- [ ] Test package loading on portal
- [ ] Test MPesa payment flow
- [ ] Verify RADIUS authentication after payment
- [ ] Test rate limit enforcement
- [ ] Verify session timeout enforcement
- [ ] Test hybrid mode (if used)
- [ ] Verify multi-tenant isolation

---

## Summary

✅ **Tenant Identification** - Subdomain-based tenant mapping  
✅ **Captive Portal** - Tenant-specific domain with dynamic URL generation  
✅ **Package Loading** - Dynamic package retrieval from tenant schema  
✅ **Payment Integration** - MPesa and voucher support with verification  
✅ **Session Activation** - RADIUS-based authentication after payment  
✅ **Hybrid Mode** - Full support with VLAN separation  
✅ **Billing Enforcement** - Rate limits and session timeouts via RADIUS  
✅ **Multi-Tenancy** - Complete schema-based isolation  
✅ **Performance** - Optimized for hAP Lite devices  

**All logic is implemented in the codebase and ready for deployment via SSH execution framework.**
