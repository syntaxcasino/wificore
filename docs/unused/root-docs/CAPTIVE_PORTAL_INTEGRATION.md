# Captive Portal Integration Guide

## Overview

The captive portal system has two components:
1. **Backend Blade Templates** - For MikroTik hotspot integration (tenant-branded)
2. **Frontend Vue Component** - For package selection and user login (PackagesView.vue)

---

## Architecture

### Flow Diagram

```
User connects to WiFi
    ↓
MikroTik Hotspot redirects to captive portal
    ↓
Backend: /captive-portal/{subdomain} (Blade template)
    ↓
User authenticates via RADIUS
    ↓
[Optional] Redirect to Frontend: /portal (PackagesView.vue)
    ↓
User selects package and purchases
```

---

## Backend Captive Portal (MikroTik Integration)

### Purpose
Serves tenant-branded login pages directly to MikroTik hotspot users.

### Routes
```php
// backend/routes/api.php
Route::prefix('captive-portal')->name('captive-portal.')->group(function () {
    Route::get('/{subdomain}', [CaptivePortalController::class, 'loginPage'])->name('login');
    Route::post('/{subdomain}/auth', [CaptivePortalController::class, 'authenticate'])->name('authenticate');
    Route::get('/{subdomain}/status', [CaptivePortalController::class, 'statusPage'])->name('status');
    Route::get('/{subdomain}/logout', [CaptivePortalController::class, 'logout'])->name('logout');
});
```

### MikroTik Configuration

To configure MikroTik to use the captive portal:

```routeros
# Set hotspot profile to use custom login page
/ip hotspot profile
set [find] 
    html-directory=hotspot 
    login-by=http-chap,http-pap 
    use-radius=yes
    
# Optional: Set custom login URL (if using external server)
/ip hotspot profile
set [find] 
    http-cookie-lifetime=1d
    login-by=http-chap,http-pap
```

### URL Format
```
https://{tenant-subdomain}.yourdomain.com/captive-portal/{tenant-subdomain}
```

Example:
```
https://acme.wificore.com/captive-portal/acme
```

### Tenant Branding

Branding is configured in the `tenants` table:

```json
{
  "branding": {
    "logo_url": "https://example.com/logo.png",
    "primary_color": "#3b82f6",
    "secondary_color": "#10b981",
    "company_name": "Acme WiFi",
    "tagline": "Fast & Reliable Internet",
    "support_email": "support@acme.com",
    "support_phone": "+254700000000",
    "background_image": "https://example.com/bg.jpg",
    "terms_url": "https://acme.com/terms",
    "privacy_url": "https://acme.com/privacy"
  }
}
```

---

## Frontend Captive Portal (PackagesView.vue)

### Purpose
Provides a modern Vue.js interface for:
- Package browsing
- User login
- Package purchase
- Session management

### Current Route
```javascript
// frontend/src/router/index.js
{ path: '/portal', name: 'captive-portal', component: PackagesView },
{ path: '/hotspot', redirect: '/portal' },
{ path: '/hotspot/login', redirect: '/portal' },
```

### Location
```
frontend/src/modules/common/views/public/PackagesView.vue
```

### Features
- Tenant-aware package display
- Integrated login form
- Responsive design
- Real-time session status

---

## Integration Steps

### Step 1: Configure Hotspot Generator

The `ZeroConfigHotspotGenerator` already includes captive portal URL:

```php
// backend/app/Services/MikroTik/ZeroConfigHotspotGenerator.php
$tenant = $router->tenant;
$captivePortalUrl = $tenant 
    ? "https://{$tenant->subdomain}." . config('app.base_domain') . "/captive-portal/{$tenant->subdomain}" 
    : null;
```

### Step 2: Update Hotspot Profile Configuration

Add to hotspot profile generation:

```php
private function generateHotspotSetup(array $params): array
{
    $profile = "hs-profile-{$params['router_id']}";
    $server = "hs-server-{$params['router_id']}";
    $captivePortalUrl = $params['captive_portal_url'] ?? null;
    
    return [
        "# Hotspot Profile",
        "/ip hotspot profile remove [find name=\"{$profile}\"]",
        "/ip hotspot profile add name={$profile} " .
            "hotspot-address={$params['gateway_ip']} " .
            "login-by=http-chap,http-pap " .
            "use-radius=yes " .
            "html-directory=hotspot " .
            ($captivePortalUrl ? "http-cookie-lifetime=1d " : "") .
            "dns-name=hotspot.local",
        "",
    ];
}
```

### Step 3: Frontend Integration (Optional)

To make PackagesView tenant-aware:

```vue
<script setup>
import { ref, onMounted } from 'vue'
import { useTenant } from '@/composables/useTenant'

const { getTenantBySubdomain } = useTenant()
const tenant = ref(null)

// Extract subdomain from URL
const getSubdomain = () => {
  const hostname = window.location.hostname
  const parts = hostname.split('.')
  
  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    return 'demo' // Default for local dev
  }
  
  if (parts.length >= 3) {
    return parts[0]
  }
  
  return null
}

onMounted(async () => {
  const subdomain = getSubdomain()
  if (subdomain) {
    try {
      tenant.value = await getTenantBySubdomain(subdomain)
      // Apply tenant branding
      applyBranding(tenant.value.branding)
    } catch (error) {
      console.error('Failed to load tenant:', error)
    }
  }
})

const applyBranding = (branding) => {
  if (!branding) return
  
  document.documentElement.style.setProperty('--primary-color', branding.primary_color)
  document.documentElement.style.setProperty('--secondary-color', branding.secondary_color)
}
</script>
```

---

## Authentication Flow

### Backend Blade Template Flow

1. User connects to WiFi
2. MikroTik redirects to: `/captive-portal/{subdomain}?mac=XX:XX:XX&ip=192.168.1.100&link-login=...`
3. Backend serves branded login page
4. User enters credentials
5. Form submits to MikroTik's `link-login` URL
6. MikroTik validates via RADIUS
7. User is authenticated and redirected

### Frontend Vue Flow

1. User visits `/portal` directly or via redirect
2. PackagesView loads available packages
3. User can:
   - Browse packages
   - Login with existing credentials
   - Purchase new package
4. After login, session is managed via RADIUS

---

## Configuration Files

### Environment Variables

```env
# .env
APP_URL=https://yourdomain.com
APP_BASE_DOMAIN=yourdomain.com

RADIUS_SERVER_IP=172.70.0.2
RADIUS_SECRET=testing123
```

### Router Configuration

```php
// config/app.php
'base_domain' => env('APP_BASE_DOMAIN', 'yourdomain.com'),
```

---

## Testing

### Test Backend Captive Portal

```bash
# Visit in browser
https://demo.yourdomain.com/captive-portal/demo

# With MikroTik parameters
https://demo.yourdomain.com/captive-portal/demo?mac=00:11:22:33:44:55&ip=192.168.1.100
```

### Test Frontend Portal

```bash
# Visit in browser
https://yourdomain.com/portal
```

### Test with MikroTik

1. Configure hotspot on MikroTik
2. Connect device to WiFi
3. Should redirect to captive portal
4. Login with RADIUS credentials
5. Verify authentication

---

## Troubleshooting

### Issue: Captive portal not loading

**Check:**
1. DNS resolution for subdomain
2. Nginx/Apache virtual host configuration
3. Laravel routes registered
4. Tenant exists in database

**Solution:**
```bash
# Check tenant exists
php artisan tinker
>>> App\Models\Tenant::where('subdomain', 'demo')->first()

# Clear route cache
php artisan route:clear
php artisan route:cache
```

### Issue: Branding not showing

**Check:**
1. Tenant branding data in database
2. Image URLs are accessible
3. Browser console for errors

**Solution:**
```sql
-- Update tenant branding
UPDATE tenants 
SET branding = '{"logo_url": "https://example.com/logo.png", "primary_color": "#3b82f6"}'
WHERE subdomain = 'demo';
```

### Issue: Authentication fails

**Check:**
1. RADIUS server is running
2. RADIUS secret matches
3. User exists in RADIUS database
4. MikroTik can reach RADIUS server

**Solution:**
```bash
# Check RADIUS logs
docker logs wificore-freeradius

# Test RADIUS authentication
radtest username password localhost 0 testing123
```

---

## Best Practices

### 1. Use HTTPS
Always use HTTPS for captive portal to protect credentials.

### 2. Cache Tenant Data
Tenant branding is cached for 30 minutes to reduce database queries.

### 3. Optimize Images
Use optimized images for logos and backgrounds (WebP format, < 100KB).

### 4. Mobile-First Design
Captive portal is responsive and mobile-friendly.

### 5. Error Handling
Graceful error messages for invalid credentials or network issues.

---

## Future Enhancements

### Planned Features

1. **Social Login**
   - Facebook/Google OAuth
   - One-click authentication

2. **Package Vouchers**
   - Generate voucher codes
   - Bulk voucher creation

3. **Multi-language Support**
   - Detect browser language
   - Translate UI elements

4. **Analytics**
   - Track login attempts
   - Monitor package purchases
   - User behavior analytics

5. **Custom Themes**
   - Theme builder in admin panel
   - Preview before publishing

---

## Summary

**Backend Captive Portal:**
- ✅ Tenant-branded Blade templates
- ✅ MikroTik hotspot integration
- ✅ RADIUS authentication
- ✅ Session status tracking

**Frontend Portal:**
- ✅ Vue.js package browser
- ✅ Integrated login form
- ✅ Responsive design
- 🔄 Tenant branding integration (optional)

**Next Steps:**
1. Test backend captive portal with real tenant
2. Configure MikroTik to use captive portal URL
3. Optionally integrate tenant branding in frontend PackagesView
4. Monitor authentication success rate

---

**Last Updated**: January 14, 2026  
**Version**: 1.0.0  
**Status**: Production Ready
