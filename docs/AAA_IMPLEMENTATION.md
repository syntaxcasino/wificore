# ✅ AAA (Authentication, Authorization, Accounting) Implementation

## 🎯 Overview

This system implements **full AAA** via **FreeRADIUS** for ALL users:
- **System Administrators**
- **Tenant Administrators**  
- **Hotspot Users**

**Every user authenticates through FreeRADIUS** - no exceptions!

---

## 🔐 Authentication Flow

```
┌─────────────────────────────────────────────────┐
│         User Login Request                      │
│  POST /api/login                                │
│  { username, password }                         │
└────────────────────┬────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────┐
│  1. Find User in Database (users table)         │
│     WHERE username = ? OR email = ?             │
└────────────────────┬────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────┐
│  2. Authenticate via FreeRADIUS (AAA)           │
│     - Query radcheck table                      │
│     - Verify password                           │
│     - ALL users go through RADIUS               │
└────────────────────┬────────────────────────────┘
                     │
        ┌────────────┴────────────┐
        │                         │
        ▼                         ▼
    ✅ Success                ❌ Failure
        │                         │
        ▼                         ▼
┌──────────────┐          ┌──────────────┐
│ Create Token │          │ Return 401   │
│ Return User  │          │ Unauthorized │
└──────────────┘          └──────────────┘
```

---

## 📊 Database Schema

### Users Table (Application)
```sql
CREATE TABLE users (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,  -- Bcrypt hash (backup)
    role VARCHAR(50) NOT NULL,       -- system_admin, admin, hotspot_user
    is_active BOOLEAN DEFAULT TRUE,
    ...
);
```

### Radcheck Table (RADIUS Authentication)
```sql
CREATE TABLE radcheck (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,     -- 'Cleartext-Password'
    op CHAR(2) NOT NULL DEFAULT '==',   -- ':='
    value VARCHAR(253) NOT NULL         -- Actual password
);
```

**Every user MUST exist in BOTH tables!**

---

## 🔧 Implementation Details

### 1. System Admin Seeding (init.sql)

```sql
-- Create in users table
INSERT INTO users (id, username, email, password, role, ...)
VALUES (
    '00000000-0000-0000-0000-000000000001',
    'sysadmin',
    'sysadmin@system.local',
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5NANClx6T.Zcm',
    'system_admin',
    ...
);

-- AAA: Add to RADIUS
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('sysadmin', 'Cleartext-Password', ':=', 'Admin@123!');
```

### 2. Tenant Registration (TenantRegistrationController.php)

```php
// Create tenant admin user
$adminUser = User::create([
    'username' => $request->admin_username,
    'password' => Hash::make($request->admin_password),
    'role' => User::ROLE_ADMIN,
    ...
]);

// AAA: Add to RADIUS
DB::table('radcheck')->insert([
    'username' => $request->admin_username,
    'attribute' => 'Cleartext-Password',
    'op' => ':=',
    'value' => $request->admin_password,  // Plain text for RADIUS
]);
```

### 3. Login Authentication (UnifiedAuthController.php)

```php
// Find user in database
$user = User::where('username', $request->username)
    ->orWhere('email', $request->username)
    ->first();

// AAA: Authenticate ALL users via RADIUS
$authenticated = $this->radiusService->authenticate(
    $user->username, 
    $request->password
);

if (!$authenticated) {
    return response()->json([
        'success' => false,
        'message' => 'Invalid credentials',
    ], 401);
}
```

### 4. Password Change (UnifiedAuthController.php)

```php
// Update in database
$user->update([
    'password' => Hash::make($request->new_password),
]);

// AAA: Update in RADIUS
DB::table('radcheck')
    ->where('username', $user->username)
    ->where('attribute', 'Cleartext-Password')
    ->update(['value' => $request->new_password]);
```

---

## 🎯 User Roles & AAA

### System Administrator
- **Role**: `system_admin`
- **Database**: `users` table
- **RADIUS**: `radcheck` table
- **Authentication**: FreeRADIUS ✅
- **Accounting**: RADIUS accounting ✅
- **Authorization**: Laravel Sanctum tokens

### Tenant Administrator
- **Role**: `admin`
- **Database**: `users` table (with `tenant_id`)
- **RADIUS**: `radcheck` table
- **Authentication**: FreeRADIUS ✅
- **Accounting**: RADIUS accounting ✅
- **Authorization**: Laravel Sanctum tokens

### Hotspot User
- **Role**: `hotspot_user`
- **Database**: `users` table (with `tenant_id`)
- **RADIUS**: `radcheck` table
- **Authentication**: FreeRADIUS ✅
- **Accounting**: RADIUS accounting (radacct) ✅
- **Authorization**: Laravel Sanctum tokens

---

## 📝 RADIUS Service (RadiusService.php)

```php
class RadiusService
{
    public function authenticate(string $username, string $password): bool
    {
        // Connect to RADIUS server
        $radius = radius_auth_open();
        radius_add_server(
            $radius,
            config('radius.host'),
            config('radius.port'),
            config('radius.secret'),
            3,  // timeout
            3   // max_tries
        );

        // Create authentication request
        radius_create_request($radius, RADIUS_ACCESS_REQUEST);
        radius_put_string($radius, RADIUS_USER_NAME, $username);
        radius_put_string($radius, RADIUS_USER_PASSWORD, $password);

        // Send request
        $result = radius_send_request($radius);

        return $result === RADIUS_ACCESS_ACCEPT;
    }
}
```

---

## 🔍 Accounting (RADIUS)

### Radacct Table
```sql
CREATE TABLE radacct (
    radacctid BIGSERIAL PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL,
    acctuniqueid VARCHAR(32) NOT NULL UNIQUE,
    username VARCHAR(64),
    nasipaddress INET,
    acctstarttime TIMESTAMP,
    acctstoptime TIMESTAMP,
    acctsessiontime BIGINT,
    acctinputoctets BIGINT,
    acctoutputoctets BIGINT,
    ...
);
```

**All user sessions are tracked in radacct!**

---

## ✅ Benefits of Full AAA

### 1. **Centralized Authentication**
- Single source of truth (RADIUS)
- Consistent authentication across all user types
- Easy to audit and monitor

### 2. **Accounting & Tracking**
- All logins tracked in RADIUS
- Session duration recorded
- Data usage monitored
- Compliance ready

### 3. **Authorization**
- Role-based access control (RBAC)
- Token-based API access
- Granular permissions

### 4. **Security**
- Industry-standard RADIUS protocol
- Encrypted password transmission
- Rate limiting
- Session management

### 5. **Scalability**
- RADIUS can handle millions of users
- Distributed authentication
- Load balancing support

---

## 🧪 Testing AAA

### 1. Test System Admin Login
```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "sysadmin",
    "password": "Admin@123!"
  }'
```

**Expected**: 
- ✅ RADIUS authentication
- ✅ Token returned
- ✅ Session logged in radacct

### 2. Test Tenant Admin Registration & Login
```bash
# Register
curl -X POST http://localhost/api/register/tenant \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_name": "Test Company",
    "tenant_slug": "test-company",
    "admin_username": "testadmin",
    "admin_password": "Test@123!",
    ...
  }'

# Login
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "testadmin",
    "password": "Test@123!"
  }'
```

**Expected**:
- ✅ User created in `users` table
- ✅ User added to `radcheck` table
- ✅ RADIUS authentication works
- ✅ Session tracked

### 3. Check RADIUS Logs
```bash
docker-compose logs traidnet-freeradius | grep -i "access-request"
```

**Expected**:
```
Access-Request packet from host 172.20.0.x port xxxx
User-Name = "sysadmin"
Access-Accept packet sent
```

### 4. Check Accounting
```bash
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot
SELECT username, acctstarttime, acctsessiontime FROM radacct ORDER BY acctstarttime DESC LIMIT 10;
```

---

## 🔧 Configuration

### Environment Variables (backend/.env)
```env
RADIUS_SERVER_HOST=traidnet-freeradius
RADIUS_SERVER_PORT=1812
RADIUS_SECRET=testing123
```

### FreeRADIUS Configuration
- **Server**: `traidnet-freeradius` container
- **Port**: 1812 (authentication)
- **Port**: 1813 (accounting)
- **Secret**: `testing123`
- **Database**: PostgreSQL (`wifi_hotspot`)

---

## 📊 AAA Flow Diagram

```
┌──────────────┐
│   Browser    │
└──────┬───────┘
       │ POST /api/login
       │ {username, password}
       ▼
┌──────────────────────┐
│   Laravel Backend    │
│ UnifiedAuthController│
└──────┬───────────────┘
       │ 1. Find user in DB
       │ 2. Call RadiusService
       ▼
┌──────────────────────┐
│   RadiusService      │
│  authenticate()      │
└──────┬───────────────┘
       │ RADIUS Access-Request
       ▼
┌──────────────────────┐
│   FreeRADIUS Server  │
│  (traidnet-freeradius)│
└──────┬───────────────┘
       │ Query radcheck table
       ▼
┌──────────────────────┐
│   PostgreSQL         │
│   radcheck table     │
└──────┬───────────────┘
       │ Return password
       ▼
┌──────────────────────┐
│   FreeRADIUS         │
│   Verify password    │
└──────┬───────────────┘
       │ Access-Accept/Reject
       ▼
┌──────────────────────┐
│   RadiusService      │
│   Return true/false  │
└──────┬───────────────┘
       │
       ▼
┌──────────────────────┐
│   Laravel Backend    │
│   Create token       │
│   Log accounting     │
└──────┬───────────────┘
       │ Return response
       ▼
┌──────────────────────┐
│   Browser            │
│   Store token        │
└──────────────────────┘
```

---

## ✅ Summary

### What's Implemented:
- ✅ **Full AAA via FreeRADIUS** for ALL users
- ✅ **System admin** seeded in RADIUS
- ✅ **Tenant admin** added to RADIUS on registration
- ✅ **Hotspot users** authenticated via RADIUS
- ✅ **Password changes** update RADIUS
- ✅ **Session accounting** in radacct table
- ✅ **Centralized authentication** - no bypassing RADIUS

### User Credentials:
```
Username: sysadmin
Password: Admin@123!
```

### Test Login:
```
URL: http://localhost/login
```

**All authentication now goes through FreeRADIUS!** 🚀

---

**Last Updated**: Oct 28, 2025, 12:20 PM  
**Status**: ✅ **FULL AAA IMPLEMENTED**  
**RADIUS**: Required for ALL users ✅  
**Accounting**: Enabled ✅  
**Documentation**: Organized in /docs ✅
