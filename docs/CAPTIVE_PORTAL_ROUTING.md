# Captive Portal Routing Configuration

## Overview

The WifiCore system has two distinct user interfaces:

1. **Admin/Tenant Login** - For system administrators and tenant managers
2. **Captive Portal** - For end-users connecting to the WiFi hotspot

## URL Structure

### Public URLs (Admin/Tenant Access)

- `https://wificore.traidsolutions.com/` → Redirects to `/login`
- `https://wificore.traidsolutions.com/login` → Admin/Tenant login page
- `https://wificore.traidsolutions.com/register` → Tenant registration page

**Purpose**: These URLs are for WiFi business owners and administrators to manage their hotspot operations.

### Captive Portal URLs (Hotspot Users)

- `https://wificore.traidsolutions.com/portal` → Captive portal (package selection and hotspot login)
- `https://wificore.traidsolutions.com/hotspot` → Redirects to `/portal`
- `https://wificore.traidsolutions.com/hotspot/login` → Redirects to `/portal`

**Purpose**: These URLs are for end-users who connect to the WiFi hotspot to purchase packages and access the internet.

## How It Works

### 1. Admin/Tenant Access Flow

```
User visits https://wificore.traidsolutions.com/
    ↓
Redirected to /login
    ↓
Shows admin/tenant login page (LoginView.vue)
    ↓
User logs in with tenant credentials
    ↓
Redirected to /dashboard
```

### 2. Hotspot User Access Flow

```
User connects to WiFi hotspot
    ↓
MikroTik router redirects to https://wificore.traidsolutions.com/portal
    ↓
Shows captive portal (PackagesView.vue)
    ↓
User selects package and makes payment
    ↓
User logs in with hotspot credentials
    ↓
MikroTik grants internet access
```

## MikroTik Configuration

Configure your MikroTik router to redirect hotspot users to the captive portal:

### Hotspot Profile Settings

```
/ip hotspot profile
set [find name=default] login-by=http-chap,http-pap \
    http-address=wificore.traidsolutions.com \
    http-path=/portal
```

### Alternative: Use IP Firewall NAT

```
/ip firewall nat
add action=dst-nat chain=dstnat dst-port=80 protocol=tcp \
    to-addresses=<nginx-ip> to-ports=80 \
    comment="Redirect HTTP to captive portal"
```

## Frontend Router Configuration

The routing is configured in `frontend/src/router/index.js`:

```javascript
const routes = [
  // Public admin/tenant login (default homepage)
  { path: '/', name: 'home', redirect: '/login' },
  { path: '/login', name: 'login', component: LoginView },
  
  // Captive Portal - Only accessible for hotspot users
  { path: '/portal', name: 'captive-portal', component: PackagesView },
  { path: '/hotspot', redirect: '/portal' },
  { path: '/hotspot/login', redirect: '/portal' },
]
```

## Security Considerations

### 1. IP-Based Access Control (Optional)

If you want to restrict the captive portal to only hotspot network IPs, add this to nginx:

```nginx
# Restrict /portal to hotspot network only
location /portal {
    # Allow hotspot network (example: 10.5.50.0/24)
    allow 10.5.50.0/24;
    deny all;
    
    # Proxy to frontend
    proxy_pass http://wificore-frontend:80;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

### 2. Public Access (Current Setup)

Currently, `/portal` is publicly accessible. This is acceptable because:

- Hotspot users are redirected there by MikroTik
- Admin users have no reason to visit this page
- The portal requires payment before granting access
- Actual internet access is controlled by MikroTik/FreeRADIUS

## Testing

### Test Admin Login

1. Visit `https://wificore.traidsolutions.com/`
2. Should redirect to `/login`
3. Should show admin/tenant login page with "WifiCore Hotspot Management System"

### Test Captive Portal

1. Visit `https://wificore.traidsolutions.com/portal`
2. Should show captive portal with "TraidNet Solutions High-Speed WiFi Packages"
3. Should display package selection and payment options

## Troubleshooting

### Issue: Root URL shows captive portal instead of admin login

**Cause**: Old router configuration still in place.

**Fix**: Clear browser cache and rebuild frontend:
```bash
cd frontend
npm run build
```

### Issue: Hotspot users see admin login page

**Cause**: MikroTik not configured to redirect to `/portal`.

**Fix**: Update MikroTik hotspot profile:
```
/ip hotspot profile
set [find name=default] http-path=/portal
```

### Issue: 404 error on /portal

**Cause**: Frontend not rebuilt after router changes.

**Fix**: Rebuild and restart frontend container:
```bash
docker compose build wificore-frontend
docker compose up -d wificore-frontend
```

## API Endpoints

### Hotspot Authentication

- **POST** `/api/hotspot/login` - Hotspot user login
- **POST** `/api/hotspot/logout` - Hotspot user logout
- **GET** `/api/packages/public` - Get available packages for purchase

### Admin Authentication

- **POST** `/api/login` - Admin/tenant login
- **POST** `/api/logout` - Admin/tenant logout
- **POST** `/api/register` - Tenant registration

## File Locations

- Frontend Router: `frontend/src/router/index.js`
- Admin Login Page: `frontend/src/modules/common/views/auth/LoginView.vue`
- Captive Portal: `frontend/src/modules/common/views/public/PackagesView.vue`
- Hotspot Controller: `backend/app/Http/Controllers/Api/HotspotController.php`
- Nginx Config: `nginx/nginx.conf`

## Migration Notes

### Before (Old Configuration)

- `/` → Captive portal (PackagesView)
- `/login` → Admin login
- Confusing for administrators

### After (New Configuration)

- `/` → Redirects to `/login` (Admin login)
- `/portal` → Captive portal (PackagesView)
- Clear separation of concerns

## Related Documentation

- [Tenant Registration Flow](./TENANT_REGISTRATION_FLOW.md)
- [Redis Configuration](./REDIS_FIX_DEPLOYMENT.md)
- [MikroTik Integration](./MIKROTIK_INTEGRATION.md) (if exists)
