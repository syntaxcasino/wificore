CREATE TABLE IF NOT EXISTS packages (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    duration_hours INTEGER NOT NULL,
    mikrotik_profile VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO packages (name, description, price, duration_hours, mikrotik_profile)
SELECT 'Basic Plan', '1GB data for 1 hour', 1.00, 1, 'basic_profile'
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Basic Plan');
INSERT INTO packages (name, description, price, duration_hours, mikrotik_profile)
SELECT 'Standard Plan', '3GB data for 24 hours', 150.00, 24, 'standard_profile'
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Standard Plan');
INSERT INTO packages (name, description, price, duration_hours, mikrotik_profile)
SELECT 'Premium Plan', '10GB data for 72 hours', 400.00, 72, 'premium_profile'
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name = 'Premium Plan');

CREATE TABLE IF NOT EXISTS system_logs (
    id SERIAL PRIMARY KEY,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);



CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    package_id INTEGER REFERENCES packages(id),
    status VARCHAR(50) NOT NULL,
    mac_address VARCHAR(17), -- Stores MAC address (e.g., D6:D2:52:1C:90:71)
    transaction_id VARCHAR(100), -- Stores M-Pesa CheckoutRequestID
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);