-- =========================
-- UUID EXTENSIONS
-- =========================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- =========================
-- RADIUS TABLES (Keep as SERIAL for FreeRADIUS compatibility)
-- =========================
CREATE TABLE IF NOT EXISTS radcheck (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS radcheck_username ON radcheck (username, attribute);

CREATE TABLE IF NOT EXISTS radreply (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS radreply_username ON radreply (username, attribute);

CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGSERIAL PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL,
    acctuniqueid VARCHAR(32) NOT NULL UNIQUE,
    username VARCHAR(64),
    realm VARCHAR(64),
    nasipaddress INET NOT NULL,
    nasportid VARCHAR(15),
    acctstarttime TIMESTAMP WITH TIME ZONE,
    acctupdatetime TIMESTAMP WITH TIME ZONE,
    acctstoptime TIMESTAMP WITH TIME ZONE,
    acctinterval BIGINT,
    acctsessiontime BIGINT,
    acctauthentic VARCHAR(32),
    connectinfo_start VARCHAR(50),
    connectinfo_stop VARCHAR(50),
    acctinputoctets BIGINT DEFAULT 0,
    acctoutputoctets BIGINT DEFAULT 0,
    calledstationid VARCHAR(50),
    callingstationid VARCHAR(50),
    acctterminatecause VARCHAR(32),
    servicetype VARCHAR(32),
    framedprotocol VARCHAR(32),
    framedipaddress INET,
    acctstartdelay BIGINT DEFAULT 0,
    acctstopdelay BIGINT DEFAULT 0
);
CREATE INDEX IF NOT EXISTS radacct_active ON radacct (acctuniqueid) WHERE acctstoptime IS NULL;
CREATE INDEX IF NOT EXISTS radacct_start ON radacct (acctstarttime, username);

CREATE TABLE IF NOT EXISTS radpostauth (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    pass VARCHAR(64),
    reply VARCHAR(32),
    authdate TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS radpostauth_username ON radpostauth (username);

CREATE TABLE IF NOT EXISTS nas (
    id SERIAL PRIMARY KEY,
    nasname VARCHAR(128) NOT NULL UNIQUE,
    shortname VARCHAR(32),
    type VARCHAR(32) DEFAULT 'other',
    ports INTEGER,
    secret VARCHAR(60) NOT NULL,
    server VARCHAR(64),
    community VARCHAR(50),
    description VARCHAR(128)
);

CREATE INDEX IF NOT EXISTS nas_nasname ON nas (nasname);

-- =========================
-- TENANTS TABLE (Multi-Tenancy)
-- =========================
CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    address TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    is_suspended BOOLEAN DEFAULT FALSE,
    suspension_reason TEXT,
    trial_ends_at TIMESTAMP,
    settings JSON DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP
);

CREATE INDEX idx_tenants_slug ON tenants(slug);
CREATE INDEX idx_tenants_is_active ON tenants(is_active);

-- =========================
-- USERS (UUID with Multi-Tenancy)
-- =========================
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'hotspot_user' NOT NULL,
    phone_number VARCHAR(20) UNIQUE,
    account_number VARCHAR(50) UNIQUE,
    account_balance DECIMAL(10, 2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP,
    remember_token VARCHAR(100),
    -- Security: Failed login tracking and suspension
    failed_login_attempts INTEGER DEFAULT 0,
    last_failed_login_at TIMESTAMP,
    suspended_at TIMESTAMP,
    suspended_until TIMESTAMP,
    suspension_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT users_role_check CHECK (role IN ('system_admin', 'admin', 'hotspot_user'))
);

CREATE INDEX idx_users_tenant_id ON users(tenant_id);
CREATE INDEX idx_users_phone_number ON users(phone_number) WHERE phone_number IS NOT NULL;
CREATE INDEX idx_users_account_number ON users(account_number) WHERE account_number IS NOT NULL;
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_is_active ON users(is_active);
CREATE INDEX idx_users_suspended_until ON users(suspended_until) WHERE suspended_until IS NOT NULL;

-- =========================
-- DEFAULT SYSTEM ADMINISTRATOR
-- =========================
-- Insert default system administrator (cannot be deleted)
INSERT INTO users (id, tenant_id, name, username, email, email_verified_at, password, role, is_active, account_number, created_at, updated_at)
VALUES (
    '00000000-0000-0000-0000-000000000001',
    NULL,
    'System Administrator',
    'sysadmin',
    'sysadmin@system.local',
    NOW(),
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5NANClx6T.Zcm', -- Password: Admin@123!
    'system_admin',
    TRUE,
    'SYS-ADMIN-001',
    NOW(),
    NOW()
) ON CONFLICT (id) DO NOTHING;

-- AAA: Add system admin to RADIUS for authentication
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('sysadmin', 'Cleartext-Password', ':=', 'Admin@123!');

-- PERSONAL ACCESS TOKENS (Keep BIGSERIAL for Laravel compatibility)
CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id UUID NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP,
    expires_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens(tokenable_type, tokenable_id);

-- PASSWORD RESET TOKENS (Keep as-is)
CREATE TABLE password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token VARCHAR(255),
    created_at TIMESTAMP
);

-- SESSIONS (Keep as-is, but update user_id to UUID)
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id UUID REFERENCES users(id),
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT,
    last_activity INTEGER
);

-- =========================
-- ROUTERS (UUID)
-- =========================
CREATE TABLE routers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    model VARCHAR(255),
    os_version VARCHAR(50),
    last_seen TIMESTAMP,
    port INTEGER DEFAULT 8728,
    username VARCHAR(100) NOT NULL,
    password TEXT NOT NULL,
    location VARCHAR(255),
    status VARCHAR(50) DEFAULT 'pending',
    provisioning_stage VARCHAR(50),
    interface_assignments JSON DEFAULT '[]',
    configurations JSON DEFAULT '[]',
    config_token VARCHAR(255) UNIQUE,
    vendor VARCHAR(50) DEFAULT 'mikrotik',
    device_type VARCHAR(50) DEFAULT 'router',
    capabilities JSON DEFAULT '[]',
    interface_list JSON DEFAULT '[]',
    reserved_interfaces JSON DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP
);

CREATE INDEX idx_routers_vendor ON routers(vendor);
CREATE INDEX idx_routers_device_type ON routers(device_type);
CREATE INDEX idx_routers_status ON routers(status);

-- =========================
-- WIREGUARD (UUID)
CREATE TABLE wireguard_peers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    router_id UUID REFERENCES routers(id) ON DELETE CASCADE,
    peer_name VARCHAR(255),
    public_key TEXT,
    endpoint VARCHAR(255),
    allowed_ips TEXT,
    last_handshake TIMESTAMP
);

CREATE TABLE router_configs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    router_id UUID REFERENCES routers(id) ON DELETE CASCADE,
    config_type VARCHAR(50) NOT NULL,
    config_data JSON,
    config_content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE router_vpn_configs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    router_id UUID UNIQUE REFERENCES routers(id) ON DELETE CASCADE,
    
    -- WireGuard configuration
    wireguard_public_key VARCHAR(255) UNIQUE NOT NULL,
    wireguard_private_key TEXT,
    vpn_ip_address INET UNIQUE NOT NULL,
    listen_port INTEGER DEFAULT 13231,
    
    -- Connection status
    vpn_connected BOOLEAN DEFAULT FALSE,
    last_handshake TIMESTAMP,
    bytes_received BIGINT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    
    -- RADIUS configuration
    radius_server_ip INET DEFAULT '10.10.10.1',
    radius_auth_port INTEGER DEFAULT 1812,
    radius_acct_port INTEGER DEFAULT 1813,
    radius_secret VARCHAR(255) NOT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_router_vpn_configs_router_id ON router_vpn_configs(router_id);
CREATE INDEX idx_router_vpn_configs_vpn_connected ON router_vpn_configs(vpn_connected);

-- =========================
-- ROUTER SERVICES (UUID)
-- =========================
CREATE TABLE router_services (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    router_id UUID NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    service_type VARCHAR(50) NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    interfaces JSON DEFAULT '[]',
    configuration JSON DEFAULT '{}',
    status VARCHAR(20) DEFAULT 'inactive',
    active_users INTEGER DEFAULT 0,
    total_sessions INTEGER DEFAULT 0,
    last_checked_at TIMESTAMP,
    enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT router_services_type_check CHECK (service_type IN ('hotspot', 'pppoe', 'vpn', 'firewall', 'dhcp', 'dns')),
    CONSTRAINT router_services_status_check CHECK (status IN ('active', 'inactive', 'error', 'starting', 'stopping'))
);

CREATE INDEX idx_router_services_router_id ON router_services(router_id);
CREATE INDEX idx_router_services_service_type ON router_services(service_type);
CREATE INDEX idx_router_services_status ON router_services(status);
CREATE INDEX idx_router_services_enabled ON router_services(enabled);

-- =========================
-- ACCESS POINTS (UUID)
-- =========================
CREATE TABLE access_points (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    router_id UUID REFERENCES routers(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    vendor VARCHAR(50) NOT NULL,
    model VARCHAR(100),
    ip_address VARCHAR(45) NOT NULL,
    mac_address VARCHAR(17),
    management_protocol VARCHAR(20) DEFAULT 'snmp',
    credentials JSON,
    location VARCHAR(255),
    status VARCHAR(20) DEFAULT 'unknown',
    active_users INTEGER DEFAULT 0,
    total_capacity INTEGER,
    signal_strength INTEGER,
    uptime_seconds BIGINT DEFAULT 0,
    last_seen_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT access_points_vendor_check CHECK (vendor IN ('ruijie', 'tenda', 'tplink', 'mikrotik', 'ubiquiti', 'other')),
    CONSTRAINT access_points_protocol_check CHECK (management_protocol IN ('snmp', 'ssh', 'api', 'telnet', 'http')),
    CONSTRAINT access_points_status_check CHECK (status IN ('online', 'offline', 'unknown', 'error'))
);

CREATE INDEX idx_access_points_router_id ON access_points(router_id);
CREATE INDEX idx_access_points_vendor ON access_points(vendor);
CREATE INDEX idx_access_points_status ON access_points(status);
CREATE INDEX idx_access_points_ip_address ON access_points(ip_address);

-- =========================
-- AP ACTIVE SESSIONS (UUID)
-- =========================
CREATE TABLE ap_active_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    access_point_id UUID NOT NULL REFERENCES access_points(id) ON DELETE CASCADE,
    router_id UUID REFERENCES routers(id) ON DELETE CASCADE,
    username VARCHAR(100),
    mac_address VARCHAR(17) NOT NULL,
    ip_address VARCHAR(45),
    session_id VARCHAR(100),
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at TIMESTAMP,
    bytes_in BIGINT DEFAULT 0,
    bytes_out BIGINT DEFAULT 0,
    signal_strength INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ap_sessions_ap_id ON ap_active_sessions(access_point_id);
CREATE INDEX idx_ap_sessions_router_id ON ap_active_sessions(router_id);
CREATE INDEX idx_ap_sessions_username ON ap_active_sessions(username);
CREATE INDEX idx_ap_sessions_mac_address ON ap_active_sessions(mac_address);

-- =========================
-- PACKAGES (UUID)
-- =========================
CREATE TABLE packages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    type VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    duration VARCHAR(50) NOT NULL,
    upload_speed VARCHAR(50) NOT NULL,
    download_speed VARCHAR(50) NOT NULL,
    speed VARCHAR(50),
    price FLOAT NOT NULL,
    devices INTEGER NOT NULL,
    data_limit VARCHAR(50),
    validity VARCHAR(50),
    enable_burst BOOLEAN DEFAULT FALSE,
    enable_schedule BOOLEAN DEFAULT FALSE,
    scheduled_activation_time TIMESTAMP,
    hide_from_client BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'active',
    is_active BOOLEAN DEFAULT TRUE,
    users_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT packages_type_check CHECK (type IN ('hotspot', 'pppoe')),
    CONSTRAINT packages_status_check CHECK (status IN ('active', 'inactive'))
);

CREATE INDEX idx_packages_tenant_id ON packages(tenant_id);
CREATE INDEX idx_packages_type ON packages(type);
CREATE INDEX idx_packages_status ON packages(status);
CREATE INDEX idx_packages_is_active ON packages(is_active);
CREATE INDEX idx_packages_scheduled_activation ON packages(scheduled_activation_time) WHERE scheduled_activation_time IS NOT NULL;

-- =========================
-- PAYMENTS & VOUCHERS (UUID)
-- =========================
CREATE TABLE payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    mac_address VARCHAR(17) NOT NULL,
    phone_number VARCHAR(15) NOT NULL,
    package_id UUID REFERENCES packages(id) ON DELETE CASCADE,
    router_id UUID REFERENCES routers(id) ON DELETE SET NULL,
    amount DECIMAL(10, 2) NOT NULL,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    mpesa_receipt VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending',
    payment_method VARCHAR(50) DEFAULT 'mpesa',
    callback_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payments_user_id ON payments(user_id);
CREATE INDEX idx_payments_status ON payments(status);
CREATE INDEX idx_payments_phone_number ON payments(phone_number);
CREATE INDEX idx_payments_created_at ON payments(created_at DESC);

-- User subscriptions (UUID)
CREATE TABLE user_subscriptions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    package_id UUID NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
    payment_id UUID REFERENCES payments(id) ON DELETE SET NULL,
    mac_address VARCHAR(17) NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    mikrotik_username VARCHAR(255),
    mikrotik_password VARCHAR(255),
    data_used_mb BIGINT DEFAULT 0,
    time_used_minutes INTEGER DEFAULT 0,
    next_payment_date DATE,
    grace_period_days INTEGER DEFAULT 3,
    grace_period_ends_at TIMESTAMP,
    auto_renew BOOLEAN DEFAULT false,
    disconnected_at TIMESTAMP,
    disconnection_reason VARCHAR(255),
    last_reminder_sent_at TIMESTAMP,
    reminder_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_subscriptions_status_check CHECK (status IN ('active', 'expired', 'suspended', 'grace_period', 'disconnected', 'cancelled'))
);
CREATE INDEX idx_user_subscriptions_user_id ON user_subscriptions(user_id);
CREATE INDEX idx_user_subscriptions_status ON user_subscriptions(status);
CREATE INDEX idx_user_subscriptions_end_time ON user_subscriptions(end_time);
CREATE INDEX idx_user_subscriptions_next_payment_date ON user_subscriptions(next_payment_date);
CREATE INDEX idx_user_subscriptions_grace_period_ends_at ON user_subscriptions(grace_period_ends_at);

-- =========================
-- PAYMENT REMINDERS (UUID)
-- =========================
CREATE TABLE payment_reminders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subscription_id UUID REFERENCES user_subscriptions(id) ON DELETE CASCADE,
    reminder_type VARCHAR(50) NOT NULL,
    days_before_due INTEGER,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channel VARCHAR(20) NOT NULL,
    status VARCHAR(20) DEFAULT 'sent',
    response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT payment_reminders_type_check CHECK (reminder_type IN ('due_soon', 'overdue', 'grace_period', 'disconnected', 'final_warning')),
    CONSTRAINT payment_reminders_channel_check CHECK (channel IN ('email', 'sms', 'in_app', 'push')),
    CONSTRAINT payment_reminders_status_check CHECK (status IN ('sent', 'failed', 'pending', 'delivered'))
);

CREATE INDEX idx_payment_reminders_user_id ON payment_reminders(user_id);
CREATE INDEX idx_payment_reminders_subscription_id ON payment_reminders(subscription_id);
CREATE INDEX idx_payment_reminders_type ON payment_reminders(reminder_type);
CREATE INDEX idx_payment_reminders_sent_at ON payment_reminders(sent_at DESC);

-- =========================
-- SERVICE CONTROL LOGS (UUID)
-- =========================
CREATE TABLE service_control_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    subscription_id UUID REFERENCES user_subscriptions(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL,
    reason VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending',
    radius_response JSON,
    executed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT service_control_logs_action_check CHECK (action IN ('disconnect', 'reconnect', 'suspend', 'activate', 'terminate')),
    CONSTRAINT service_control_logs_status_check CHECK (status IN ('pending', 'completed', 'failed', 'retrying'))
);

CREATE INDEX idx_service_control_logs_user_id ON service_control_logs(user_id);
CREATE INDEX idx_service_control_logs_subscription_id ON service_control_logs(subscription_id);
CREATE INDEX idx_service_control_logs_action ON service_control_logs(action);
CREATE INDEX idx_service_control_logs_status ON service_control_logs(status);
CREATE INDEX idx_service_control_logs_created_at ON service_control_logs(created_at DESC);

CREATE TABLE vouchers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code VARCHAR(255) UNIQUE NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    payment_id UUID NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
    package_id UUID NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
    duration_hours INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'unused',
    expires_at TIMESTAMP NOT NULL,
    mikrotik_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    payment_id UUID REFERENCES payments(id) ON DELETE CASCADE,
    voucher VARCHAR(255) UNIQUE NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_user_sessions_tenant_id ON user_sessions(tenant_id);
CREATE INDEX idx_user_sessions_payment_id ON user_sessions(payment_id);
CREATE INDEX idx_user_sessions_status ON user_sessions(status);

-- =========================
-- HOTSPOT USERS & SESSIONS (UUID)
-- =========================
CREATE TABLE hotspot_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone_number VARCHAR(255) UNIQUE NOT NULL,
    mac_address VARCHAR(17),
    
    -- Subscription details
    has_active_subscription BOOLEAN DEFAULT FALSE,
    package_name VARCHAR(255),
    package_id UUID REFERENCES packages(id) ON DELETE SET NULL,
    subscription_starts_at TIMESTAMP,
    subscription_expires_at TIMESTAMP,
    
    -- Data usage
    data_limit BIGINT COMMENT 'in bytes',
    data_used BIGINT DEFAULT 0 COMMENT 'in bytes',
    
    -- Login tracking
    last_login_at TIMESTAMP,
    last_login_ip VARCHAR(45),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    status VARCHAR(20) DEFAULT 'active',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP,
    
    CONSTRAINT hotspot_users_status_check CHECK (status IN ('active', 'suspended', 'expired'))
);

CREATE INDEX idx_hotspot_users_tenant_id ON hotspot_users(tenant_id);
CREATE INDEX idx_hotspot_users_username ON hotspot_users(username);
CREATE INDEX idx_hotspot_users_phone_number ON hotspot_users(phone_number);
CREATE INDEX idx_hotspot_users_has_active_subscription ON hotspot_users(has_active_subscription);
CREATE INDEX idx_hotspot_users_subscription_expires_at ON hotspot_users(subscription_expires_at);
CREATE INDEX idx_hotspot_users_package_id ON hotspot_users(package_id);

CREATE TABLE hotspot_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    hotspot_user_id UUID NOT NULL REFERENCES hotspot_users(id) ON DELETE CASCADE,
    
    -- Session details
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    session_start TIMESTAMP NOT NULL,
    session_end TIMESTAMP,
    last_activity TIMESTAMP,
    expires_at TIMESTAMP,
    
    -- Session status
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Data usage for this session
    bytes_uploaded BIGINT DEFAULT 0,
    bytes_downloaded BIGINT DEFAULT 0,
    total_bytes BIGINT DEFAULT 0,
    
    -- Connection details
    user_agent VARCHAR(255),
    device_type VARCHAR(50),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_hotspot_sessions_tenant_id ON hotspot_sessions(tenant_id);
CREATE INDEX idx_hotspot_sessions_hotspot_user_id ON hotspot_sessions(hotspot_user_id);
CREATE INDEX idx_hotspot_sessions_is_active ON hotspot_sessions(is_active);
CREATE INDEX idx_hotspot_sessions_session_start ON hotspot_sessions(session_start);
CREATE INDEX idx_hotspot_sessions_expires_at ON hotspot_sessions(expires_at);
CREATE INDEX idx_hotspot_sessions_mac_address ON hotspot_sessions(mac_address);

-- =========================
-- LOGGING (UUID)
-- =========================
CREATE TABLE system_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    action VARCHAR(255) NOT NULL,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- QUEUE TABLES (Keep BIGSERIAL for Laravel compatibility)
-- =========================
CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL,
    reserved_at INTEGER,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_jobs_queue ON jobs(queue);

CREATE TABLE job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options TEXT,
    cancelled_at INTEGER,
    created_at INTEGER NOT NULL,
    finished_at INTEGER
);

CREATE TABLE failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- HOTSPOT USERS TABLE (UUID)
-- =========================
CREATE TABLE IF NOT EXISTS hotspot_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone_number VARCHAR(255) UNIQUE NOT NULL,
    mac_address VARCHAR(255),
    
    -- Subscription details
    has_active_subscription BOOLEAN DEFAULT FALSE,
    package_name VARCHAR(255),
    package_id UUID,
    subscription_starts_at TIMESTAMP,
    subscription_expires_at TIMESTAMP,
    
    -- Data usage (in bytes)
    data_limit BIGINT,
    data_used BIGINT DEFAULT 0,
    
    -- Login tracking
    last_login_at TIMESTAMP,
    last_login_ip VARCHAR(255),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    status VARCHAR(50) DEFAULT 'active',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP,
    
    -- Foreign key
    CONSTRAINT fk_hotspot_user_package FOREIGN KEY (package_id) 
        REFERENCES packages(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_hotspot_users_username ON hotspot_users(username);
CREATE INDEX IF NOT EXISTS idx_hotspot_users_phone_number ON hotspot_users(phone_number);
CREATE INDEX IF NOT EXISTS idx_hotspot_users_has_active_subscription ON hotspot_users(has_active_subscription);
CREATE INDEX IF NOT EXISTS idx_hotspot_users_subscription_expires_at ON hotspot_users(subscription_expires_at);
CREATE INDEX IF NOT EXISTS idx_hotspot_users_deleted_at ON hotspot_users(deleted_at);

-- =========================
-- HOTSPOT SESSIONS TABLE (UUID)
-- =========================
CREATE TABLE IF NOT EXISTS hotspot_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    hotspot_user_id UUID NOT NULL,
    
    -- Session details
    mac_address VARCHAR(255),
    ip_address VARCHAR(255),
    session_start TIMESTAMP NOT NULL,
    session_end TIMESTAMP,
    last_activity TIMESTAMP,
    expires_at TIMESTAMP,
    
    -- Session status
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Data usage for this session (in bytes)
    bytes_uploaded BIGINT DEFAULT 0,
    bytes_downloaded BIGINT DEFAULT 0,
    total_bytes BIGINT DEFAULT 0,
    
    -- Connection details
    user_agent VARCHAR(500),
    device_type VARCHAR(100),
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign key
    CONSTRAINT fk_hotspot_session_user FOREIGN KEY (hotspot_user_id) 
        REFERENCES hotspot_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_hotspot_sessions_user_id ON hotspot_sessions(hotspot_user_id);
CREATE INDEX IF NOT EXISTS idx_hotspot_sessions_is_active ON hotspot_sessions(is_active);
CREATE INDEX IF NOT EXISTS idx_hotspot_sessions_session_start ON hotspot_sessions(session_start);
CREATE INDEX IF NOT EXISTS idx_hotspot_sessions_expires_at ON hotspot_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_hotspot_sessions_mac_address ON hotspot_sessions(mac_address);

-- =========================
-- RADIUS SESSIONS TABLE (UUID)
-- =========================
CREATE TABLE IF NOT EXISTS radius_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    hotspot_user_id UUID REFERENCES hotspot_users(id) ON DELETE CASCADE,
    payment_id UUID REFERENCES payments(id) ON DELETE SET NULL,
    package_id UUID REFERENCES packages(id) ON DELETE SET NULL,
    
    -- RADIUS data
    radacct_id BIGINT,
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
    status VARCHAR(20) DEFAULT 'active',
    disconnect_reason VARCHAR(100),
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_radius_sessions_hotspot_user ON radius_sessions(hotspot_user_id);
CREATE INDEX IF NOT EXISTS idx_radius_sessions_status ON radius_sessions(status);
CREATE INDEX IF NOT EXISTS idx_radius_sessions_expected_end ON radius_sessions(expected_end);
CREATE INDEX IF NOT EXISTS idx_radius_sessions_username ON radius_sessions(username);

-- =========================
-- HOTSPOT CREDENTIALS TABLE (UUID)
-- =========================
CREATE TABLE IF NOT EXISTS hotspot_credentials (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    hotspot_user_id UUID REFERENCES hotspot_users(id) ON DELETE CASCADE,
    payment_id UUID REFERENCES payments(id) ON DELETE SET NULL,
    
    -- Credentials
    username VARCHAR(64) NOT NULL,
    plain_password VARCHAR(64) NOT NULL,
    
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

CREATE INDEX IF NOT EXISTS idx_hotspot_credentials_user ON hotspot_credentials(hotspot_user_id);
CREATE INDEX IF NOT EXISTS idx_hotspot_credentials_phone ON hotspot_credentials(phone_number);
CREATE INDEX IF NOT EXISTS idx_hotspot_credentials_sms_sent ON hotspot_credentials(sms_sent);

-- =========================
-- SESSION DISCONNECTIONS TABLE (UUID)
-- =========================
CREATE TABLE IF NOT EXISTS session_disconnections (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    radius_session_id UUID REFERENCES radius_sessions(id) ON DELETE CASCADE,
    hotspot_user_id UUID REFERENCES hotspot_users(id) ON DELETE CASCADE,
    
    -- Disconnection details
    disconnect_method VARCHAR(50),
    disconnect_reason VARCHAR(255),
    disconnected_at TIMESTAMP NOT NULL,
    disconnected_by UUID REFERENCES users(id) ON DELETE SET NULL,
    
    -- Session summary
    total_duration BIGINT,
    total_data_used BIGINT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_session_disconnections_user ON session_disconnections(hotspot_user_id);
CREATE INDEX IF NOT EXISTS idx_session_disconnections_date ON session_disconnections(disconnected_at);
CREATE INDEX IF NOT EXISTS idx_session_disconnections_method ON session_disconnections(disconnect_method);

-- =========================
-- DATA USAGE LOGS TABLE (UUID)
-- =========================
CREATE TABLE IF NOT EXISTS data_usage_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    hotspot_user_id UUID REFERENCES hotspot_users(id) ON DELETE CASCADE,
    radius_session_id UUID REFERENCES radius_sessions(id) ON DELETE CASCADE,
    
    -- Usage data
    bytes_in BIGINT NOT NULL,
    bytes_out BIGINT NOT NULL,
    total_bytes BIGINT NOT NULL,
    
    -- Snapshot time
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Source
    source VARCHAR(50) DEFAULT 'radius_accounting'
);

CREATE INDEX IF NOT EXISTS idx_data_usage_logs_user ON data_usage_logs(hotspot_user_id);
CREATE INDEX IF NOT EXISTS idx_data_usage_logs_session ON data_usage_logs(radius_session_id);
CREATE INDEX IF NOT EXISTS idx_data_usage_logs_date ON data_usage_logs(recorded_at);

-- =========================
-- SAMPLE DATA (with UUIDs)
-- =========================
-- Insert packages with specific UUIDs for consistency
INSERT INTO packages (id, type, name, description, duration, upload_speed, download_speed, speed, price, devices, data_limit, validity, enable_burst, enable_schedule, hide_from_client, status, is_active, users_count) VALUES
('11111111-1111-1111-1111-111111111111', 'hotspot', '1 Hour - 5GB', 'Perfect for quick browsing', '1 hour', '3 Mbps', '3 Mbps', '3 Mbps', 50.00, 1, '5 GB', '1 hour', FALSE, FALSE, FALSE, 'active', TRUE, 45),
('22222222-2222-2222-2222-222222222222', 'hotspot', '1 Day - 20GB', 'Full day unlimited access', '12 hours', '10 Mbps', '10 Mbps', '10 Mbps', 200.00, 2, '20 GB', '24 hours', FALSE, FALSE, FALSE, 'active', TRUE, 120),
('33333333-3333-3333-3333-333333333333', 'pppoe', 'Home Basic - 10 Mbps', 'Residential internet package', '30 days', '10 Mbps', '10 Mbps', '10 Mbps', 2000.00, 1, NULL, '30 days', TRUE, FALSE, FALSE, 'active', TRUE, 35),
('44444444-4444-4444-4444-444444444444', 'pppoe', 'Home Premium - 20 Mbps', 'Fast home internet', '30 days', '20 Mbps', '20 Mbps', '20 Mbps', 3500.00, 3, NULL, '30 days', TRUE, TRUE, FALSE, 'active', TRUE, 28),
('55555555-5555-5555-5555-555555555555', 'hotspot', '1 Week - 50GB', 'Weekly hotspot package', '7 days', '10 Mbps', '10 Mbps', '10 Mbps', 500.00, 2, '50 GB', '7 days', FALSE, FALSE, FALSE, 'active', TRUE, 67),
('66666666-6666-6666-6666-666666666666', 'pppoe', 'Business - 50 Mbps', 'For small businesses', '30 days', '50 Mbps', '50 Mbps', '50 Mbps', 7500.00, 5, NULL, '30 days', TRUE, TRUE, FALSE, 'inactive', FALSE, 0)
ON CONFLICT (id) DO NOTHING;

-- Note: Default system administrator (sysadmin) is already inserted above - No need for duplicate admin user here

-- Seed example NAS
INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('192.168.88.1', 'mikrotik', 'other', 0, 'testing123', 'MikroTik Router')
ON CONFLICT (nasname) DO NOTHING;

-- Insert test hotspot user
INSERT INTO hotspot_users (
    id,
    username, 
    password, 
    phone_number, 
    mac_address,
    has_active_subscription,
    package_name,
    package_id,
    subscription_starts_at,
    subscription_expires_at,
    data_limit,
    data_used,
    is_active,
    status,
    created_at,
    updated_at
) VALUES (
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    'testuser',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+254712345678',
    'D6:D2:52:1C:90:71',
    TRUE,
    'Normal 1 Hour',
    '11111111-1111-1111-1111-111111111111',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP + INTERVAL '24 hours',
    1073741824,
    0,
    TRUE,
    'active',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON CONFLICT (username) DO NOTHING;

-- Insert another test user with expired subscription
INSERT INTO hotspot_users (
    id,
    username, 
    password, 
    phone_number,
    has_active_subscription,
    package_name,
    subscription_starts_at,
    subscription_expires_at,
    data_limit,
    data_used,
    is_active,
    status,
    created_at,
    updated_at
) VALUES (
    'cccccccc-cccc-cccc-cccc-cccccccccccc',
    'expireduser',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+254712345679',
    FALSE,
    'Normal 1 Hour',
    CURRENT_TIMESTAMP - INTERVAL '2 days',
    CURRENT_TIMESTAMP - INTERVAL '1 day',
    1073741824,
    0,
    TRUE,
    'expired',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON CONFLICT (username) DO NOTHING;

-- =========================
-- COMMENTS FOR DOCUMENTATION
-- =========================
COMMENT ON TABLE hotspot_users IS 'Stores hotspot user credentials and subscription information';
COMMENT ON TABLE hotspot_sessions IS 'Tracks active and historical hotspot user sessions';
COMMENT ON TABLE radius_sessions IS 'Enhanced session tracking linking hotspot users with RADIUS accounting';
COMMENT ON TABLE hotspot_credentials IS 'Temporary storage of credentials for SMS delivery tracking';
COMMENT ON TABLE session_disconnections IS 'Audit log of all session disconnections';
COMMENT ON TABLE data_usage_logs IS 'Time-series data usage tracking for analytics';

-- =========================
-- TRIGGERS FOR UPDATED_AT TIMESTAMPS
-- =========================
CREATE OR REPLACE FUNCTION update_hotspot_users_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_hotspot_users_updated_at
    BEFORE UPDATE ON hotspot_users
    FOR EACH ROW
    EXECUTE FUNCTION update_hotspot_users_updated_at();

CREATE OR REPLACE FUNCTION update_hotspot_sessions_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_hotspot_sessions_updated_at
    BEFORE UPDATE ON hotspot_sessions
    FOR EACH ROW
    EXECUTE FUNCTION update_hotspot_sessions_updated_at();

CREATE OR REPLACE FUNCTION update_radius_sessions_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_radius_sessions_updated_at
    BEFORE UPDATE ON radius_sessions
    FOR EACH ROW
    EXECUTE FUNCTION update_radius_sessions_updated_at();

-- =========================
-- ACCESS POINTS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS access_points (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    router_id UUID,
    name VARCHAR(100) NOT NULL,
    vendor VARCHAR(50) NOT NULL,
    model VARCHAR(100),
    ip_address VARCHAR(45) NOT NULL,
    mac_address VARCHAR(17),
    management_protocol VARCHAR(20) DEFAULT 'snmp',
    credentials JSONB,
    location VARCHAR(255),
    status VARCHAR(20) DEFAULT 'unknown',
    active_users INTEGER DEFAULT 0,
    total_capacity INTEGER,
    signal_strength INTEGER,
    uptime_seconds BIGINT DEFAULT 0,
    last_seen_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT access_points_vendor_check CHECK (vendor IN ('ruijie', 'tenda', 'tplink', 'mikrotik', 'ubiquiti', 'other')),
    CONSTRAINT access_points_protocol_check CHECK (management_protocol IN ('snmp', 'ssh', 'api', 'telnet', 'http')),
    CONSTRAINT access_points_status_check CHECK (status IN ('online', 'offline', 'unknown', 'error')),
    CONSTRAINT fk_access_points_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_access_points_router_id ON access_points(router_id);
CREATE INDEX IF NOT EXISTS idx_access_points_vendor ON access_points(vendor);
CREATE INDEX IF NOT EXISTS idx_access_points_status ON access_points(status);
CREATE INDEX IF NOT EXISTS idx_access_points_ip_address ON access_points(ip_address);

COMMENT ON TABLE access_points IS 'WiFi Access Points connected to routers for hotspot service';

-- =========================
-- PERFORMANCE METRICS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS performance_metrics (
    id BIGSERIAL PRIMARY KEY,
    recorded_at TIMESTAMP NOT NULL,
    
    -- TPS Metrics
    tps_current NUMERIC(10, 2) DEFAULT 0,
    tps_average NUMERIC(10, 2) DEFAULT 0,
    tps_max NUMERIC(10, 2) DEFAULT 0,
    tps_min NUMERIC(10, 2) DEFAULT 0,
    
    -- OPS Metrics (Redis)
    ops_current NUMERIC(10, 2) DEFAULT 0,
    
    -- Database Metrics
    db_active_connections INTEGER DEFAULT 0,
    db_total_queries BIGINT DEFAULT 0,
    db_slow_queries INTEGER DEFAULT 0,
    
    -- Cache Metrics
    cache_keys BIGINT DEFAULT 0,
    cache_memory_used VARCHAR(50),
    cache_hit_rate NUMERIC(5, 2) DEFAULT 0,
    
    -- System Metrics
    active_sessions INTEGER DEFAULT 0,
    pending_jobs INTEGER DEFAULT 0,
    failed_jobs INTEGER DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for efficient querying
CREATE INDEX IF NOT EXISTS idx_performance_metrics_recorded_at ON performance_metrics(recorded_at);
CREATE INDEX IF NOT EXISTS idx_performance_metrics_recorded_tps ON performance_metrics(recorded_at, tps_current);
CREATE INDEX IF NOT EXISTS idx_performance_metrics_recorded_ops ON performance_metrics(recorded_at, ops_current);

COMMENT ON TABLE performance_metrics IS 'Historical performance metrics for TPS, OPS, database, cache, and system monitoring';
