# Captive Portal URL Format - Test & Verification

## Implementation

### URL Format
```
https://{tenant-subdomain}.wificore.traidsolutions.com/portal
```

### Examples

**Tenant: acme**
```
https://acme.wificore.traidsolutions.com/portal
```

**Tenant: demo**
```
https://demo.wificore.traidsolutions.com/portal
```

**Tenant: testcorp**
```
https://testcorp.wificore.traidsolutions.com/portal
```

---

## Backend Implementation

### ZeroConfigHotspotGenerator.php

```php
// Get tenant for captive portal URL (frontend Vue portal)
// Format: https://tenant.wificore.traidsolutions.com/portal
$tenant = $router->tenant;
if ($tenant) {
    $baseUrl = parse_url(config('app.url'), PHP_URL_HOST);
    $captivePortalUrl = "https://{$tenant->subdomain}.{$baseUrl}/portal";
} else {
    $captivePortalUrl = null;
}
```

**How it works:**
1. Gets `APP_URL` from config: `https://wificore.traidsolutions.com`
2. Extracts hostname: `wificore.traidsolutions.com`
3. Prepends tenant subdomain: `acme.wificore.traidsolutions.com`
4. Adds `/portal` path: `https://acme.wificore.traidsolutions.com/portal`

---

## Frontend Implementation

### PackagesView.vue - Subdomain Detection

```javascript
const getSubdomain = () => {
  const hostname = window.location.hostname
  const parts = hostname.split('.')
  
  // For localhost development
  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    return route.query.subdomain || 'demo'
  }
  
  // For production, extract first part of domain
  // Example: acme.wificore.traidsolutions.com -> 'acme'
  if (parts.length >= 3) {
    return parts[0]
  }
  
  return null
}
```

**How it works:**
1. URL: `https://acme.wificore.traidsolutions.com/portal`
2. Hostname: `acme.wificore.traidsolutions.com`
3. Split by `.`: `['acme', 'wificore', 'traidsolutions', 'com']`
4. Extract first part: `acme`
5. Load tenant branding for `acme`

---

## MikroTik Hotspot Flow

### Step-by-Step

1. **User connects to WiFi**
   - SSID: "Acme Guest WiFi"
   - No authentication yet

2. **MikroTik detects unauthenticated user**
   - Captures HTTP requests
   - Triggers hotspot redirect

3. **MikroTik redirects to captive portal**
   ```
   https://acme.wificore.traidsolutions.com/portal?mac=00:11:22:33:44:55&ip=192.168.1.100&link-login=http://192.168.1.1/login&link-orig=http://www.google.com
   ```

4. **Frontend Vue portal loads**
   - Detects subdomain: `acme`
   - Loads tenant branding from API: `/public/tenant/acme`
   - Parses MikroTik parameters from URL
   - Displays branded login form

5. **User enters credentials**
   - Username: `john.doe`
   - Password: `********`

6. **Portal submits to MikroTik**
   - Creates hidden form
   - Action: `http://192.168.1.1/login` (from `link-login`)
   - Fields: `username`, `password`, `dst` (original URL)
   - Submits form

7. **MikroTik validates via RADIUS**
   - Sends authentication request to FreeRADIUS
   - RADIUS checks credentials in database
   - Returns Accept/Reject

8. **User authenticated**
   - MikroTik creates hotspot session
   - Redirects to original URL (`link-orig`)
   - User has internet access

---

## DNS Configuration Required

### Wildcard DNS Record

To support tenant subdomains, you need a wildcard DNS record:

```
*.wificore.traidsolutions.com  ->  A  ->  YOUR_SERVER_IP
```

**Example:**
```
*.wificore.traidsolutions.com  ->  A  ->  104.21.45.123
```

This allows:
- `acme.wificore.traidsolutions.com` ✅
- `demo.wificore.traidsolutions.com` ✅
- `testcorp.wificore.traidsolutions.com` ✅
- `any-tenant.wificore.traidsolutions.com` ✅

### Verification

```bash
# Test DNS resolution
nslookup acme.wificore.traidsolutions.com
nslookup demo.wificore.traidsolutions.com
nslookup testcorp.wificore.traidsolutions.com

# All should resolve to the same IP
```

---

## Nginx Configuration

### Virtual Host for Wildcard Subdomain

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name *.wificore.traidsolutions.com wificore.traidsolutions.com;
    
    # Redirect to HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name *.wificore.traidsolutions.com wificore.traidsolutions.com;
    
    # SSL certificate (wildcard cert required)
    ssl_certificate /etc/letsencrypt/live/wificore.traidsolutions.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/wificore.traidsolutions.com/privkey.pem;
    
    # Root directory
    root /var/www/wificore/frontend/dist;
    index index.html;
    
    # Frontend SPA routing
    location / {
        try_files $uri $uri/ /index.html;
    }
    
    # Backend API proxy
    location /api {
        proxy_pass http://wificore-backend:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

---

## SSL Certificate

### Wildcard Certificate Required

You need a wildcard SSL certificate to support all tenant subdomains:

```bash
# Using Certbot with DNS challenge
certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials ~/.secrets/cloudflare.ini \
  -d wificore.traidsolutions.com \
  -d *.wificore.traidsolutions.com
```

**Or manually:**
```bash
certbot certonly \
  --manual \
  --preferred-challenges dns \
  -d wificore.traidsolutions.com \
  -d *.wificore.traidsolutions.com
```

---

## Testing

### 1. Test Backend URL Generation

```bash
# SSH into backend container
docker exec -it wificore-backend bash

# Run tinker
php artisan tinker

# Test URL generation
>>> $router = App\Models\Router::with('tenant')->first();
>>> $tenant = $router->tenant;
>>> $baseUrl = parse_url(config('app.url'), PHP_URL_HOST);
>>> $captivePortalUrl = "https://{$tenant->subdomain}.{$baseUrl}/portal";
>>> echo $captivePortalUrl;
# Should output: https://acme.wificore.traidsolutions.com/portal
```

### 2. Test Frontend Subdomain Detection

```javascript
// Open browser console on: https://acme.wificore.traidsolutions.com/portal
console.log(window.location.hostname)
// Output: acme.wificore.traidsolutions.com

const parts = window.location.hostname.split('.')
console.log(parts[0])
// Output: acme
```

### 3. Test Full Flow (Manual)

```bash
# 1. Visit captive portal URL
https://demo.wificore.traidsolutions.com/portal

# 2. Check if tenant branding loads
# - Company name should change
# - Colors should apply
# - Logo should display (if configured)

# 3. Test with MikroTik parameters
https://demo.wificore.traidsolutions.com/portal?mac=00:11:22:33:44:55&link-login=http://192.168.1.1/login

# 4. Check console logs
# Should see: "MikroTik hotspot mode detected"
```

### 4. Test with Real MikroTik

```routeros
# Configure hotspot on MikroTik
/ip hotspot profile
set [find] html-directory=hotspot

# Test by connecting device to WiFi
# Should redirect to: https://tenant.wificore.traidsolutions.com/portal
```

---

## Troubleshooting

### Issue: Subdomain not resolving

**Check:**
```bash
# DNS resolution
nslookup acme.wificore.traidsolutions.com

# Ping test
ping acme.wificore.traidsolutions.com
```

**Solution:**
- Add wildcard DNS record: `*.wificore.traidsolutions.com`
- Wait for DNS propagation (up to 24 hours)
- Clear DNS cache: `ipconfig /flushdns` (Windows) or `sudo systemd-resolve --flush-caches` (Linux)

### Issue: SSL certificate error

**Check:**
```bash
# Test SSL
openssl s_client -connect acme.wificore.traidsolutions.com:443 -servername acme.wificore.traidsolutions.com
```

**Solution:**
- Ensure wildcard certificate is installed
- Certificate must cover `*.wificore.traidsolutions.com`
- Restart Nginx after certificate installation

### Issue: Tenant branding not loading

**Check:**
```bash
# Test API endpoint
curl https://wificore.traidsolutions.com/api/public/tenant/acme

# Check backend logs
docker logs wificore-backend
```

**Solution:**
- Verify tenant exists in database
- Check tenant has `subdomain` field set
- Ensure API route is accessible
- Clear cache: `php artisan cache:clear`

### Issue: MikroTik not redirecting

**Check:**
```routeros
# Check hotspot configuration
/ip hotspot print detail

# Check hotspot profile
/ip hotspot profile print detail
```

**Solution:**
- Ensure hotspot is enabled
- Verify hotspot profile is configured
- Check RADIUS server is reachable
- Test connectivity: `/ping 172.70.0.2`

---

## Environment Variables

### Required Configuration

```env
# .env
APP_URL=https://wificore.traidsolutions.com
FRONTEND_URL=https://wificore.traidsolutions.com
SESSION_DOMAIN=.traidsolutions.com
SANCTUM_STATEFUL_DOMAINS=wificore.traidsolutions.com,*.wificore.traidsolutions.com
```

**Important:**
- `SESSION_DOMAIN` must start with `.` to support subdomains
- `SANCTUM_STATEFUL_DOMAINS` must include wildcard `*.wificore.traidsolutions.com`

---

## Summary

✅ **Backend generates URL**: `https://tenant.wificore.traidsolutions.com/portal`  
✅ **Frontend detects subdomain**: Extracts `tenant` from hostname  
✅ **Loads tenant branding**: Via `/api/public/tenant/{subdomain}`  
✅ **Handles MikroTik parameters**: Parses from URL query string  
✅ **Submits to MikroTik**: Direct form submission to `link-login`  

**Requirements:**
- ✅ Wildcard DNS record
- ✅ Wildcard SSL certificate
- ✅ Nginx configured for subdomains
- ✅ Session domain configured

**Next Steps:**
1. Configure wildcard DNS
2. Install wildcard SSL certificate
3. Test with real tenant subdomain
4. Test with MikroTik router
5. Monitor authentication success rate

---

**Last Updated**: January 14, 2026  
**Status**: Implemented & Ready for Testing
