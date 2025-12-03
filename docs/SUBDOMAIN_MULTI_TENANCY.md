# 🌐 Subdomain-Based Multi-Tenancy Implementation

## 🎯 **Overview**

The WiFi Hotspot SaaS system now supports **subdomain-based multi-tenancy**, where each tenant gets their own subdomain for public-facing pages and package display.

---

## ✅ **Features Implemented**

### **1. Subdomain System**
- ✅ Each tenant gets a unique subdomain (e.g., `tenant1.yourdomain.com`)
- ✅ Custom domain support for premium tenants (e.g., `wifi.customcompany.com`)
- ✅ Automatic subdomain generation from tenant slug
- ✅ Reserved subdomain protection (www, api, admin, etc.)
- ✅ Subdomain availability checking

### **2. Public Package Display**
- ✅ Public packages page per tenant
- ✅ Tenant-specific branding
- ✅ Customizable colors, logo, and tagline
- ✅ Toggle public package visibility
- ✅ SEO-friendly URLs

### **3. Tenant Branding**
- ✅ Custom logo upload
- ✅ Primary and secondary colors
- ✅ Company name and tagline
- ✅ Support contact information
- ✅ Stored in database (JSON)

---

## 📊 **Database Changes**

### **Migration: `2025_11_30_000001_add_subdomain_to_tenants.php`**

**New Columns**:
```sql
subdomain              VARCHAR(255) UNIQUE  -- e.g., "tenant1"
custom_domain          VARCHAR(255) UNIQUE  -- e.g., "wifi.company.com"
branding               JSON                 -- Logo, colors, tagline
public_packages_enabled BOOLEAN DEFAULT TRUE
public_registration_enabled BOOLEAN DEFAULT TRUE
```

**Branding JSON Structure**:
```json
{
  "logo_url": "https://cdn.example.com/logo.png",
  "primary_color": "#3b82f6",
  "secondary_color": "#10b981",
  "company_name": "Acme WiFi",
  "tagline": "Fast & Reliable Internet",
  "support_email": "support@acme.com",
  "support_phone": "+254712345678"
}
```

---

## 🔧 **Backend Implementation**

### **1. Middleware: `IdentifyTenantFromSubdomain`**

**Purpose**: Automatically identify tenant from subdomain

**How it works**:
1. Extract subdomain from request host
2. Look up tenant by subdomain or custom_domain
3. Cache tenant info (1 hour)
4. Add tenant to request attributes
5. Add tenant context to logs

**Example**:
```php
// Request to: tenant1.yourdomain.com/api/packages
// Middleware extracts "tenant1"
// Finds tenant with subdomain="tenant1"
// Adds to request: $request->attributes->get('tenant')
```

**Reserved Subdomains**:
- www, api, admin, app
- mail, smtp, ftp, webmail
- system, test, dev, staging, demo

---

### **2. Controller: `PublicTenantController`**

**Endpoints**:

#### **GET /api/public/tenant/{subdomain}**
Get tenant information by subdomain

**Response**:
```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "name": "Acme WiFi",
    "slug": "acme-wifi",
    "subdomain": "acme",
    "custom_domain": null,
    "branding": {
      "logo_url": "...",
      "primary_color": "#3b82f6",
      "company_name": "Acme WiFi"
    },
    "public_packages_enabled": true,
    "public_registration_enabled": true
  }
}
```

#### **GET /api/public/tenant/{subdomain}/packages**
Get public packages for a tenant

**Response**:
```json
{
  "success": true,
  "data": {
    "tenant": {
      "name": "Acme WiFi",
      "branding": { ... }
    },
    "packages": [
      {
        "id": "uuid",
        "name": "Basic Plan",
        "description": "1 Hour Internet Access",
        "price": 50,
        "duration_hours": 1,
        "data_limit_bytes": 1073741824,
        "speed_limit_mbps": 10,
        "type": "time_based",
        "features": ["Unlimited devices", "24/7 support"]
      }
    ]
  }
}
```

#### **POST /api/public/subdomain/check**
Check if subdomain is available

**Request**:
```json
{
  "subdomain": "my-company"
}
```

**Response**:
```json
{
  "success": true,
  "available": true,
  "message": "Subdomain is available"
}
```

---

### **3. Model Updates: `Tenant.php`**

**New Methods**:

```php
// Get full subdomain URL
$tenant->getSubdomainUrl();
// Returns: "https://tenant1.yourdomain.com"

// Get public packages URL
$tenant->getPublicPackagesUrl();
// Returns: "https://tenant1.yourdomain.com/packages"

// Get branding setting
$tenant->getBranding('logo_url');
// Returns: "https://cdn.example.com/logo.png"

// Set branding setting
$tenant->setBranding('primary_color', '#ff0000');
```

---

## 🌐 **URL Structure**

### **Main Application**
```
https://yourdomain.com          - Landing page
https://yourdomain.com/login    - System login
https://yourdomain.com/register - Tenant registration
```

### **Tenant Subdomains**
```
https://tenant1.yourdomain.com                - Tenant landing page
https://tenant1.yourdomain.com/packages       - Public packages
https://tenant1.yourdomain.com/login          - Tenant user login
https://tenant1.yourdomain.com/dashboard      - Tenant dashboard
```

### **Custom Domains** (Premium)
```
https://wifi.customcompany.com                - Custom domain
https://wifi.customcompany.com/packages       - Public packages
```

---

## 🎨 **Frontend Implementation**

### **1. Tenant Package Display Page**

```vue
<template>
  <div class="tenant-packages">
    <!-- Tenant Branding -->
    <header :style="{ backgroundColor: tenant.branding.primary_color }">
      <img v-if="tenant.branding.logo_url" :src="tenant.branding.logo_url" />
      <h1>{{ tenant.branding.company_name }}</h1>
      <p>{{ tenant.branding.tagline }}</p>
    </header>

    <!-- Packages Grid -->
    <div class="packages-grid">
      <div 
        v-for="pkg in packages" 
        :key="pkg.id"
        class="package-card"
        :style="{ borderColor: tenant.branding.primary_color }"
      >
        <h3>{{ pkg.name }}</h3>
        <p class="price">KES {{ pkg.price }}</p>
        <p class="duration">{{ pkg.duration_hours }} Hours</p>
        <ul class="features">
          <li v-for="feature in pkg.features" :key="feature">
            {{ feature }}
          </li>
        </ul>
        <button 
          @click="selectPackage(pkg)"
          :style="{ backgroundColor: tenant.branding.primary_color }"
        >
          Buy Now
        </button>
      </div>
    </div>

    <!-- Support Contact -->
    <footer>
      <p>Need help? Contact us:</p>
      <p>Email: {{ tenant.branding.support_email }}</p>
      <p>Phone: {{ tenant.branding.support_phone }}</p>
    </footer>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const tenant = ref(null)
const packages = ref([])

onMounted(async () => {
  // Extract subdomain from URL
  const subdomain = window.location.hostname.split('.')[0]
  
  // Fetch tenant and packages
  const response = await axios.get(`/api/public/tenant/${subdomain}/packages`)
  tenant.value = response.data.data.tenant
  packages.value = response.data.data.packages
})
</script>
```

---

## 🔐 **DNS Configuration**

### **Wildcard DNS Setup**

**For Cloudflare/DNS Provider**:
```
Type: A
Name: *
Value: YOUR_SERVER_IP
TTL: Auto
Proxy: Yes (for Cloudflare)
```

**For NGINX**:
```nginx
server {
    listen 80;
    server_name *.yourdomain.com;
    
    location / {
        proxy_pass http://frontend:5173;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
    
    location /api {
        proxy_pass http://backend:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

---

## 📋 **Configuration**

### **1. Environment Variables**

**Backend (.env)**:
```env
APP_BASE_DOMAIN=yourdomain.com
APP_URL=https://yourdomain.com

# Subdomain settings
SUBDOMAIN_ENABLED=true
SUBDOMAIN_PROTOCOL=https
```

**Frontend (.env)**:
```env
VITE_BASE_DOMAIN=yourdomain.com
VITE_API_URL=https://yourdomain.com/api
```

### **2. Laravel Config**

**config/app.php**:
```php
'base_domain' => env('APP_BASE_DOMAIN', 'yourdomain.com'),
'subdomain_enabled' => env('SUBDOMAIN_ENABLED', true),
'subdomain_protocol' => env('SUBDOMAIN_PROTOCOL', 'https'),
```

---

## 🚀 **Usage Examples**

### **Example 1: Tenant Registration with Subdomain**

```javascript
// Frontend - Tenant Registration
const registerTenant = async () => {
  const response = await axios.post('/api/register', {
    tenant_name: 'Acme WiFi',
    tenant_slug: 'acme-wifi',  // Will become subdomain
    tenant_email: 'admin@acme.com',
    admin_name: 'John Doe',
    admin_username: 'john',
    admin_email: 'john@acme.com',
    admin_password: 'Password@123',
    admin_password_confirmation: 'Password@123',
    accept_terms: true
  })
  
  // Tenant created with subdomain: acme-wifi.yourdomain.com
  console.log('Your subdomain:', response.data.data.subdomain)
  console.log('Public URL:', `https://acme-wifi.yourdomain.com/packages`)
}
```

### **Example 2: Check Subdomain Availability**

```javascript
const checkSubdomain = async (subdomain) => {
  const response = await axios.post('/api/public/subdomain/check', {
    subdomain: subdomain
  })
  
  if (response.data.available) {
    console.log('✅ Subdomain available!')
  } else {
    console.log('❌ Subdomain taken')
  }
}
```

### **Example 3: Display Tenant Packages**

```javascript
const loadTenantPackages = async () => {
  // Get subdomain from URL
  const subdomain = window.location.hostname.split('.')[0]
  
  // Fetch tenant packages
  const response = await axios.get(`/api/public/tenant/${subdomain}/packages`)
  
  const { tenant, packages } = response.data.data
  
  // Apply tenant branding
  document.documentElement.style.setProperty('--primary-color', tenant.branding.primary_color)
  document.documentElement.style.setProperty('--secondary-color', tenant.branding.secondary_color)
  
  // Display packages
  displayPackages(packages)
}
```

---

## 🎯 **Benefits**

### **For Tenants**
- ✅ **Branded Experience** - Own subdomain with custom branding
- ✅ **Professional Look** - Dedicated URL for customers
- ✅ **SEO Benefits** - Each tenant has own domain presence
- ✅ **Easy Sharing** - Simple URL to share (acme.yourdomain.com/packages)
- ✅ **Custom Domain** - Option to use own domain (premium)

### **For End Users**
- ✅ **Clear Identity** - Know which company they're buying from
- ✅ **Trust** - Professional subdomain builds confidence
- ✅ **Easy Access** - Memorable URL
- ✅ **Consistent Branding** - Colors and logo match company

### **For System**
- ✅ **Scalability** - Unlimited tenants
- ✅ **Isolation** - Each tenant completely isolated
- ✅ **Performance** - Cached tenant lookups
- ✅ **Flexibility** - Support both subdomains and custom domains

---

## 📝 **Migration Steps**

### **1. Run Migration**
```bash
docker exec traidnet-backend php artisan migrate
```

### **2. Update Existing Tenants**
```bash
# Migration automatically sets subdomain = slug for existing tenants
```

### **3. Configure DNS**
```bash
# Add wildcard DNS record: *.yourdomain.com → YOUR_SERVER_IP
```

### **4. Update NGINX**
```bash
# Add wildcard server_name to nginx config
```

### **5. Test**
```bash
# Test subdomain access
curl https://tenant1.yourdomain.com/api/public/tenant/tenant1

# Test package display
curl https://tenant1.yourdomain.com/api/public/tenant/tenant1/packages
```

---

## 🔍 **Testing Checklist**

- [ ] Tenant registration creates subdomain
- [ ] Subdomain availability check works
- [ ] Public packages display correctly
- [ ] Tenant branding applies correctly
- [ ] Custom domain support works
- [ ] Reserved subdomains are blocked
- [ ] Middleware identifies tenant correctly
- [ ] Caching works properly
- [ ] DNS wildcard configured
- [ ] NGINX wildcard configured

---

## 🎉 **Next Steps**

1. **Run migration** to add subdomain columns
2. **Configure DNS** wildcard record
3. **Update NGINX** for wildcard subdomains
4. **Create frontend** tenant package page
5. **Test** subdomain access
6. **Update** tenant registration to show subdomain
7. **Add** subdomain to tenant dashboard

---

**Status**: ✅ **BACKEND COMPLETE**  
**Frontend**: ⏳ Pending (package display page)  
**DNS**: ⏳ Needs configuration  
**NGINX**: ⏳ Needs wildcard setup

---

**Created**: November 30, 2025  
**Version**: 1.0  
**Architecture**: Subdomain-Based Multi-Tenancy
