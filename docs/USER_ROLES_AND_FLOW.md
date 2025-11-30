# User Roles and Authentication Flow

## Overview
The WiFi Hotspot Management System supports two distinct user types with different access levels and workflows:

1. **System Administrators** - Manage the system, routers, packages, and monitor usage
2. **Hotspot Users** - Purchase packages and access WiFi services

---

## User Roles

### 1. System Administrator (`admin`)

**Purpose:** Manage and monitor the WiFi hotspot system

**Characteristics:**
- Created via RADIUS (radcheck table)
- Full system access
- No package purchase required
- Can manage routers, packages, users, and view logs

**Abilities/Permissions:**
- `*` (all permissions)
- Access to admin dashboard
- Router management
- Package management
- User management
- System logs and monitoring
- Payment tracking

**Login Flow:**
```
1. Admin enters username/password
2. System authenticates via RADIUS
3. User record created/updated in Laravel
4. Role set to 'admin'
5. Sanctum token issued with all abilities
6. Redirect to admin dashboard
```

---

### 2. Hotspot User (`hotspot_user`)

**Purpose:** Purchase packages and access WiFi services

**Characteristics:**
- Created after successful payment
- Limited system access
- Must purchase packages to use WiFi
- Account balance for prepaid services

**Abilities/Permissions:**
- `read-packages` - View available packages
- `purchase-package` - Buy WiFi packages
- `view-subscription` - View own subscriptions
- Access to customer portal only

**User Journey:**
```
1. User connects to WiFi hotspot
2. Redirected to captive portal
3. Views available packages
4. Initiates payment (M-Pesa)
5. On successful payment:
   - User account created (if new)
   - Subscription activated
   - MikroTik credentials generated
   - WiFi access granted
6. Returning users:
   - Existing account updated
   - New subscription added
   - WiFi access extended
```

---

## Database Schema

### Users Table

```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'hotspot_user' NOT NULL,
    phone_number VARCHAR(20),              -- For M-Pesa payments
    account_balance DECIMAL(10, 2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT users_role_check CHECK (role IN ('admin', 'hotspot_user'))
);
```

### User Subscriptions Table

```sql
CREATE TABLE user_subscriptions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    package_id INTEGER NOT NULL REFERENCES packages(id),
    payment_id INTEGER REFERENCES payments(id),
    mac_address VARCHAR(17) NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    mikrotik_username VARCHAR(255),
    mikrotik_password VARCHAR(255),
    data_used_mb BIGINT DEFAULT 0,
    time_used_minutes INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Payments Table (Updated)

```sql
CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),  -- NULL for guest purchases
    mac_address VARCHAR(17) NOT NULL,
    phone_number VARCHAR(15) NOT NULL,
    package_id INTEGER REFERENCES packages(id),
    router_id INTEGER REFERENCES routers(id),
    amount FLOAT NOT NULL,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    payment_method VARCHAR(50) DEFAULT 'mpesa',
    callback_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## Authentication Flows

### Admin Login Flow

```
┌─────────────┐
│ Admin Login │
└──────┬──────┘
       │
       ▼
┌──────────────────┐
│ RADIUS Auth      │
│ (radcheck table) │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐     ┌─────────────┐
│ User exists?     │────▶│ Create User │
│ (Laravel users)  │     │ role: admin │
└──────┬───────────┘     └──────┬──────┘
       │                        │
       ▼                        │
┌──────────────────┐            │
│ Update last_login│◀───────────┘
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Generate Token   │
│ abilities: ['*'] │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│ Admin Dashboard  │
└──────────────────┘
```

### Hotspot User Flow

```
┌─────────────────┐
│ Connect to WiFi │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Captive Portal  │
│ View Packages   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Select Package  │
│ Enter Phone #   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Initiate M-Pesa │
│ Payment         │
└────────┬────────┘
         │
         ▼
┌─────────────────┐     ┌──────────────┐
│ Payment Success?│────▶│ Payment Failed│
└────────┬────────┘     └──────────────┘
         │ YES
         ▼
┌─────────────────┐     ┌──────────────┐
│ User exists?    │────▶│ Create User  │
│ (by phone)      │ NO  │ role: hotspot│
└────────┬────────┘     └──────┬───────┘
         │ YES                 │
         ▼                     │
┌─────────────────┐            │
│ Create          │◀───────────┘
│ Subscription    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Generate        │
│ MikroTik Creds  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Provision in    │
│ MikroTik        │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ WiFi Access     │
│ Granted         │
└─────────────────┘
```

---

## Payment and Provisioning Flow

### New User Purchase

```php
// 1. Payment initiated
POST /api/payments/initiate
{
    "package_id": 1,
    "phone_number": "254712345678",
    "mac_address": "AA:BB:CC:DD:EE:FF"
}

// 2. M-Pesa callback received
POST /api/mpesa/callback
{
    "transaction_id": "ABC123",
    "amount": 100,
    "phone_number": "254712345678",
    "status": "completed"
}

// 3. System processes payment
- Check if user exists (by phone_number)
- If new: Create user account
  - username: generated from phone
  - email: phone@hotspot.local
  - role: hotspot_user
  - phone_number: from payment
- Create user_subscription record
- Generate MikroTik credentials
- Provision user in MikroTik router
- Send SMS with credentials (optional)

// 4. User connects to WiFi
- Username: generated_username
- Password: generated_password
- RADIUS validates against radcheck
- Session tracked in radacct
```

### Returning User Purchase

```php
// 1. Payment initiated (same as above)

// 2. System processes payment
- Find existing user by phone_number
- Check for active subscription
- If active: Extend subscription
- If expired: Create new subscription
- Update MikroTik user profile
- Notify user via SMS

// 3. User continues using WiFi
- Existing credentials still work
- Session extended automatically
- Data/time limits updated
```

---

## API Endpoints and Permissions

### Public Endpoints (No Auth Required)

```
GET  /api/packages              - List available packages
POST /api/payments/initiate     - Initiate payment
POST /api/mpesa/callback        - M-Pesa callback handler
```

### Admin Endpoints (role: admin)

```
GET    /api/routers             - List routers
POST   /api/routers             - Add router
PUT    /api/routers/{id}        - Update router
DELETE /api/routers/{id}        - Delete router

GET    /api/packages            - List packages (admin view)
POST   /api/packages            - Create package
PUT    /api/packages/{id}       - Update package
DELETE /api/packages/{id}       - Delete package

GET    /api/users               - List all users
PUT    /api/users/{id}          - Update user
DELETE /api/users/{id}          - Deactivate user

GET    /api/logs                - View system logs
GET    /api/payments            - View all payments
GET    /api/subscriptions       - View all subscriptions
```

### Hotspot User Endpoints (role: hotspot_user)

```
GET  /api/packages              - List available packages
POST /api/purchase              - Purchase package (using account balance)
GET  /api/my-subscription       - View own subscription
GET  /api/my-usage              - View data/time usage
POST /api/account/topup         - Top up account balance
```

### Shared Endpoints (All Authenticated Users)

```
POST /api/login                 - Login
POST /api/logout                - Logout
GET  /api/profile               - View own profile
PUT  /api/profile               - Update own profile
```

---

## Middleware Configuration

### Route Protection

```php
// routes/api.php

// Public routes
Route::get('/packages', [PackageController::class, 'index']);
Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
Route::post('/mpesa/callback', [MpesaController::class, 'callback']);

// Admin only routes
Route::middleware(['auth:sanctum', 'role:admin', 'user.active'])->group(function () {
    Route::apiResource('routers', RouterController::class);
    Route::apiResource('packages', PackageController::class)->except(['index']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/logs', [LogController::class, 'index']);
    Route::get('/payments', [PaymentController::class, 'index']);
});

// Hotspot user routes
Route::middleware(['auth:sanctum', 'role:hotspot_user', 'user.active'])->group(function () {
    Route::post('/purchase', [PurchaseController::class, 'store']);
    Route::get('/my-subscription', [SubscriptionController::class, 'mine']);
    Route::get('/my-usage', [UsageController::class, 'mine']);
});

// Shared authenticated routes
Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
});
```

---

## User Model Methods

### Role Checking

```php
// Check if user is admin
if ($user->isAdmin()) {
    // Admin-specific logic
}

// Check if user is hotspot user
if ($user->isHotspotUser()) {
    // Hotspot user-specific logic
}
```

### Account Balance Management

```php
// Check balance
if ($user->hasSufficientBalance(100.00)) {
    // Proceed with purchase
}

// Deduct from balance
$user->deductBalance(100.00);

// Add to balance
$user->addBalance(500.00);
```

### Subscription Management

```php
// Get active subscription
$subscription = $user->activeSubscription;

// Check if user has active subscription
if ($subscription && $subscription->isActive()) {
    // User has active WiFi access
}

// Get all subscriptions
$subscriptions = $user->subscriptions;
```

---

## RADIUS Integration

### Admin Users (RADIUS)

Admins are created in the `radcheck` table:

```sql
INSERT INTO radcheck (username, attribute, op, value) 
VALUES ('admin', 'Cleartext-Password', ':=', 'admin123');
```

### Hotspot Users (Dynamic)

Hotspot users are created dynamically after payment:

```sql
-- Created by system after successful payment
INSERT INTO radcheck (username, attribute, op, value) 
VALUES ('user_254712345678', 'Cleartext-Password', ':=', 'generated_password');
```

### Session Tracking

Both user types have sessions tracked in `radacct`:

```sql
SELECT 
    username,
    acctstarttime,
    acctsessiontime,
    acctinputoctets,
    acctoutputoctets
FROM radacct
WHERE username = 'user_254712345678'
AND acctstoptime IS NULL;
```

---

## Frontend Differentiation

### Admin Dashboard

**Route:** `/admin`

**Features:**
- Router management
- Package management
- User management
- Payment tracking
- System logs
- Real-time monitoring

### Customer Portal

**Route:** `/portal`

**Features:**
- Package selection
- Payment initiation
- Subscription status
- Usage statistics
- Account balance
- Top-up options

### Login Redirect Logic

```javascript
// After successful login
if (user.role === 'admin') {
    router.push('/admin/dashboard');
} else if (user.role === 'hotspot_user') {
    router.push('/portal/packages');
}
```

---

## Account Balance System

### For Hotspot Users

Users can maintain an account balance for quick purchases:

```php
// Top up account
POST /api/account/topup
{
    "amount": 500.00,
    "phone_number": "254712345678",
    "payment_method": "mpesa"
}

// Purchase using balance
POST /api/purchase
{
    "package_id": 1,
    "payment_method": "account_balance"
}
```

### Balance Workflow

```
1. User tops up account via M-Pesa
2. Balance credited after successful payment
3. User can purchase packages instantly using balance
4. No need to wait for M-Pesa confirmation
5. Balance deducted immediately
6. WiFi access provisioned instantly
```

---

## Security Considerations

### Role-Based Access Control

1. **Middleware Protection**
   - All admin routes protected by `role:admin` middleware
   - Hotspot user routes protected by `role:hotspot_user` middleware
   - Active user check on all authenticated routes

2. **Token Abilities**
   - Admin tokens: `['*']` (all abilities)
   - Hotspot tokens: `['read-packages', 'purchase-package', 'view-subscription']`

3. **Account Status**
   - Inactive users cannot login
   - Tokens revoked when user deactivated
   - Automatic logout on deactivation

### Data Isolation

1. **Hotspot Users**
   - Can only view own subscriptions
   - Can only view own usage data
   - Cannot access other users' information

2. **Admins**
   - Full access to all data
   - Audit logging for admin actions
   - Cannot be deactivated by other admins

---

## Testing

### Create Admin User

```bash
# Via script
./scripts/create-radius-user.sh -u admin -p admin123

# Via SQL
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "INSERT INTO radcheck (username, attribute, op, value) 
   VALUES ('admin', 'Cleartext-Password', ':=', 'admin123');"
```

### Test Admin Login

```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' | jq '.'
```

### Simulate Hotspot User Purchase

```bash
# 1. Initiate payment
curl -X POST http://localhost/api/payments/initiate \
  -H "Content-Type: application/json" \
  -d '{
    "package_id": 1,
    "phone_number": "254712345678",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }' | jq '.'

# 2. Simulate M-Pesa callback (manual for testing)
curl -X POST http://localhost/api/mpesa/callback \
  -H "Content-Type: application/json" \
  -d '{
    "transaction_id": "TEST123",
    "amount": 100,
    "phone_number": "254712345678",
    "status": "completed"
  }' | jq '.'
```

---

## Migration Path

### Updating Existing System

```bash
# 1. Backup database
docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup.sql

# 2. Stop services
docker-compose down

# 3. Update database schema (handled by init.sql on restart)
docker-compose up -d

# 4. Update existing users to have roles
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "UPDATE users SET role = 'admin' WHERE username = 'admin';"

# 5. Test login
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' | jq '.'
```

---

## Troubleshooting

### User Cannot Login

```bash
# Check if user exists in RADIUS
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT * FROM radcheck WHERE username='admin';"

# Check if user exists in Laravel
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, username, role, is_active FROM users WHERE username='admin';"

# Check if user is active
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT is_active FROM users WHERE username='admin';"
```

### Permission Denied

```bash
# Check user role
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT username, role FROM users WHERE username='admin';"

# Check token abilities
# View in Laravel logs or database
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT abilities FROM personal_access_tokens WHERE tokenable_id = 
   (SELECT id FROM users WHERE username='admin');"
```

---

**Last Updated:** 2025-10-04
**Version:** 1.0
