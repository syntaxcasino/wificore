CREATE TABLE packages (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price FLOAT NOT NULL,
    duration_hours INTEGER NOT NULL,
    mikrotik_profile VARCHAR(255) NOT NULL,
    speed_type VARCHAR(50) DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    mac_address VARCHAR(17) NOT NULL,
    phone_number VARCHAR(15) NOT NULL, -- Updated length
    package_id INTEGER REFERENCES packages(id) ON DELETE CASCADE,
    amount FLOAT NOT NULL,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    callback_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE system_logs (
    id SERIAL PRIMARY KEY,
    action VARCHAR(255) NOT NULL,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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

INSERT INTO packages (name, description, price, duration_hours, mikrotik_profile, speed_type) VALUES
('Normal 1 Hour', '3mbps for 1 hour', 1.00, 1, 'normal_3mbps', 'normal'),
('Normal 12 Hours', '3mbps for 12 hours', 20.00, 12, 'normal_3mbps', 'normal'),
('High 1 Hour', '10mbps for 1 hour', 15.00, 1, 'high_10mbps', 'high'),
('High 12 Hours', '10mbps for 12 hours', 25.00, 12, 'high_10mbps', 'high');