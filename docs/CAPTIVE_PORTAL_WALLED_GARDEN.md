# Captive Portal & Walled Garden Configuration

## Overview

This document explains how to configure the WifiCore system for tenant-specific captive portal access with walled garden functionality. The captive portal ensures that hotspot users can only access the login page and specified domains until they authenticate.

## Architecture

### Components

1. **FreeRADIUS** - Handles authentication and authorization
2. **MikroTik Router** - Enforces walled garden rules and redirects to captive portal
3. **Nginx** - Routes requests to tenant-specific captive portal pages
4. **Backend API** - Handles tenant detection and authentication
5. **Frontend** - Serves tenant-specific login pages

## Walled Garden Configuration

### What is Walled Garden?

A walled garden is a list of domains and IP addresses that hotspot users can access **before authentication**. This typically includes:

- Captive portal domain (login page)
- Payment gateway domains
- DNS servers
- CDN domains for assets

### Current Implementation

The system automatically configures walled garden rules when deploying hotspot configuration to MikroTik routers.

**File:** `backend/app/Services/MikroTik/SecurityHardeningService.php`

**Default Walled Garden Hosts:**
```php
$hosts = [
    'wificore.traidsolutions.com' => 'Captive Portal',
    '*.googleapis.com' => 'Google APIs',
    '*.gstatic.com' => 'Google Static',
    '*.cloudflare.com' => 'Cloudflare CDN',
    '*.cloudfront.net' => 'AWS CloudFront',
];
```

**Default Walled Garden IPs:**
```php
$ips = [
    '8.8.8.8' => 'Google DNS',
    '1.1.1.1' => 'Cloudflare DNS',
    '8.8.4.4' => 'Google DNS Secondary',
];
```

## Tenant-Specific Captive Portal

### How It Works

1. **User connects to hotspot** - Gets IP from DHCP
2. **Tries to access any website** - MikroTik redirects to captive portal
3. **Captive portal detects tenant** - Based on router IP or subdomain
4. **Shows tenant-specific login page** - With tenant branding and packages
5. **User authenticates** - Via RADIUS through backend API
6. **Access granted** - User can access internet

### Tenant Detection Methods

**Priority Order:**

1. **Router IP** - Most reliable for hotspot users
2. **Subdomain** - e.g., `tenant-slug.wificore.traidsolutions.com`
3. **Query Parameter** - `?tenant_id=xxx` (for testing)
4. **Session** - Previously detected tenant

**Implementation:** `backend/app/Http/Controllers/Api/PublicPackageController.php`

## Configuration Steps

### 1. Update Walled Garden Domain

Update the walled garden configuration to use your production domain:

**File:** `backend/app/Services/MikroTik/SecurityHardeningService.php`

```php
$hosts = [
    'wificore.traidsolutions.com' => 'Captive Portal',
    // Add payment gateway domains if needed
    '*.pesapal.com' => 'Payment Gateway',
    '*.mpesa.com' => 'M-Pesa Gateway',
    // Keep CDN domains for assets
    '*.googleapis.com' => 'Google APIs',
    '*.gstatic.com' => 'Google Static',
    '*.cloudflare.com' => 'Cloudflare CDN',
];
```

### 2. Configure MikroTik Hotspot

When deploying hotspot configuration, ensure the portal URL points to your domain:

**File:** `backend/app/Services/MikroTik/ConfigurationService.php`

```php
$options = [
    'portal_url' => 'https://wificore.traidsolutions.com/hotspot/login',
    // ... other options
];
```

### 3. Update Environment Variables

Ensure your production `.env` file has the correct domain:

```bash
APP_URL=https://wificore.traidsolutions.com
API_BASE_URL=https://wificore.traidsolutions.com/api
```

### 4. Nginx Configuration

The nginx configuration should route hotspot requests to the frontend:

```nginx
# Captive portal routes
location /hotspot {
    try_files $uri $uri/ /index.html;
}

# API routes
location /api {
    proxy_pass http://wificore-backend:9000;
    # ... proxy headers
}
```

## Default Login Page Behavior

### Current Implementation

The system has public routes for unauthenticated hotspot users:

**Routes:**
- `GET /api/public/packages` - Get tenant-specific packages
- `POST /api/hotspot/login` - Authenticate hotspot user
- `POST /api/hotspot/logout` - Logout hotspot user
- `POST /api/hotspot/check-session` - Check active session

### Frontend Login Page

The frontend should have a hotspot login page at `/hotspot/login` that:

1. Detects the tenant automatically
2. Displays tenant-specific branding
3. Shows available packages
4. Handles authentication
5. Redirects to success page after login

## Testing Walled Garden

### 1. Connect to Hotspot

Connect a device to the hotspot WiFi network.

### 2. Check Walled Garden Access

Before authentication, you should be able to access:
- ✅ `https://wificore.traidsolutions.com` (captive portal)
- ✅ DNS queries (8.8.8.8, 1.1.1.1)
- ❌ Any other website (should redirect to captive portal)

### 3. Verify Tenant Detection

Access the captive portal and verify:
- Correct tenant is detected
- Tenant-specific packages are displayed
- Tenant branding is shown

### 4. Test Authentication

1. Purchase a package or use test credentials
2. Login through captive portal
3. Verify internet access is granted
4. Check RADIUS logs for authentication records

## Security Considerations

### 1. Walled Garden Scope

**Keep walled garden minimal:**
- Only add domains absolutely necessary for login/payment
- Avoid adding broad wildcards that could be abused
- Regularly audit walled garden rules

### 2. HTTPS Enforcement

**Always use HTTPS for captive portal:**
- Prevents credential interception
- Ensures secure payment processing
- Required for modern browsers

### 3. Session Management

**Implement proper session handling:**
- Set appropriate session timeouts
- Invalidate sessions on logout
- Track concurrent sessions per user

### 4. Rate Limiting

**Protect against abuse:**
- Limit login attempts per IP
- Rate limit package queries
- Monitor for suspicious patterns

## Troubleshooting

### Issue: Users Not Redirected to Captive Portal

**Check:**
1. MikroTik hotspot is enabled on interface
2. DNS is configured correctly
3. Firewall NAT rules are in place
4. HTTP/HTTPS redirect rules exist

### Issue: Walled Garden Not Working

**Check:**
1. Walled garden rules are configured in MikroTik
2. Domain names resolve correctly
3. No firewall rules blocking access
4. MikroTik can reach DNS servers

### Issue: Tenant Not Detected

**Check:**
1. Router IP is correctly configured in database
2. Router belongs to correct tenant
3. Network connectivity between router and backend
4. Backend logs for tenant detection attempts

### Issue: Authentication Fails

**Check:**
1. FreeRADIUS is running and accessible
2. RADIUS secret matches between MikroTik and FreeRADIUS
3. Database credentials are correct
4. User has active subscription

## Production Deployment Checklist

- [ ] Update walled garden domain to production domain
- [ ] Configure MikroTik hotspot with correct portal URL
- [ ] Set up SSL certificate for captive portal domain
- [ ] Test walled garden access before authentication
- [ ] Verify tenant detection works correctly
- [ ] Test complete authentication flow
- [ ] Configure monitoring for RADIUS authentication
- [ ] Set up logging for captive portal access
- [ ] Document tenant-specific customizations
- [ ] Train support staff on troubleshooting

## API Endpoints

### Public Endpoints (No Authentication)

```
GET  /api/public/packages          - Get tenant packages
POST /api/hotspot/login            - Hotspot user login
POST /api/hotspot/logout           - Hotspot user logout
POST /api/hotspot/check-session    - Check active session
POST /api/payments/initiate        - Initiate payment
```

### Authenticated Endpoints

```
GET  /api/packages                 - List packages (tenant admin)
POST /api/packages                 - Create package (tenant admin)
GET  /api/hotspot/users            - List hotspot users (tenant admin)
```

## Next Steps

1. **Create Frontend Hotspot Login Page** - Build Vue component for `/hotspot/login`
2. **Implement Tenant Branding** - Allow tenants to customize login page
3. **Add Payment Integration** - Integrate M-Pesa/Pesapal for package purchase
4. **Setup Monitoring** - Track captive portal usage and authentication
5. **Document Tenant Onboarding** - Create guide for new tenants

## Support

For issues or questions:
- Check backend logs: `docker logs wificore-backend`
- Check FreeRADIUS logs: `docker logs wificore-freeradius`
- Check MikroTik logs: `/log print where topics~"hotspot"`
- Review this documentation
- Contact system administrator
