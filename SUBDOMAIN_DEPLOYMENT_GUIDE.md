# 🚀 Subdomain Multi-Tenancy - Deployment Guide

## ✅ **Implementation Status: COMPLETE**

All backend and frontend components are ready for deployment!

---

## 📋 **Deployment Checklist**

### **Step 1: Run Database Migrations** ⏳

```bash
# Run migrations to add subdomain columns
docker exec traidnet-backend php artisan migrate

# Expected output:
# Running migrations...
# 2025_11_30_000001_add_subdomain_to_tenants ..................... DONE
# 2025_11_30_000002_add_is_public_to_packages .................... DONE
```

**What this does**:
- ✅ Adds `subdomain`, `custom_domain`, `branding` columns to `tenants` table
- ✅ Adds `public_packages_enabled`, `public_registration_enabled` columns
- ✅ Adds `is_public` column to `packages` table
- ✅ Auto-populates existing tenants with subdomains from their slugs
- ✅ Sets default branding for existing tenants

---

### **Step 2: Configure DNS** ⏳

**For Production (Cloudflare/DNS Provider)**:

1. Log in to your DNS provider
2. Add wildcard A record:
   ```
   Type: A
   Name: *
   Value: YOUR_SERVER_IP
   TTL: Auto (or 3600)
   ```

3. For Cloudflare, enable proxy (orange cloud icon)

**For Development (Local Testing)**:

Edit your hosts file:
```
# Windows: C:\Windows\System32\drivers\etc\hosts
# Linux/Mac: /etc/hosts

127.0.0.1 tenant1.localhost
127.0.0.1 tenant2.localhost
127.0.0.1 demo.localhost
```

---

### **Step 3: Update Environment Variables** ⏳

**Backend (.env)**:
```env
# Add these lines
APP_BASE_DOMAIN=yourdomain.com
SUBDOMAIN_ENABLED=true
SUBDOMAIN_PROTOCOL=https
```

**Frontend (.env)**:
```env
# Add these lines
VITE_BASE_DOMAIN=yourdomain.com
VITE_SUBDOMAIN_ENABLED=true
```

---

### **Step 4: Update NGINX Configuration** ⏳

**Edit nginx configuration** to support wildcard subdomains:

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    
    # Wildcard server name for all subdomains
    server_name *.yourdomain.com yourdomain.com;
    
    # SSL certificates (wildcard cert required)
    ssl_certificate /etc/nginx/ssl/wildcard.yourdomain.com.crt;
    ssl_certificate_key /etc/nginx/ssl/wildcard.yourdomain.com.key;
    
    # Frontend (Vue.js)
    location / {
        proxy_pass http://frontend:5173;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
    }
    
    # Backend API
    location /api {
        proxy_pass http://backend:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
    }
    
    # WebSocket (Soketi)
    location /app {
        proxy_pass http://soketi:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

---

### **Step 5: Get Wildcard SSL Certificate** ⏳

**Option 1: Let's Encrypt (Free)**:
```bash
# Install certbot
sudo apt-get install certbot python3-certbot-nginx

# Get wildcard certificate (requires DNS challenge)
sudo certbot certonly --manual --preferred-challenges dns \
  -d yourdomain.com -d *.yourdomain.com

# Follow prompts to add TXT record to DNS
```

**Option 2: Cloudflare (Free with Cloudflare)**:
- Use Cloudflare's free SSL/TLS encryption
- Automatically handles wildcard subdomains

---

### **Step 6: Restart Services** ⏳

```bash
# Restart NGINX
docker-compose restart traidnet-nginx

# Restart backend (to load new env vars)
docker-compose restart traidnet-backend

# Restart frontend (to load new env vars)
docker-compose restart traidnet-frontend

# Or restart all services
docker-compose restart
```

---

### **Step 7: Test the Implementation** ⏳

**Test 1: Check Migration**
```bash
# Verify subdomain column exists
docker exec traidnet-backend php artisan tinker
>>> \App\Models\Tenant::first()->subdomain
# Should return a subdomain value
```

**Test 2: Check API Endpoints**
```bash
# Test subdomain availability
curl -X POST http://localhost:8000/api/public/subdomain/check \
  -H "Content-Type: application/json" \
  -d '{"subdomain":"test-tenant"}'

# Test tenant info
curl http://localhost:8000/api/public/tenant/demo

# Test tenant packages
curl http://localhost:8000/api/public/tenant/demo/packages
```

**Test 3: Test Subdomain Access** (after DNS configured)
```bash
# Test tenant subdomain
curl https://tenant1.yourdomain.com/api/public/tenant/tenant1

# Should return tenant information
```

---

## 🎨 **Frontend Integration**

### **Add Route for Tenant Packages**

**Edit `frontend/src/router/index.js`**:
```javascript
{
  path: '/packages',
  name: 'tenant-packages',
  component: () => import('@/views/TenantPackagesView.vue'),
  meta: { public: true }
}
```

### **Update Tenant Registration Form**

Add subdomain availability checker to registration form:

```vue
<script setup>
import { useTenant } from '@/composables/useTenant'

const { checkSubdomainAvailability } = useTenant()
const subdomainAvailable = ref(null)

const checkSubdomain = async () => {
  const result = await checkSubdomainAvailability(form.tenant_slug)
  subdomainAvailable.value = result.available
}
</script>

<template>
  <input 
    v-model="form.tenant_slug" 
    @input="checkSubdomain"
    placeholder="my-company"
  />
  <p v-if="subdomainAvailable === true" class="text-green-600">
    ✓ Subdomain available: {{ form.tenant_slug }}.yourdomain.com
  </p>
  <p v-else-if="subdomainAvailable === false" class="text-red-600">
    ✗ Subdomain already taken
  </p>
</template>
```

---

## 📊 **Files Created/Modified**

### **Backend** (8 files)
1. ✅ `migrations/2025_11_30_000001_add_subdomain_to_tenants.php`
2. ✅ `migrations/2025_11_30_000002_add_is_public_to_packages.php`
3. ✅ `Middleware/IdentifyTenantFromSubdomain.php`
4. ✅ `Controllers/Api/PublicTenantController.php`
5. ✅ `Models/Tenant.php` (updated)
6. ✅ `Models/Package.php` (updated)
7. ✅ `Jobs/CreateTenantJob.php` (updated)
8. ✅ `routes/api.php` (updated)

### **Frontend** (2 files)
1. ✅ `views/TenantPackagesView.vue`
2. ✅ `composables/useTenant.js`

### **Documentation** (3 files)
1. ✅ `SUBDOMAIN_MULTI_TENANCY.md`
2. ✅ `SUBDOMAIN_IMPLEMENTATION_SUMMARY.md`
3. ✅ `SUBDOMAIN_DEPLOYMENT_GUIDE.md` (this file)

---

## 🎯 **How It Works**

### **Tenant Registration Flow**:
```
1. User registers tenant "Acme WiFi" with slug "acme-wifi"
   ↓
2. System creates:
   - subdomain: "acme-wifi"
   - branding: { colors, logo, etc. }
   - public_packages_enabled: true
   ↓
3. Tenant accessible at: https://acme-wifi.yourdomain.com
```

### **Customer Package Purchase Flow**:
```
1. Customer visits: https://acme-wifi.yourdomain.com/packages
   ↓
2. Frontend extracts subdomain: "acme-wifi"
   ↓
3. Calls API: GET /api/public/tenant/acme-wifi/packages
   ↓
4. Backend returns:
   - Tenant branding (colors, logo, name)
   - Public packages (price, duration, features)
   ↓
5. Frontend displays branded package page
   ↓
6. Customer clicks "Buy Now"
   ↓
7. Redirects to login/purchase with package ID
```

---

## 🔍 **Verification Steps**

### **After Deployment, Verify**:

- [ ] Migrations ran successfully
- [ ] DNS wildcard record added
- [ ] SSL certificate installed (wildcard)
- [ ] NGINX configured for wildcard
- [ ] Environment variables updated
- [ ] Services restarted
- [ ] API endpoints responding
- [ ] Subdomain resolution working
- [ ] Package page displays correctly
- [ ] Branding applies correctly

---

## 🐛 **Troubleshooting**

### **Issue: Subdomain not resolving**
**Solution**: 
- Check DNS propagation (can take 24-48 hours)
- Use `nslookup tenant1.yourdomain.com` to verify
- Check NGINX server_name includes wildcard

### **Issue: SSL certificate error**
**Solution**:
- Ensure wildcard certificate installed
- Verify certificate includes `*.yourdomain.com`
- Check certificate paths in NGINX config

### **Issue: API returns 404 for tenant**
**Solution**:
- Verify migrations ran
- Check tenant has subdomain set
- Verify API route registered
- Check NGINX proxy_pass configuration

### **Issue: Packages not displaying**
**Solution**:
- Check `public_packages_enabled` is true
- Verify packages have `is_public` = true
- Check tenant is active
- Verify API response in browser console

---

## 📚 **API Reference**

### **Public Endpoints** (No Authentication)

```
GET  /api/public/tenant/{subdomain}
     → Get tenant information

GET  /api/public/tenant/{subdomain}/packages
     → Get tenant's public packages

GET  /api/public/tenant-by-domain
     → Get tenant by current domain

POST /api/public/subdomain/check
     → Check subdomain availability
     Body: { "subdomain": "my-company" }
```

---

## 🎉 **Success Criteria**

Your subdomain multi-tenancy is working when:

1. ✅ New tenants get automatic subdomains
2. ✅ Subdomain availability checking works
3. ✅ Customers can visit `tenant.yourdomain.com/packages`
4. ✅ Packages display with tenant branding
5. ✅ Custom colors and logo apply
6. ✅ Support contact info shows correctly
7. ✅ "Buy Now" redirects to purchase flow
8. ✅ SSL works for all subdomains

---

## 🚀 **Next Steps After Deployment**

1. **Test with Real Tenant**:
   - Register a test tenant
   - Visit their subdomain
   - Verify branding and packages

2. **Update Documentation**:
   - Add subdomain info to tenant onboarding
   - Update user guides

3. **Marketing**:
   - Highlight subdomain feature
   - Show example: `yourcompany.yourdomain.com`

4. **Premium Features** (Future):
   - Custom domain support
   - Custom SSL certificates
   - Advanced branding options

---

**Status**: ✅ **READY FOR DEPLOYMENT**  
**Estimated Time**: 30-60 minutes  
**Difficulty**: Medium  
**Risk**: Low (backward compatible)

---

**Last Updated**: November 30, 2025  
**Version**: 1.0  
**Author**: Cascade AI
