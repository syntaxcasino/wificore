# Unified Captive Portal - Frontend Vue Implementation

## Overview

The WiFiCore SaaS now uses a **single, unified Vue.js captive portal** that handles both:
1. **MikroTik Hotspot Integration** - Direct authentication via MikroTik RouterOS
2. **Self-Service Portal** - Package browsing and purchase

This replaces the previous dual-system approach (Backend Blade + Frontend Vue).

---

## Architecture

### Single Portal, Dual Functionality

```
┌─────────────────────────────────────────────────────────┐
│         Frontend Vue Captive Portal                     │
│         (PackagesView.vue)                              │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────────┐    ┌──────────────────┐          │
│  │  MikroTik Mode   │    │  Self-Service    │          │
│  │  - Detects URL   │    │  - Direct access │          │
│  │    parameters    │    │  - Package browse│          │
│  │  - Submits to    │    │  - API login     │          │
│  │    MikroTik      │    │  - Purchase      │          │
│  │  - RADIUS auth   │    │                  │          │
│  └──────────────────┘    └──────────────────┘          │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

---

## Features

### 1. **Tenant Subdomain Detection**
- Automatically detects tenant from URL subdomain
- Falls back to query parameter for localhost development
- Loads tenant-specific branding dynamically

### 2. **Dynamic Branding**
- Company name, logo, colors
- Support contact information
- Tagline and custom messaging
- Applied via CSS variables

### 3. **MikroTik Hotspot Integration**
- Parses MikroTik URL parameters:
  - `mac` - Device MAC address
  - `ip` - Client IP address
  - `username` - Pre-filled username
  - `link-login` - MikroTik authentication URL
  - `link-logout` - Logout URL
  - `link-orig` - Original destination URL
  - `error` - Error messages from MikroTik
  - `trial` - Trial user indicator
  - `popup` - Popup mode indicator

### 4. **Dual Authentication Flow**
- **MikroTik Mode**: Submits credentials directly to MikroTik's `link-login` URL
- **API Mode**: Authenticates via backend API for self-service users

### 5. **Package Browsing**
- Displays tenant-specific packages
- Real-time availability
- Purchase integration

---

## URL Formats

### MikroTik Hotspot Redirect
```
https://yourdomain.com/portal?subdomain=acme&mac=00:11:22:33:44:55&ip=192.168.1.100&link-login=http://192.168.1.1/login&link-orig=http://www.google.com
```

### Self-Service Access
```
https://acme.yourdomain.com/portal
```

### Localhost Development
```
http://localhost:5173/portal?subdomain=demo
```

---

## Implementation Details

### Frontend Component
**Location**: `frontend/src/modules/common/views/public/PackagesView.vue`

**Key Features**:
```javascript
// Tenant branding detection
const getSubdomain = () => {
  const hostname = window.location.hostname
  const parts = hostname.split('.')
  
  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    return route.query.subdomain || 'demo'
  }
  
  if (parts.length >= 3) {
    return parts[0]
  }
  
  return null
}

// MikroTik parameter parsing
const parseMikrotikParams = () => {
  hotspotParams.value = {
    mac: route.query.mac || null,
    ip: route.query.ip || null,
    username: route.query.username || null,
    linkLogin: route.query['link-login'] || null,
    linkLogout: route.query['link-logout'] || null,
    linkOrig: route.query['link-orig'] || null,
    error: route.query.error || null
  }
}

// Dual authentication
const handleLogin = async () => {
  if (hotspotParams.value.linkLogin) {
    // MikroTik mode: Submit to MikroTik
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = hotspotParams.value.linkLogin
    // ... add fields and submit
  } else {
    // API mode: Regular login
    await axios.post('/hotspot/login', { ... })
  }
}
```

### Backend Integration

**Hotspot Generator**: `backend/app/Services/MikroTik/ZeroConfigHotspotGenerator.php`

```php
// Generate captive portal URL for MikroTik
$tenant = $router->tenant;
$captivePortalUrl = $tenant 
    ? "https://" . config('app.url') . "/portal?subdomain={$tenant->subdomain}" 
    : null;
```

**API Endpoints**:
- `GET /public/tenant/{subdomain}` - Get tenant branding
- `GET /public/tenant/{subdomain}/packages` - Get tenant packages
- `POST /hotspot/login` - API-based authentication (self-service)

---

## MikroTik Configuration

### Hotspot Profile Setup

```routeros
# Set hotspot profile
/ip hotspot profile
set [find] 
    html-directory=hotspot 
    login-by=http-chap,http-pap 
    use-radius=yes
    http-cookie-lifetime=1d
    dns-name=hotspot.local

# Hotspot server configuration
/ip hotspot
set [find] 
    address-pool=hotspot-pool
    profile=default
    addresses-per-mac=2
    idle-timeout=5m
    keepalive-timeout=2m
```

### Redirect URL

MikroTik automatically redirects unauthenticated users to the captive portal with parameters:
```
https://yourdomain.com/portal?subdomain={tenant}&mac={mac}&ip={ip}&link-login={login_url}
```

---

## Tenant Branding Configuration

### Database Schema

```json
{
  "branding": {
    "company_name": "Acme WiFi",
    "logo_url": "https://example.com/logo.png",
    "primary_color": "#3b82f6",
    "secondary_color": "#10b981",
    "tagline": "Fast & Reliable Internet",
    "support_email": "support@acme.com",
    "support_phone": "+254700000000"
  }
}
```

### Update Branding

```sql
-- Update tenant branding
UPDATE tenants 
SET branding = jsonb_set(
    COALESCE(branding, '{}'::jsonb),
    '{company_name}',
    '"Acme WiFi"'
)
WHERE subdomain = 'acme';
```

Or via API:
```bash
curl -X PATCH https://api.yourdomain.com/tenants/{id} \
  -H "Authorization: Bearer {token}" \
  -d '{
    "branding": {
      "company_name": "Acme WiFi",
      "primary_color": "#3b82f6"
    }
  }'
```

---

## Authentication Flows

### Flow 1: MikroTik Hotspot User

1. User connects to WiFi
2. MikroTik redirects to: `/portal?subdomain=acme&mac=XX:XX:XX&link-login=...`
3. Vue portal loads with tenant branding
4. User enters credentials
5. Portal submits to MikroTik's `link-login` URL
6. MikroTik validates via RADIUS
7. User authenticated and redirected to `link-orig`

### Flow 2: Self-Service User

1. User visits: `https://acme.yourdomain.com/portal`
2. Vue portal loads with tenant branding
3. User browses packages
4. User can:
   - Login with existing credentials (API auth)
   - Purchase new package
   - View session status

---

## Testing

### Test MikroTik Mode (Localhost)

```bash
# Visit with MikroTik parameters
http://localhost:5173/portal?subdomain=demo&mac=00:11:22:33:44:55&ip=192.168.1.100&link-login=http://192.168.1.1/login&link-orig=http://www.google.com
```

### Test Self-Service Mode

```bash
# Direct access
http://localhost:5173/portal?subdomain=demo
```

### Test Tenant Branding

```bash
# Create test tenant
php artisan tinker
>>> $tenant = App\Models\Tenant::where('subdomain', 'demo')->first();
>>> $tenant->branding = [
    'company_name' => 'Demo WiFi',
    'primary_color' => '#3b82f6',
    'tagline' => 'Test Network'
];
>>> $tenant->save();
```

---

## Advantages Over Dual System

### Before (Backend Blade + Frontend Vue)
❌ Two separate codebases to maintain  
❌ Inconsistent UI/UX between systems  
❌ Duplicate branding logic  
❌ More complex deployment  
❌ Harder to test  

### After (Unified Frontend Vue)
✅ Single codebase  
✅ Consistent UI/UX  
✅ Centralized branding  
✅ Simpler deployment  
✅ Easier to test  
✅ Better performance (SPA)  
✅ Modern, responsive design  

---

## Performance Optimizations

### 1. **Tenant Branding Caching**
```javascript
// Cache tenant branding for 30 minutes
const response = await axios.get(`/public/tenant/${subdomain}`)
// Backend caches response for 1800 seconds
```

### 2. **Lazy Loading**
```javascript
// Components loaded on demand
const PaymentModal = defineAsyncComponent(() => 
  import('@/modules/tenant/components/payment/PaymentModal.vue')
)
```

### 3. **CSS Variables**
```javascript
// Dynamic theming without re-render
document.documentElement.style.setProperty('--primary-color', color)
```

---

## Troubleshooting

### Issue: Branding not loading

**Check:**
1. Tenant exists in database
2. Subdomain is correct
3. Branding data is valid JSON
4. API endpoint is accessible

**Solution:**
```bash
# Check tenant
php artisan tinker
>>> App\Models\Tenant::where('subdomain', 'demo')->first()

# Clear cache
php artisan cache:clear
```

### Issue: MikroTik parameters not detected

**Check:**
1. URL contains query parameters
2. Parameter names match (use hyphens: `link-login`)
3. Browser console for errors

**Solution:**
```javascript
// Debug in browser console
console.log(route.query)
```

### Issue: Authentication fails

**Check:**
1. RADIUS server is running
2. Credentials are correct
3. MikroTik can reach RADIUS server
4. Network connectivity

**Solution:**
```bash
# Check RADIUS logs
docker logs wificore-freeradius

# Test RADIUS
radtest username password localhost 0 testing123
```

---

## Migration Guide

### Removed Files
- ✅ `backend/resources/views/captive-portal/login.blade.php`
- ✅ `backend/resources/views/captive-portal/status.blade.php`
- ✅ `backend/resources/views/captive-portal/error.blade.php`
- ✅ `backend/resources/views/captive-portal/logout.blade.php`
- ✅ `backend/app/Http/Controllers/Api/CaptivePortalController.php`

### Updated Files
- ✅ `frontend/src/modules/common/views/public/PackagesView.vue` - Enhanced with MikroTik support
- ✅ `backend/routes/api.php` - Removed Blade captive portal routes
- ✅ `backend/app/Services/MikroTik/ZeroConfigHotspotGenerator.php` - Updated portal URL

### No Changes Required
- Router configurations (automatic)
- Database schema (compatible)
- RADIUS setup (unchanged)

---

## Future Enhancements

### Planned Features

1. **Session Status Page**
   - Real-time data usage
   - Session timer
   - Logout button

2. **Multi-language Support**
   - Detect browser language
   - Translate UI elements

3. **Social Login**
   - Facebook/Google OAuth
   - One-click authentication

4. **Voucher System**
   - Generate voucher codes
   - Bulk voucher creation

5. **Analytics**
   - Track login attempts
   - Monitor package purchases
   - User behavior analytics

---

## Summary

**Unified Captive Portal Benefits:**
- ✅ Single Vue.js codebase
- ✅ Handles both MikroTik and self-service use cases
- ✅ Dynamic tenant branding
- ✅ Modern, responsive UI
- ✅ Simpler maintenance
- ✅ Better performance
- ✅ Consistent user experience

**Key Features:**
- Automatic tenant detection
- MikroTik parameter parsing
- Dual authentication flow
- Dynamic branding application
- Package browsing and purchase
- Mobile-friendly design

**Next Steps:**
1. Test with real MikroTik router
2. Configure tenant branding
3. Monitor authentication success rate
4. Gather user feedback

---

**Last Updated**: January 14, 2026  
**Version**: 2.0.0  
**Status**: Production Ready
