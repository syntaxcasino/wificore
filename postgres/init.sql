-- =========================
-- USERS
-- =========================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- PASSWORD RESET TOKENS
-- =========================
CREATE TABLE password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token VARCHAR(255),
    created_at TIMESTAMP
);

-- =========================
-- SESSIONS
-- =========================
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT,
    last_activity INTEGER
);

-- =========================
-- MIKROTIK ROUTERS
-- =========================
CREATE TABLE routers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    model VARCHAR(255),
    os_version VARCHAR(50),
    last_seen TIMESTAMP,
    port INTEGER DEFAULT 8728,
    username VARCHAR(100) NOT NULL,
    password TEXT NOT NULL, -- Changed from VARCHAR(100) to TEXT for encrypted passwords
    location VARCHAR(255),
    status VARCHAR(50) DEFAULT 'pending', -- Changed from VARCHAR(20) to VARCHAR(50)
    interface_assignments JSON DEFAULT '[]', -- Explicit JSON with default
    configurations JSON DEFAULT '[]', -- Explicit JSON with default
    config_token VARCHAR(255) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- WIREGUARD PEERS
-- =========================
CREATE TABLE wireguard_peers (
    id SERIAL PRIMARY KEY,
    router_id INTEGER REFERENCES routers(id) ON DELETE CASCADE,
    peer_name VARCHAR(255),
    public_key TEXT,
    allowed_ips TEXT,
    transfer_rx BIGINT,
    transfer_tx BIGINT,
    last_handshake TIMESTAMP
);

-- =========================
-- ROUTER CONFIGS
-- =========================
CREATE TABLE router_configs (
    id SERIAL PRIMARY KEY,
    router_id INTEGER REFERENCES routers(id) ON DELETE CASCADE,
    config_type VARCHAR(50) NOT NULL,
    config_content TEXT NOT NULL,
    last_updated TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- PACKAGES
-- =========================
CREATE TABLE packages (
    id SERIAL PRIMARY KEY,
    type VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    duration VARCHAR(50) NOT NULL,
    upload_speed VARCHAR(50) NOT NULL,
    download_speed VARCHAR(50) NOT NULL,
    price FLOAT NOT NULL,
    devices INTEGER NOT NULL,
    enable_burst BOOLEAN DEFAULT FALSE,
    enable_schedule BOOLEAN DEFAULT FALSE,
    hide_from_client BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- PAYMENTS
-- =========================
CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    mac_address VARCHAR(17) NOT NULL,
    phone_number VARCHAR(15) NOT NULL,
    package_id INTEGER REFERENCES packages(id) ON DELETE CASCADE,
    router_id INTEGER REFERENCES routers(id) ON DELETE SET NULL,
    amount FLOAT NOT NULL,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    callback_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- SYSTEM LOGS
-- =========================
CREATE TABLE system_logs (
    id SERIAL PRIMARY KEY,
    action VARCHAR(255) NOT NULL,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- USER SESSIONS
-- =========================
CREATE TABLE user_sessions (
    id SERIAL PRIMARY KEY,
    payment_id INTEGER REFERENCES payments(id) ON DELETE CASCADE,
    voucher VARCHAR(255) UNIQUE NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- VOUCHERS
-- =========================
CREATE TABLE vouchers (
    id SERIAL PRIMARY KEY,
    code VARCHAR(255) UNIQUE NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    payment_id INTEGER NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
    package_id INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
    duration_hours INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'unused',
    expires_at TIMESTAMP NOT NULL,
    mikrotik_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- SAMPLE DATA
-- =========================
INSERT INTO packages (type, name, duration, upload_speed, download_speed, price, devices, enable_burst, enable_schedule, hide_from_client) VALUES
('Basic', 'Normal 1 Hour', '1 hour', '3 Mbps', '3 Mbps', 1.00, 1, FALSE, FALSE, FALSE),
('Basic', 'Normal 12 Hours', '12 hours', '3 Mbps', '3 Mbps', 20.00, 2, FALSE, FALSE, FALSE),
('Premium', 'High 1 Hour', '1 hour', '10 Mbps', '10 Mbps', 15.00, 1, TRUE, FALSE, FALSE),
('Premium', 'High 12 Hours', '12 hours', '10 Mbps', '10 Mbps', 25.00, 3, TRUE, TRUE, FALSE);

INSERT INTO routers (name, ip_address, username, password, port, status, interface_assignments, configurations, config_token, created_at, updated_at) VALUES 
('Main Office Router', '192.168.100.30', 'admin', 'encrypted_password_example', 8728, 'pending', '[]', '[]', '123e4567-e89b-12d3-a456-426614174000', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

INSERT INTO users (name, email, password, created_at, updated_at) VALUES 
('Admin User', 'admin@example.com', '$2y$10$exampleHashedPasswordHere', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- =========================
-- INDEXES
-- =========================

-- USERS
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_created_at ON users(created_at DESC);

-- MIKROTIK ROUTERS
CREATE INDEX idx_routers_ip_address ON routers(ip_address);
CREATE INDEX idx_routers_name ON routers(name);
CREATE INDEX idx_routers_status ON routers(status);
CREATE INDEX idx_routers_config_token ON routers(config_token);

-- WIREGUARD PEERS
CREATE INDEX idx_peers_router_id ON wireguard_peers(router_id);
CREATE INDEX idx_peers_peer_name ON wireguard_peers(peer_name);

-- ROUTER CONFIGS
CREATE INDEX idx_router_configs_router_id ON router_configs(router_id);
CREATE INDEX idx_router_configs_config_type ON router_configs(config_type);

-- PACKAGES
CREATE INDEX idx_packages_price ON packages(price);
CREATE INDEX idx_packages_type ON packages(type);

-- PAYMENTS
CREATE INDEX idx_payments_mac ON payments(mac_address);
CREATE INDEX idx_payments_phone ON payments(phone_number);
CREATE INDEX idx_payments_transaction_id ON payments(transaction_id);
CREATE INDEX idx_payments_status ON payments(status);
CREATE INDEX idx_payments_created_at ON payments(created_at DESC);
CREATE INDEX idx_payments_package_router ON payments(package_id, router_id);

-- SYSTEM LOGS
CREATE INDEX idx_logs_action ON system_logs(action);
CREATE INDEX idx_logs_created_at ON system_logs(created_at DESC);
CREATE INDEX idx_logs_action_created ON system_logs(action, created_at DESC);

-- USER SESSIONS
CREATE INDEX idx_sessions_mac ON user_sessions(mac_address);
CREATE INDEX idx_sessions_status ON user_sessions(status);
CREATE INDEX idx_sessions_start_end ON user_sessions(start_time, end_time);
CREATE INDEX idx_sessions_payment_id ON user_sessions(payment_id);

-- VOUCHERS
CREATE INDEX idx_vouchers_mac ON vouchers(mac_address);
CREATE INDEX idx_vouchers_mac_status ON vouchers(mac_address, status);
CREATE INDEX idx_vouchers_mac_expires ON vouchers(mac_address, expires_at DESC);
CREATE INDEX idx_vouchers_payment_package ON vouchers(payment_id, package_id);
CREATE INDEX idx_vouchers_status ON vouchers(status);

-- PASSWORD RESET
CREATE INDEX idx_reset_created_at ON password_reset_tokens(created_at);

-- SESSIONS
CREATE INDEX idx_sessions_user_id ON sessions(user_id);
CREATE INDEX idx_sessions_last_activity ON sessions(last_activity DESC);
