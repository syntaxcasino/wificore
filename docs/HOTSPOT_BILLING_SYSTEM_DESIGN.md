# Hotspot Billing System - Complete Design & Implementation

## 🎯 System Overview

A comprehensive hotspot billing system with automatic user provisioning, RADIUS authentication, M-Pesa payment integration, and automated session management.

## 🏗️ System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        HOTSPOT USER FLOW                         │
└─────────────────────────────────────────────────────────────────┘

1. User connects to WiFi → Captive Portal → PackagesView Page
2. User selects package → Initiates M-Pesa payment
3. Payment successful → Queue Job: CreateHotspotUser
4. RADIUS account created → User credentials sent via SMS
5. User logs in with credentials → RADIUS authenticates
6. Session starts → Data usage tracked in radacct
7. Duration expires → Queue Job: DisconnectHotspotUser
8. User disconnected → Account remains (not deleted)
9. User can purchase again → Reactivate account

┌─────────────────────────────────────────────────────────────────┐
│                     SYSTEM COMPONENTS                            │
└─────────────────────────────────────────────────────────────────┘

Frontend (Vue.js)
    ↓
Backend (Laravel API)
    ↓
├─→ PostgreSQL (Database + Queue)
├─→ Redis (Cache + Sessions)
├─→ Soketi (WebSocket Broadcasting)
├─→ Supervisor (Queue Workers)
└─→ FreeRADIUS (AAA Server)
    ↓
MikroTik Routers (via WireGuard VPN)
```

## 📊 Database Schema Design

### Current Tables (Already Exist):
✅ `radcheck` - RADIUS user authentication
✅ `radreply` - RADIUS user attributes
✅ `radacct` - RADIUS accounting (sessions, data usage)
✅ `radpostauth` - RADIUS authentication logs
✅ `nas` - Network Access Servers (routers)
✅ `users` - System administrators
✅ `packages` - WiFi packages
✅ `payments` - M-Pesa transactions
✅ `hotspot_users` - Hotspot user accounts
✅ `hotspot_sessions` - Hotspot session tracking
✅ `jobs` - Laravel queue jobs
✅ `failed_jobs` - Failed queue jobs

### New Tables Needed:

#### 1. `radius_sessions` (Enhanced Session Tracking)
```sql
CREATE TABLE radius_sessions (
    id BIGSERIAL PRIMARY KEY,
    hotspot_user_id BIGINT REFERENCES hotspot_users(id) ON DELETE CASCADE,
    payment_id BIGINT REFERENCES payments(id) ON DELETE SET NULL,
    package_id BIGINT REFERENCES packages(id) ON DELETE SET NULL,
    
    -- RADIUS data
    radacct_id BIGINT REFERENCES radacct(radacctid) ON DELETE SET NULL,
    username VARCHAR(64) NOT NULL,
    mac_address VARCHAR(17),
    ip_address INET,
    nas_ip_address INET,
    
    -- Session timing
    session_start TIMESTAMP NOT NULL,
    session_end TIMESTAMP,
    expected_end TIMESTAMP NOT NULL,
    duration_seconds BIGINT DEFAULT 0,
    
    -- Data usage
    bytes_in BIGINT DEFAULT 0,
    bytes_out BIGINT DEFAULT 0,
    total_bytes BIGINT DEFAULT 0,
    
    -- Status
    status VARCHAR(20) DEFAULT 'active', -- active, expired, disconnected
    disconnect_reason VARCHAR(100),
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_radius_sessions_hotspot_user ON radius_sessions(hotspot_user_id);
CREATE INDEX idx_radius_sessions_status ON radius_sessions(status);
CREATE INDEX idx_radius_sessions_expected_end ON radius_sessions(expected_end);
```

#### 2. `hotspot_credentials` (SMS Delivery Tracking)
```sql
CREATE TABLE hotspot_credentials (
    id BIGSERIAL PRIMARY KEY,
    hotspot_user_id BIGINT REFERENCES hotspot_users(id) ON DELETE CASCADE,
    payment_id BIGINT REFERENCES payments(id) ON DELETE SET NULL,
    
    -- Credentials
    username VARCHAR(64) NOT NULL,
    plain_password VARCHAR(64) NOT NULL, -- Temporary storage for SMS
    
    -- SMS delivery
    phone_number VARCHAR(20) NOT NULL,
    sms_sent BOOLEAN DEFAULT FALSE,
    sms_sent_at TIMESTAMP,
    sms_message_id VARCHAR(100),
    sms_status VARCHAR(50),
    
    -- Expiry
    credentials_expires_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_hotspot_credentials_user ON hotspot_credentials(hotspot_user_id);
CREATE INDEX idx_hotspot_credentials_phone ON hotspot_credentials(phone_number);
```

#### 3. `session_disconnections` (Disconnection Tracking)
```sql
CREATE TABLE session_disconnections (
    id BIGSERIAL PRIMARY KEY,
    radius_session_id BIGINT REFERENCES radius_sessions(id) ON DELETE CASCADE,
    hotspot_user_id BIGINT REFERENCES hotspot_users(id) ON DELETE CASCADE,
    
    -- Disconnection details
    disconnect_method VARCHAR(50), -- auto_expire, admin_disconnect, user_logout
    disconnect_reason VARCHAR(255),
    disconnected_at TIMESTAMP NOT NULL,
    disconnected_by INTEGER REFERENCES users(id) ON DELETE SET NULL, -- Admin who disconnected
    
    -- Session summary
    total_duration BIGINT,
    total_data_used BIGINT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_session_disconnections_user ON session_disconnections(hotspot_user_id);
CREATE INDEX idx_session_disconnections_date ON session_disconnections(disconnected_at);
```

#### 4. `data_usage_logs` (Detailed Data Tracking)
```sql
CREATE TABLE data_usage_logs (
    id BIGSERIAL PRIMARY KEY,
    hotspot_user_id BIGINT REFERENCES hotspot_users(id) ON DELETE CASCADE,
    radius_session_id BIGINT REFERENCES radius_sessions(id) ON DELETE CASCADE,
    
    -- Usage data
    bytes_in BIGINT NOT NULL,
    bytes_out BIGINT NOT NULL,
    total_bytes BIGINT NOT NULL,
    
    -- Snapshot time
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Source
    source VARCHAR(50) DEFAULT 'radius_accounting' -- radius_accounting, manual
);

CREATE INDEX idx_data_usage_logs_user ON data_usage_logs(hotspot_user_id);
CREATE INDEX idx_data_usage_logs_session ON data_usage_logs(radius_session_id);
CREATE INDEX idx_data_usage_logs_date ON data_usage_logs(recorded_at);
```

## 🔄 Complete User Flow

### Phase 1: Package Selection & Payment

```
User → PackagesView → Select Package → Initiate Payment
    ↓
M-Pesa STK Push → User enters PIN → Payment Processed
    ↓
M-Pesa Callback → Backend receives confirmation
    ↓
Payment record created in `payments` table
    ↓
Dispatch Queue Job: CreateHotspotUserJob (Database Queue)
```

### Phase 2: User Creation & RADIUS Provisioning

```
CreateHotspotUserJob starts (from database queue)
    ↓
1. Generate unique username (phone number or random)
2. Generate secure random password
3. Create record in `hotspot_users` table
4. Create RADIUS entries:
   - radcheck: username + password
   - radreply: session timeout, data limit
5. Store credentials in `hotspot_credentials`
6. Create initial `radius_sessions` record
7. Dispatch SendCredentialsSMSJob
8. Return credentials to frontend (for auto-login)
9. Broadcast UserCreatedEvent
```

### Phase 3: Automatic Login (NEW!)

```
Frontend receives payment success response
    ↓
Backend returns: { success: true, credentials: {...}, auto_login: true }
    ↓
Frontend automatically calls POST /api/hotspot/login
    ↓
Backend validates → Creates session in `hotspot_sessions`
    ↓
RADIUS authentication request
    ↓
MikroTik → RADIUS → Authenticates user
    ↓
Session starts → Record in `radacct`
    ↓
Update `radius_sessions` with radacct_id
    ↓
User automatically connected to internet!
    ↓
Show success message: "You are now connected to WiFi"
```

### Phase 4: SMS Delivery (Background)

```
SendCredentialsSMSJob (runs in background)
    ↓
Send SMS with credentials:
"WiFi Login - Username: +254712345678, Password: ABC123XYZ
Valid for: 12 hours. You are already connected!"
    ↓
Update `hotspot_credentials` with SMS status
    ↓
Broadcast CredentialsSentEvent
```

### Phase 5: Manual Login (If Needed Later)

```
User disconnects or uses different device
    ↓
User receives SMS with credentials
    ↓
Opens browser → Captive portal
    ↓
User clicks "Login" → Enters credentials from SMS
    ↓
Frontend → POST /api/hotspot/login
    ↓
Backend validates → Authenticates via RADIUS
    ↓
User connected to internet
```

### Phase 5: Session Monitoring

```
RADIUS Accounting Updates (every 5 minutes)
    ↓
Update `radacct` with data usage
    ↓
Sync to `radius_sessions`
    ↓
Update `hotspot_users.data_used`
    ↓
Log to `data_usage_logs`
    ↓
Check if limit exceeded or time expired
    ↓
If expired → Dispatch DisconnectHotspotUserJob
```

### Phase 6: Session Expiry & Disconnection

```
DisconnectHotspotUserJob
    ↓
1. Check session in `radius_sessions`
2. Send RADIUS disconnect request to MikroTik
3. Update `radius_sessions.status = 'expired'`
4. Update `hotspot_users.has_active_subscription = false`
5. Create record in `session_disconnections`
6. Update `radacct.acctstoptime`
7. Broadcast SessionExpiredEvent
8. User disconnected from internet
```

## 🎯 Queue Jobs Implementation

### 1. CreateHotspotUserJob
**Trigger:** After successful M-Pesa payment
**Priority:** High
**Timeout:** 60 seconds

```php
class CreateHotspotUserJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Generate credentials
        // 2. Create hotspot_users record
        // 3. Create RADIUS entries (radcheck, radreply)
        // 4. Store credentials for SMS
        // 5. Dispatch SendCredentialsSMSJob
        // 6. Broadcast event
    }
}
```

### 2. SendCredentialsSMSJob
**Trigger:** After user creation
**Priority:** High
**Timeout:** 30 seconds

```php
class SendCredentialsSMSJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Get credentials from hotspot_credentials
        // 2. Format SMS message
        // 3. Send via SMS gateway (Africa's Talking, etc.)
        // 4. Update SMS status
        // 5. Broadcast event
    }
}
```

### 3. DisconnectHotspotUserJob
**Trigger:** Session expiry or admin action
**Priority:** High
**Timeout:** 30 seconds

```php
class DisconnectHotspotUserJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Get active session
        // 2. Send RADIUS disconnect
        // 3. Update session status
        // 4. Log disconnection
        // 5. Broadcast event
    }
}
```

### 4. SyncRadiusAccountingJob
**Trigger:** Scheduled (every 5 minutes)
**Priority:** Medium
**Timeout:** 120 seconds

```php
class SyncRadiusAccountingJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Query radacct for active sessions
        // 2. Update radius_sessions with data usage
        // 3. Update hotspot_users.data_used
        // 4. Log to data_usage_logs
        // 5. Check for expired sessions
        // 6. Dispatch disconnect jobs if needed
    }
}
```

### 5. CheckExpiredSessionsJob
**Trigger:** Scheduled (every 1 minute)
**Priority:** High
**Timeout:** 60 seconds

```php
class CheckExpiredSessionsJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Query radius_sessions where expected_end < now()
        // 2. For each expired session:
        //    - Dispatch DisconnectHotspotUserJob
        // 3. Broadcast alerts
    }
}
```

## 🔐 RADIUS Integration

### RADIUS Attributes Configuration

#### radcheck (Authentication)
```sql
INSERT INTO radcheck (username, attribute, op, value) VALUES
('user@example.com', 'Cleartext-Password', ':=', 'password123');
```

#### radreply (Authorization)
```sql
-- Session timeout (in seconds)
INSERT INTO radreply (username, attribute, op, value) VALUES
('user@example.com', 'Session-Timeout', ':=', '43200'); -- 12 hours

-- Data limit (in bytes)
INSERT INTO radreply (username, attribute, op, value) VALUES
('user@example.com', 'ChilliSpot-Max-Total-Octets', ':=', '1073741824'); -- 1GB

-- Bandwidth limits
INSERT INTO radreply (username, attribute, op, value) VALUES
('user@example.com', 'WISPr-Bandwidth-Max-Down', ':=', '3000000'), -- 3 Mbps down
('user@example.com', 'WISPr-Bandwidth-Max-Up', ':=', '3000000'); -- 3 Mbps up
```

### MikroTik RADIUS Configuration

```
/radius
add address=<RADIUS_SERVER_IP> secret=<RADIUS_SECRET> service=hotspot

/ip hotspot profile
set default use-radius=yes

/ip hotspot user profile
set default session-timeout=none idle-timeout=none keepalive-timeout=none \
    mac-cookie-timeout=3d address-list="" transparent-proxy=no
```

## 📡 WebSocket Events

### Events to Broadcast:

1. **HotspotUserCreated**
   - User: Admin dashboard
   - Data: User details, credentials

2. **CredentialsSent**
   - User: Admin dashboard
   - Data: SMS status, phone number

3. **SessionStarted**
   - User: Admin dashboard, user
   - Data: Session details, IP address

4. **SessionExpired**
   - User: Admin dashboard, user
   - Data: Session summary, data used

5. **PaymentReceived**
   - User: Admin dashboard
   - Data: Payment details, package

## 🔒 Security Considerations

### Password Generation
```php
// Generate secure random password
$password = Str::random(12); // ABC123XYZ456

// Hash for storage
$hashedPassword = bcrypt($password);

// Store plain password temporarily for SMS
// Delete after SMS sent or 24 hours
```

### RADIUS Secret
```
// Strong secret for RADIUS communication
RADIUS_SECRET=<random_64_char_string>
```

### Session Security
- MAC address binding
- IP address tracking
- Session timeout enforcement
- Concurrent session prevention

## 📊 Analytics & Reporting

### Key Metrics to Track:

1. **Revenue Metrics**
   - Daily/Weekly/Monthly income
   - Revenue by package
   - Payment success rate

2. **User Metrics**
   - New users vs returning
   - Active sessions
   - Average session duration
   - Data usage per user

3. **Network Metrics**
   - Total bandwidth usage
   - Peak usage times
   - Router performance

4. **Business Metrics**
   - Customer retention rate
   - Package popularity
   - Revenue per user

## 🚀 Implementation Priority

### Phase 1 (Critical):
1. ✅ Create new database tables
2. ✅ Implement CreateHotspotUserJob
3. ✅ Implement RADIUS integration
4. ✅ Implement DisconnectHotspotUserJob
5. ✅ Implement payment callback handler

### Phase 2 (Important):
6. ✅ Implement SMS sending
7. ✅ Implement session monitoring
8. ✅ Implement expiry checking
9. ✅ Implement WebSocket events

### Phase 3 (Enhancement):
10. ✅ Admin dashboard for user management
11. ✅ Analytics and reporting
12. ✅ Automated alerts
13. ✅ Customer portal

## 📝 Configuration Files

### .env additions:
```env
# RADIUS Configuration
RADIUS_HOST=127.0.0.1
RADIUS_PORT=1812
RADIUS_SECRET=your_radius_secret_here
RADIUS_TIMEOUT=5

# SMS Gateway (Africa's Talking)
SMS_API_KEY=your_sms_api_key
SMS_USERNAME=your_sms_username
SMS_SENDER_ID=HOTSPOT

# Queue Configuration (Database Queue)
QUEUE_CONNECTION=database
QUEUE_FAILED_DRIVER=database-uuids

# Auto-Login Configuration
HOTSPOT_AUTO_LOGIN_ENABLED=true
HOTSPOT_AUTO_LOGIN_TIMEOUT=30 # seconds to wait for auto-login

# Session Configuration
HOTSPOT_SESSION_CHECK_INTERVAL=60 # seconds
HOTSPOT_ACCOUNTING_SYNC_INTERVAL=300 # seconds
```

## ✅ Summary

**Database Tables:** 4 new tables + existing tables
**Queue Jobs:** 5 jobs
**API Endpoints:** 8 endpoints
**RADIUS Integration:** Full AAA support
**Automation:** Complete lifecycle management
**Status:** Ready for implementation

---

**Next Steps:** Implement database migrations, create queue jobs, integrate RADIUS, test end-to-end flow
