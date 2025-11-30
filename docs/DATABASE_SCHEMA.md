# Complete Database Schema Documentation

## Overview
Comprehensive database schema for WiFi Hotspot Management System with user roles, payment processing, and subscription management.

**Last Updated:** 2025-10-04  
**Database:** PostgreSQL 15+  
**Total Tables:** 20

---

## Table of Contents
1. [RADIUS Tables](#radius-tables)
2. [User Management](#user-management)
3. [Router Management](#router-management)
4. [Package & Payment System](#package--payment-system)
5. [Queue System](#queue-system)
6. [Logging](#logging)
7. [Entity Relationship Diagram](#entity-relationship-diagram)

---

## RADIUS Tables

### 1. radcheck
**Purpose:** RADIUS authentication credentials

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| username | VARCHAR(64) | NOT NULL | RADIUS username |
| attribute | VARCHAR(64) | NOT NULL | Auth attribute (e.g., Cleartext-Password) |
| op | CHAR(2) | NOT NULL, DEFAULT '==' | Operator |
| value | VARCHAR(253) | NOT NULL | Password/value |

**Indexes:**
- `radcheck_username` on (username, attribute)

---

### 2. radreply
**Purpose:** RADIUS reply attributes

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| username | VARCHAR(64) | NOT NULL | RADIUS username |
| attribute | VARCHAR(64) | NOT NULL | Reply attribute |
| op | CHAR(2) | NOT NULL, DEFAULT '=' | Operator |
| value | VARCHAR(253) | NOT NULL | Attribute value |

**Indexes:**
- `radreply_username` on (username, attribute)

---

### 3. radacct
**Purpose:** RADIUS accounting (session tracking)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| radacctid | BIGSERIAL | PRIMARY KEY | Unique accounting ID |
| acctsessionid | VARCHAR(64) | NOT NULL | Session ID |
| acctuniqueid | VARCHAR(32) | NOT NULL, UNIQUE | Unique session identifier |
| username | VARCHAR(64) | | RADIUS username |
| realm | VARCHAR(64) | | User realm |
| nasipaddress | INET | NOT NULL | NAS IP address |
| nasportid | VARCHAR(15) | | NAS port ID |
| acctstarttime | TIMESTAMP WITH TIME ZONE | | Session start time |
| acctupdatetime | TIMESTAMP WITH TIME ZONE | | Last update time |
| acctstoptime | TIMESTAMP WITH TIME ZONE | | Session stop time |
| acctsessiontime | BIGINT | | Total session time (seconds) |
| acctinputoctets | BIGINT | DEFAULT 0 | Bytes downloaded |
| acctoutputoctets | BIGINT | DEFAULT 0 | Bytes uploaded |
| calledstationid | VARCHAR(50) | | Called station (AP MAC) |
| callingstationid | VARCHAR(50) | | Calling station (Client MAC) |
| acctterminatecause | VARCHAR(32) | | Termination reason |
| framedipaddress | INET | | Assigned IP address |

**Indexes:**
- `radacct_active` on (acctuniqueid) WHERE acctstoptime IS NULL
- `radacct_start` on (acctstarttime, username)

---

### 4. radpostauth
**Purpose:** RADIUS post-authentication logging

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | BIGSERIAL | PRIMARY KEY | Auto-increment ID |
| username | VARCHAR(64) | NOT NULL | Username attempted |
| pass | VARCHAR(64) | | Password attempted |
| reply | VARCHAR(32) | | Auth result |
| authdate | TIMESTAMP WITH TIME ZONE | NOT NULL, DEFAULT NOW() | Authentication timestamp |

**Indexes:**
- `radpostauth_username` on (username)

---

### 5. nas
**Purpose:** Network Access Servers configuration

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| nasname | VARCHAR(128) | NOT NULL | NAS hostname/IP |
| shortname | VARCHAR(32) | | Short name |
| type | VARCHAR(32) | DEFAULT 'other' | NAS type |
| ports | INTEGER | | Number of ports |
| secret | VARCHAR(60) | NOT NULL | RADIUS shared secret |
| server | VARCHAR(64) | | Server address |
| community | VARCHAR(50) | | SNMP community |
| description | VARCHAR(128) | | Description |

---

## User Management

### 6. users
**Purpose:** System users (admins and hotspot users)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| name | VARCHAR(255) | NOT NULL | Full name |
| username | VARCHAR(255) | UNIQUE, NOT NULL | Login username |
| email | VARCHAR(255) | UNIQUE, NOT NULL | Email address |
| email_verified_at | TIMESTAMP | | Email verification timestamp |
| password | VARCHAR(255) | NOT NULL | Hashed password |
| **role** | **VARCHAR(50)** | **NOT NULL, DEFAULT 'hotspot_user'** | **User role (admin/hotspot_user)** |
| **phone_number** | **VARCHAR(20)** | **UNIQUE** | **Phone for M-Pesa & identification** |
| **account_number** | **VARCHAR(50)** | **UNIQUE** | **Unique account number for payments** |
| **account_balance** | **DECIMAL(10,2)** | **DEFAULT 0.00** | **Prepaid account balance** |
| **is_active** | **BOOLEAN** | **DEFAULT TRUE** | **Account active status** |
| **last_login_at** | **TIMESTAMP** | | **Last successful login** |
| remember_token | VARCHAR(100) | | Remember me token |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

**Constraints:**
- `users_role_check` CHECK (role IN ('admin', 'hotspot_user'))

**Indexes:**
- `idx_users_phone_number` on (phone_number) WHERE phone_number IS NOT NULL
- `idx_users_account_number` on (account_number) WHERE account_number IS NOT NULL
- `idx_users_role` on (role)
- `idx_users_is_active` on (is_active)

**Relationships:**
- Referenced by: payments, user_subscriptions, sessions, personal_access_tokens

---

### 7. personal_access_tokens
**Purpose:** Laravel Sanctum API tokens

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | BIGSERIAL | PRIMARY KEY | Auto-increment ID |
| tokenable_type | VARCHAR(255) | NOT NULL | Model type (App\Models\User) |
| tokenable_id | BIGINT | NOT NULL | User ID |
| name | VARCHAR(255) | NOT NULL | Token name |
| token | VARCHAR(64) | UNIQUE, NOT NULL | Hashed token |
| abilities | TEXT | | Token abilities (JSON) |
| last_used_at | TIMESTAMP | | Last usage timestamp |
| expires_at | TIMESTAMP | | Expiration timestamp |
| created_at | TIMESTAMP | | Creation timestamp |
| updated_at | TIMESTAMP | | Last update timestamp |

**Indexes:**
- `personal_access_tokens_tokenable_type_tokenable_id_index` on (tokenable_type, tokenable_id)

---

### 8. password_reset_tokens
**Purpose:** Password reset tokens

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| email | VARCHAR(255) | PRIMARY KEY | User email |
| token | VARCHAR(255) | | Reset token |
| created_at | TIMESTAMP | | Creation timestamp |

---

### 9. sessions
**Purpose:** User sessions

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | VARCHAR(255) | PRIMARY KEY | Session ID |
| user_id | INTEGER | REFERENCES users(id) | User ID |
| ip_address | VARCHAR(45) | | Client IP |
| user_agent | TEXT | | Browser user agent |
| payload | TEXT | | Session data |
| last_activity | INTEGER | | Last activity timestamp |

---

## Router Management

### 10. routers
**Purpose:** MikroTik routers configuration

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| name | VARCHAR(100) | NOT NULL | Router name |
| ip_address | VARCHAR(45) | | Router IP address |
| model | VARCHAR(255) | | Router model |
| os_version | VARCHAR(50) | | RouterOS version |
| last_seen | TIMESTAMP | | Last communication |
| port | INTEGER | DEFAULT 8728 | API port |
| username | VARCHAR(100) | NOT NULL | API username |
| password | TEXT | NOT NULL | API password (encrypted) |
| location | VARCHAR(255) | | Physical location |
| status | VARCHAR(50) | DEFAULT 'pending' | Router status |
| interface_assignments | JSON | DEFAULT '[]' | Interface config |
| configurations | JSON | DEFAULT '[]' | Router configurations |
| config_token | VARCHAR(255) | UNIQUE | Configuration token |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

---

### 11. wireguard_peers
**Purpose:** WireGuard VPN peers

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| router_id | INTEGER | REFERENCES routers(id) ON DELETE CASCADE | Router ID |
| peer_name | VARCHAR(255) | | Peer name |
| public_key | TEXT | | Public key |
| allowed_ips | TEXT | | Allowed IP ranges |
| transfer_rx | BIGINT | | Bytes received |
| transfer_tx | BIGINT | | Bytes transmitted |
| last_handshake | TIMESTAMP | | Last handshake time |

---

### 12. router_configs
**Purpose:** Router configuration history

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| router_id | INTEGER | REFERENCES routers(id) ON DELETE CASCADE | Router ID |
| config_type | VARCHAR(50) | NOT NULL | Config type |
| config_content | TEXT | NOT NULL | Configuration content |
| last_updated | TIMESTAMP | | Last update time |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

---

## Package & Payment System

### 13. packages
**Purpose:** WiFi packages/plans

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| type | VARCHAR(255) | NOT NULL | Package type (Basic/Premium) |
| name | VARCHAR(255) | NOT NULL | Package name |
| duration | VARCHAR(50) | NOT NULL | Duration (e.g., "1 hour") |
| upload_speed | VARCHAR(50) | NOT NULL | Upload speed limit |
| download_speed | VARCHAR(50) | NOT NULL | Download speed limit |
| price | FLOAT | NOT NULL | Package price (KES) |
| devices | INTEGER | NOT NULL | Max concurrent devices |
| enable_burst | BOOLEAN | DEFAULT FALSE | Enable burst mode |
| enable_schedule | BOOLEAN | DEFAULT FALSE | Enable scheduling |
| hide_from_client | BOOLEAN | DEFAULT FALSE | Hide from public view |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

---

### 14. payments
**Purpose:** Payment transactions

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| **user_id** | **INTEGER** | **REFERENCES users(id) ON DELETE SET NULL** | **Link to user (NULL for guests)** |
| mac_address | VARCHAR(17) | NOT NULL | Client MAC address |
| phone_number | VARCHAR(15) | NOT NULL | Payment phone number |
| package_id | INTEGER | REFERENCES packages(id) ON DELETE CASCADE | Package purchased |
| router_id | INTEGER | REFERENCES routers(id) ON DELETE SET NULL | Router used |
| **amount** | **DECIMAL(10,2)** | **NOT NULL** | **Payment amount** |
| transaction_id | VARCHAR(255) | UNIQUE, NOT NULL | M-Pesa transaction ID |
| **mpesa_receipt** | **VARCHAR(255)** | | **M-Pesa receipt number** |
| **status** | **VARCHAR(20)** | **DEFAULT 'pending'** | **pending/completed/failed** |
| **payment_method** | **VARCHAR(50)** | **DEFAULT 'mpesa'** | **mpesa/cash/account_balance** |
| callback_response | JSON | | Full M-Pesa callback data |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

**Indexes:**
- `idx_payments_user_id` on (user_id)
- `idx_payments_status` on (status)
- `idx_payments_phone_number` on (phone_number)
- `idx_payments_created_at` on (created_at DESC)

---

### 15. user_subscriptions
**Purpose:** Active user subscriptions

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| **user_id** | **INTEGER** | **NOT NULL, REFERENCES users(id) ON DELETE CASCADE** | **User ID** |
| **package_id** | **INTEGER** | **NOT NULL, REFERENCES packages(id) ON DELETE CASCADE** | **Package ID** |
| **payment_id** | **INTEGER** | **REFERENCES payments(id) ON DELETE SET NULL** | **Payment ID** |
| mac_address | VARCHAR(17) | NOT NULL | Client MAC address |
| **start_time** | **TIMESTAMP** | **NOT NULL** | **Subscription start** |
| **end_time** | **TIMESTAMP** | **NOT NULL** | **Subscription end** |
| **status** | **VARCHAR(20)** | **DEFAULT 'active'** | **active/expired/suspended** |
| **mikrotik_username** | **VARCHAR(255)** | | **Generated MikroTik username** |
| **mikrotik_password** | **VARCHAR(255)** | | **Generated MikroTik password** |
| **data_used_mb** | **BIGINT** | **DEFAULT 0** | **Data used (MB)** |
| **time_used_minutes** | **INTEGER** | **DEFAULT 0** | **Time used (minutes)** |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

**Indexes:**
- `idx_user_subscriptions_user_id` on (user_id)
- `idx_user_subscriptions_status` on (status)

---

### 16. vouchers
**Purpose:** Voucher codes (legacy system)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| code | VARCHAR(255) | UNIQUE, NOT NULL | Voucher code |
| mac_address | VARCHAR(17) | NOT NULL | Client MAC |
| payment_id | INTEGER | NOT NULL, REFERENCES payments(id) ON DELETE CASCADE | Payment ID |
| package_id | INTEGER | NOT NULL, REFERENCES packages(id) ON DELETE CASCADE | Package ID |
| duration_hours | INTEGER | NOT NULL | Duration in hours |
| status | VARCHAR(20) | DEFAULT 'unused' | Voucher status |
| expires_at | TIMESTAMP | NOT NULL | Expiration time |
| mikrotik_response | JSON | | MikroTik response |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

---

### 17. user_sessions
**Purpose:** User session tracking (legacy)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| payment_id | INTEGER | REFERENCES payments(id) ON DELETE CASCADE | Payment ID |
| voucher | VARCHAR(255) | UNIQUE, NOT NULL | Voucher code |
| mac_address | VARCHAR(17) | NOT NULL | Client MAC |
| start_time | TIMESTAMP | NOT NULL | Session start |
| end_time | TIMESTAMP | NOT NULL | Session end |
| status | VARCHAR(20) | DEFAULT 'active' | Session status |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

---

## Queue System

### 18. jobs
**Purpose:** Laravel queue jobs

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | BIGSERIAL | PRIMARY KEY | Auto-increment ID |
| queue | VARCHAR(255) | NOT NULL | Queue name |
| payload | TEXT | NOT NULL | Job payload |
| attempts | SMALLINT | NOT NULL | Attempt count |
| reserved_at | INTEGER | | Reserved timestamp |
| available_at | INTEGER | NOT NULL | Available timestamp |
| created_at | INTEGER | NOT NULL | Creation timestamp |

**Indexes:**
- `idx_jobs_queue` on (queue)

---

### 19. job_batches
**Purpose:** Job batches

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | VARCHAR(255) | PRIMARY KEY | Batch ID |
| name | VARCHAR(255) | NOT NULL | Batch name |
| total_jobs | INTEGER | NOT NULL | Total jobs |
| pending_jobs | INTEGER | NOT NULL | Pending jobs |
| failed_jobs | INTEGER | NOT NULL | Failed jobs |
| failed_job_ids | TEXT | NOT NULL | Failed job IDs |
| options | TEXT | | Batch options |
| cancelled_at | INTEGER | | Cancellation timestamp |
| created_at | INTEGER | NOT NULL | Creation timestamp |
| finished_at | INTEGER | | Finish timestamp |

---

### 20. failed_jobs
**Purpose:** Failed queue jobs

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | BIGSERIAL | PRIMARY KEY | Auto-increment ID |
| uuid | VARCHAR(255) | UNIQUE, NOT NULL | Job UUID |
| connection | TEXT | NOT NULL | Connection name |
| queue | TEXT | NOT NULL | Queue name |
| payload | TEXT | NOT NULL | Job payload |
| exception | TEXT | NOT NULL | Exception details |
| failed_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Failure timestamp |

---

## Logging

### 21. system_logs
**Purpose:** System activity logs

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PRIMARY KEY | Auto-increment ID |
| action | VARCHAR(255) | NOT NULL | Action performed |
| details | JSON | | Action details |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Last update timestamp |

---

## Entity Relationship Diagram

```
users (1) ──────< (N) user_subscriptions
  │                      │
  │                      │ (N)
  │                      └──────> (1) packages
  │                      │
  │                      │ (1)
  │                      └──────> (1) payments
  │
  │ (1)
  ├──────< (N) payments
  │              │
  │              │ (N)
  │              └──────> (1) packages
  │              │
  │              │ (N)
  │              └──────> (1) routers
  │
  │ (1)
  ├──────< (N) personal_access_tokens
  │
  │ (1)
  └──────< (N) sessions

routers (1) ──────< (N) wireguard_peers
   │
   │ (1)
   └──────< (N) router_configs

radcheck ─────── RADIUS Authentication
radreply ─────── RADIUS Replies
radacct ──────── RADIUS Accounting
radpostauth ──── RADIUS Post-Auth Logging
nas ──────────── RADIUS NAS Configuration
```

---

## Key Features

### User Account System
- **Account Number**: Unique identifier for payment tracking
- **Account Balance**: Prepaid balance for instant purchases
- **Phone Number**: Primary identifier for hotspot users
- **Role-Based Access**: Admin vs Hotspot User

### Payment Processing
- **Multiple Methods**: M-Pesa, Cash, Account Balance
- **Transaction Tracking**: Full M-Pesa callback data stored
- **User Linking**: Payments linked to user accounts

### Subscription Management
- **Active Tracking**: Real-time subscription status
- **Usage Monitoring**: Data and time usage tracking
- **MikroTik Integration**: Auto-generated credentials
- **Expiration Handling**: Automatic status updates

### Performance Optimizations
- **Strategic Indexes**: On frequently queried columns
- **Partial Indexes**: For nullable columns
- **Foreign Keys**: Proper cascading deletes
- **JSON Storage**: Flexible data storage for callbacks

---

## Sample Queries

### Find User by Phone Number
```sql
SELECT * FROM users WHERE phone_number = '+254712345678';
```

### Get Active Subscriptions
```sql
SELECT u.name, u.phone_number, s.*, p.name as package_name
FROM user_subscriptions s
JOIN users u ON s.user_id = u.id
JOIN packages p ON s.package_id = p.id
WHERE s.status = 'active' AND s.end_time > NOW();
```

### Get User Account Balance
```sql
SELECT username, phone_number, account_number, account_balance
FROM users
WHERE phone_number = '+254712345678';
```

### Payment History
```sql
SELECT p.*, pkg.name as package_name, u.name as user_name
FROM payments p
LEFT JOIN users u ON p.user_id = u.id
JOIN packages pkg ON p.package_id = pkg.id
WHERE p.phone_number = '+254712345678'
ORDER BY p.created_at DESC;
```

### Active RADIUS Sessions
```sql
SELECT username, acctstarttime, acctsessiontime, 
       acctinputoctets + acctoutputoctets as total_bytes
FROM radacct
WHERE acctstoptime IS NULL;
```

---

**Database Version:** 1.0  
**Last Schema Update:** 2025-10-04  
**Maintained By:** Development Team
