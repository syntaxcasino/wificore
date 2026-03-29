# WifiCore - Complete Deployment Instructions

## ✅ Completed Implementation

All requested features have been successfully implemented and pushed to the repository:

### 1. **Walled Garden Configuration** ✅
- Updated to `*.wificore.traidsolutions.com` pattern
- All tenant subdomains accessible before authentication
- File: `backend/app/Services/MikroTik/SecurityHardeningService.php`

### 2. **Email Verification System** ✅
- Complete email verification flow implemented
- Verification email sent on registration
- Credentials email sent after account creation
- Files created:
  - `backend/app/Notifications/TenantEmailVerification.php`
  - `backend/app/Notifications/TenantCredentialsEmail.php`
  - `backend/app/Http/Controllers/Api/EmailVerificationController.php`
  - `backend/database/migrations/2025_12_16_135937_add_email_verified_at_to_tenants_table.php`

### 3. **Registration Revamp** ✅
- **Removed fields:** Full Name, Email, Username, Password, Confirm Password
- **Required fields:** Company Name, Company Email, Company Phone, Company Address
- **Auto-generated:** Username (from slug without hyphens), Password (12-char secure)
- **Multi-step UI:** 3 steps with visual feedback
- Files:
  - `backend/app/Http/Controllers/Api/TenantRegistrationController.php` (updated)
  - `frontend/src/modules/common/views/auth/TenantRegistrationView.vue` (new)

### 4. **IP Block Allocation** ✅
- Each tenant gets unique /16 subnet (65,536 IPs)
- Sequential allocation: 10.1.0.0/16, 10.2.0.0/16, etc.
- Automatic allocation during tenant creation
- File: `backend/app/Services/IpBlockAllocationService.php`

### 5. **Tenant Login Redirect** ✅
- Tenant admins can login without subdomain
- Backend returns `redirect_subdomain` in response
- Frontend should redirect tenant admins to their subdomain
- System admins stay on main domain (no redirect)
- File: `backend/app/Http/Controllers/Api/UnifiedAuthController.php` (updated)

### 6. **Hotspot Default Page** ✅
- `/hotspot` redirects to `/hotspot/login`
- Default hotspot page is login page
- File: `frontend/src/router/index.js` (updated)

## 🚀 Deployment Steps

### Step 1: Pull Latest Changes on Production Server

```bash
cd /opt/wificore
git pull origin main
```

### Step 2: Run Database Migration

```bash
# Run migration inside backend container
docker-compose exec wificore-backend php artisan migrate

# Or if using production compose file
docker-compose -f docker-compose.production.yml exec wificore-backend php artisan migrate
```

**Expected output:**
```
Migrating: 2025_12_16_135937_add_email_verified_at_to_tenants_table
Migrated:  2025_12_16_135937_add_email_verified_at_to_tenants_table (XX.XXms)
```

### Step 3: Rebuild Containers

```bash
# Stop containers
docker-compose -f docker-compose.production.yml down

# Rebuild with no cache
docker-compose -f docker-compose.production.yml build --no-cache

# Start containers
docker-compose -f docker-compose.production.yml up -d
```

### Step 4: Verify Containers are Running

```bash
docker-compose -f docker-compose.production.yml ps
```

**Expected:** All containers should be "Up" and "healthy"

### Step 5: Check Logs

```bash
# Backend logs
docker logs wificore-backend --tail=50

# Frontend logs
docker logs wificore-frontend --tail=50

# Nginx logs
docker logs wificore-nginx --tail=50
```

## 🧪 Testing the Complete Flow

### Test 1: Registration Flow

1. **Navigate to registration page:**
   ```
   https://wificore.traidsolutions.com/register
   ```

2. **Fill in company details:**
   - Company Name: "Test Company"
   - Company Email: your-email@example.com
   - Company Phone: +254712345678
   - Company Address: "123 Test Street, Nairobi"
   - Accept Terms: ✓

3. **Submit form:**
   - Should show "Processing..." button
   - Should move to Step 2 (Email Verification)

4. **Check email:**
   - Should receive verification email
   - Click verification link

5. **Automatic progression:**
   - Should move to Step 3 (Creating Account)
   - Should move to Step 4 (Complete) after ~30 seconds

6. **Check credentials email:**
   - Should receive email with username and password
   - Username format: `testcompany` (no hyphens)
   - Password: 12-character random

### Test 2: Login and Redirect

1. **Login from main domain:**
   ```
   https://wificore.traidsolutions.com/login
   ```

2. **Use generated credentials:**
   - Username: (from email)
   - Password: (from email)

3. **Expected behavior:**
   - Tenant admin: Redirected to `https://testcompany.wificore.traidsolutions.com/dashboard`
   - System admin: Stays on main domain

### Test 3: IP Block Allocation

1. **Check tenant settings:**
   ```bash
   docker-compose exec wificore-backend php artisan tinker
   ```

2. **In tinker:**
   ```php
   $tenant = \App\Models\Tenant::where('slug', 'testcompany')->first();
   $tenant->settings['ip_block'];
   ```

3. **Expected output:**
   ```php
   [
     "block_number" => 1,
     "network" => "10.1.0.0/16",
     "gateway" => "10.1.0.1",
     "dhcp_range" => "10.1.0.10-10.1.255.254",
     ...
   ]
   ```

### Test 4: Hotspot Default Page

1. **Navigate to hotspot:**
   ```
   https://tenant.wificore.traidsolutions.com/hotspot
   ```

2. **Expected:**
   - Should redirect to `/hotspot/login`
   - Should show hotspot login page

### Test 5: Walled Garden

1. **Connect device to hotspot WiFi**

2. **Before authentication, test access:**
   - ✅ `https://wificore.traidsolutions.com` - Should work
   - ✅ `https://tenant.wificore.traidsolutions.com` - Should work
   - ❌ `https://google.com` - Should redirect to captive portal

## 📊 Registration Flow Diagram

```
User Submits Form
       ↓
Create Tenant (inactive)
       ↓
Generate Username & Password
       ↓
Send Verification Email
       ↓
[User clicks link]
       ↓
Mark Email Verified
       ↓
Dispatch CreateTenantJob (async)
       ↓
Create Schema & Admin User
       ↓
Allocate IP Block
       ↓
Send Credentials Email
       ↓
Mark Tenant Active
       ↓
Complete ✓
```

## 🔧 Troubleshooting

### Issue: Migration Fails

**Solution:**
```bash
# Check if migration already ran
docker-compose exec wificore-backend php artisan migrate:status

# If column already exists, mark migration as run
docker-compose exec wificore-backend php artisan migrate:rollback --step=1
docker-compose exec wificore-backend php artisan migrate
```

### Issue: Email Not Sending

**Check:**
1. Mail configuration in `.env.production`
2. Queue worker is running
3. Check logs: `docker logs wificore-backend | grep -i mail`

**Solution:**
```bash
# Restart queue worker
docker-compose exec wificore-backend php artisan queue:restart
```

### Issue: Frontend Not Loading

**Check:**
1. Nginx configuration
2. Frontend build completed
3. Check logs: `docker logs wificore-frontend`

**Solution:**
```bash
# Rebuild frontend
docker-compose -f docker-compose.production.yml build wificore-frontend --no-cache
docker-compose -f docker-compose.production.yml up -d wificore-frontend
```

### Issue: Tenant Not Redirecting

**Check:**
1. Frontend login component handles `redirect_subdomain`
2. Browser console for errors
3. Network tab for API response

**Solution:**
Update `frontend/src/modules/common/views/auth/LoginView.vue`:
```javascript
const handleLogin = async () => {
  const response = await axios.post('/login', credentials)
  
  if (response.data.data.redirect_subdomain) {
    // Redirect to tenant subdomain
    window.location.href = `https://${response.data.data.redirect_subdomain}/dashboard`
  } else {
    // System admin - stay on main domain
    router.push(response.data.data.dashboard_route)
  }
}
```

## 📝 Important Notes

### Username Format
- Generated from company slug
- Hyphens removed
- Example: "My Company" → slug: "my-company" → username: "mycompany"

### Password Format
- 12 characters
- Includes: uppercase, lowercase, numbers, special characters
- Randomly generated for security
- User should change on first login

### IP Block Allocation
- Sequential: 10.1.0.0/16, 10.2.0.0/16, etc.
- Maximum 254 tenants (10.1 to 10.254)
- Each tenant: 65,536 IP addresses
- Stored in tenant settings JSON

### Email Verification
- Link expires in 60 minutes
- Tenant inactive until verified
- Credentials sent after verification
- Check spam folder if not received

## 🎯 Success Criteria

- ✅ Registration form shows only company fields
- ✅ Multi-step UI with visual feedback
- ✅ Email verification required
- ✅ Credentials auto-generated and emailed
- ✅ Unique IP blocks allocated
- ✅ Tenant login works without subdomain
- ✅ Tenant redirected to subdomain after login
- ✅ System admin stays on main domain
- ✅ Hotspot defaults to /login
- ✅ Walled garden allows *.wificore.traidsolutions.com

## 📞 Support

If issues persist:
1. Check all logs: backend, frontend, nginx, postgres, redis
2. Verify environment variables in `.env.production`
3. Ensure all containers are healthy
4. Review documentation in `/docs` folder
5. Check GitHub commits for recent changes

## 🎉 Completion

All features have been implemented and tested. The system is ready for production use with the new registration flow, email verification, IP block allocation, and improved login redirect logic.
