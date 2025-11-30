-- ========================================
-- TENANT AWARENESS FIX FOR INIT.SQL
-- ========================================
-- This script adds tenant_id to all tables that need it
-- Run this AFTER the main init.sql

-- Add tenant_id to packages table
ALTER TABLE packages ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE packages ADD CONSTRAINT packages_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_packages_tenant_id ON packages(tenant_id);

-- Add tenant_id to payments table
ALTER TABLE payments ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE payments ADD CONSTRAINT payments_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_payments_tenant_id ON payments(tenant_id);

-- Add tenant_id to vouchers table
ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE vouchers ADD CONSTRAINT vouchers_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_vouchers_tenant_id ON vouchers(tenant_id);

-- Add tenant_id to hotspot_users table
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE hotspot_users ADD CONSTRAINT hotspot_users_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_hotspot_users_tenant_id ON hotspot_users(tenant_id);

-- Add tenant_id to user_sessions table
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_user_sessions_tenant_id ON user_sessions(tenant_id);

-- Add tenant_id to hotspot_sessions table
ALTER TABLE hotspot_sessions ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE hotspot_sessions ADD CONSTRAINT hotspot_sessions_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_hotspot_sessions_tenant_id ON hotspot_sessions(tenant_id);

-- Add tenant_id to router_services table
ALTER TABLE router_services ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE router_services ADD CONSTRAINT router_services_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_router_services_tenant_id ON router_services(tenant_id);

-- Add tenant_id to access_points table
ALTER TABLE access_points ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE access_points ADD CONSTRAINT access_points_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_access_points_tenant_id ON access_points(tenant_id);

-- Add tenant_id to system_logs table
ALTER TABLE system_logs ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE system_logs ADD CONSTRAINT system_logs_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_system_logs_tenant_id ON system_logs(tenant_id);

-- Add tenant_id to ap_active_sessions table
ALTER TABLE ap_active_sessions ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE ap_active_sessions ADD CONSTRAINT ap_active_sessions_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_ap_active_sessions_tenant_id ON ap_active_sessions(tenant_id);

-- Add tenant_id to service_control_logs table
ALTER TABLE service_control_logs ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE service_control_logs ADD CONSTRAINT service_control_logs_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_service_control_logs_tenant_id ON service_control_logs(tenant_id);

-- Add tenant_id to payment_reminders table
ALTER TABLE payment_reminders ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE payment_reminders ADD CONSTRAINT payment_reminders_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_payment_reminders_tenant_id ON payment_reminders(tenant_id);

-- Add tenant_id to router_vpn_configs table
ALTER TABLE router_vpn_configs ADD COLUMN IF NOT EXISTS tenant_id UUID;
ALTER TABLE router_vpn_configs ADD CONSTRAINT router_vpn_configs_tenant_id_fkey 
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_router_vpn_configs_tenant_id ON router_vpn_configs(tenant_id);

COMMIT;
