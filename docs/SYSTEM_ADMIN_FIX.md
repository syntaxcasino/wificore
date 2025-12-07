# System Admin Login Fix
## Landlord Should Not Be Forced to Use Subdomain

**Date**: December 7, 2025 - 11:57 AM  
**Status**: ✅ **FIXED**

---

## 🐛 **Problem**

System admin (landlord) was getting 403 error when trying to login:

```json
{
  "status": 403,
  "message": "Request failed with status code 403"
}
```

---

## 🔍 **Root Cause**

The system admin user had a `tenant_id` value when it should be `NULL`:

```sql
-- BEFORE (Wrong)
SELECT username, role, tenant_id FROM users WHERE role = 'system_admin';
 username |     role     |              tenant_id              
----------+--------------+-------------------------------------
 sysadmin | system_admin | c5cb9388-7bbd-4c8a-a037-6e999628445a  ❌
```

**Why This Caused 403**:
- System admins should have `tenant_id = NULL`
- When `tenant_id` is set, the UnifiedAuthController tries to validate schema mapping
- Schema mapping validation expects tenant users, not system admins
- This caused the 403 error

---

## ✅ **Solution**

Set `tenant_id = NULL` for all system admin users:

```sql
UPDATE users 
SET tenant_id = NULL 
WHERE role = 'system_admin';
```

**Result**:
```sql
-- AFTER (Correct)
SELECT username, role, tenant_id FROM users WHERE role = 'system_admin';
 username |     role     | tenant_id 
----------+--------------+-----------
 sysadmin | system_admin |           ✅
```

---

## 🔐 **System Admin Architecture**

### **Database Structure**:

#### **Public Schema** (System Admin):
```sql
-- User record
users (
  id: uuid,
  username: 'sysadmin',
  role: 'system_admin',
  tenant_id: NULL  ✅ -- No tenant!
)

-- RADIUS credentials
public.radcheck (
  username: 'sysadmin',
  attribute: 'Cleartext-Password',
  value: 'Admin@123!'
)

-- Schema mapping
public.radius_user_schema_mapping (
  username: 'sysadmin',
  schema_name: 'public',  ✅ -- Uses public schema!
  tenant_id: NULL  ✅
)
```

#### **Tenant Schema** (Tenant Admin):
```sql
-- User record
users (
  id: uuid,
  username: 'admin',
  role: 'admin',
  tenant_id: 'c5cb9388-...'  ✅ -- Has tenant!
)

-- RADIUS credentials
ts_xxxxxxxxxxxx.radcheck (  ✅ -- In tenant schema!
  username: 'admin',
  attribute: 'Cleartext-Password',
  value: 'password'
)

-- Schema mapping
public.radius_user_schema_mapping (
  username: 'admin',
  schema_name: 'ts_xxxxxxxxxxxx',  ✅
  tenant_id: 'c5cb9388-...'  ✅
)
```

---

## 📊 **Login Flow Comparison**

### **System Admin Login**:
```
1. User enters: sysadmin / Admin@123!
2. Find user: WHERE username = 'sysadmin'
3. Check tenant_id: NULL ✅ (Skip schema validation)
4. Check subdomain: Not a tenant subdomain ✅
5. RADIUS auth: public.radcheck ✅
6. Create token: abilities = system:*
7. Dashboard: /system/dashboard
8. Success! ✅
```

### **Tenant Admin Login**:
```
1. User enters: admin / password
2. Find user: WHERE username = 'admin'
3. Check tenant_id: c5cb9388-... ✅
4. Validate schema mapping: ts_xxxxxxxxxxxx ✅
5. Check subdomain: Matches tenant ✅
6. RADIUS auth: ts_xxxxxxxxxxxx.radcheck ✅
7. Create token: abilities = tenant:*
8. Dashboard: /dashboard
9. Success! ✅
```

---

## 🎯 **Key Differences**

| Feature | System Admin | Tenant Admin |
|---------|-------------|--------------|
| **tenant_id** | NULL | UUID |
| **RADIUS Schema** | public | ts_xxxxxxxxxxxx |
| **Subdomain** | Main domain only | Tenant subdomain |
| **Schema Validation** | Skipped | Required |
| **Dashboard** | /system/dashboard | /dashboard |
| **Abilities** | system:* | tenant:* |

---

## ✅ **Verification**

### **Test System Admin Login**:
```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "sysadmin",
    "password": "Admin@123!"
  }'
```

**Expected**: Success with `dashboard_route: "/system/dashboard"`

### **Test Tenant Admin Login**:
```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "admin",
    "password": "password"
  }'
```

**Expected**: Success with `dashboard_route: "/dashboard"`

---

## 🔧 **Prevention**

To prevent this issue in the future, ensure:

1. ✅ **System admin creation** sets `tenant_id = NULL`
2. ✅ **RADIUS credentials** go to `public.radcheck`
3. ✅ **Schema mapping** points to `public` schema
4. ✅ **Validation** in UnifiedAuthController checks `if ($user->tenant_id)` before schema validation

---

## 📝 **Code Reference**

### **UnifiedAuthController.php** (Lines 218-259):
```php
// SCHEMA-BASED MULTI-TENANCY: Validate schema mapping for tenant users
if ($user->tenant_id) {  // ✅ System admins skip this!
    $schemaMapping = DB::table('radius_user_schema_mapping')
        ->where('username', $user->username)
        ->where('tenant_id', $user->tenant_id)
        ->where('is_active', true)
        ->first();
    
    if (!$schemaMapping) {
        return error('User not properly configured');
    }
    
    // Validate schema matches tenant
    if ($schemaMapping->schema_name !== $user->tenant->schema_name) {
        return error('Schema mismatch');
    }
}
```

---

## ✅ **Status**

```
╔══════════════════════════════════════════════════════════════╗
║              SYSTEM ADMIN LOGIN FIXED ✅                     ║
╚══════════════════════════════════════════════════════════════╝

✅ tenant_id set to NULL for system admins
✅ Schema validation skipped for system admins
✅ RADIUS credentials in public schema
✅ Schema mapping points to public
✅ No subdomain required
✅ Can login from main domain

Status: FIXED
Tested: ✅ Working
```

---

**Status**: ✅ **FIXED**  
**System Admin**: ✅ **CAN LOGIN WITHOUT SUBDOMAIN**  
**Tenant Isolation**: ✅ **MAINTAINED**  
**Security**: ✅ **ENFORCED**

🎉 **System admins (landlords) can now login without being forced to use a subdomain!** 🎉
