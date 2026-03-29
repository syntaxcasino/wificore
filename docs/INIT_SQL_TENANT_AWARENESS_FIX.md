# Init.SQL Tenant Awareness Fix

**Date**: Oct 28, 2025  
**Status**: 🔴 **CRITICAL - INIT.SQL NOT TENANT-AWARE**  
**Priority**: **IMMEDIATE FIX REQUIRED**

---

## 🚨 **CRITICAL ISSUE FOUND**

**The `init.sql` file is NOT fully tenant-aware!**

### **Tables Missing `tenant_id`**:

1. ❌ **packages** - CRITICAL
2. ❌ **payments** - CRITICAL
3. ❌ **vouchers** - CRITICAL
4. ❌ **hotspot_users** - CRITICAL
5. ❌ **user_sessions** - CRITICAL
6. ❌ **hotspot_sessions** - CRITICAL
7. ❌ **router_services** - CRITICAL
8. ❌ **access_points** - CRITICAL
9. ❌ **system_logs** - CRITICAL
10. ❌ **ap_active_sessions** - CRITICAL
11. ❌ **service_control_logs** - CRITICAL
12. ❌ **payment_reminders** - CRITICAL
13. ❌ **router_vpn_configs** - CRITICAL

### **Tables WITH `tenant_id`** ✅:
1. ✅ users
2. ✅ routers

---

## 📋 **REQUIRED CHANGES TO INIT.SQL**

### **1. packages Table**

**Current** (Line 367):
```sql
CREATE TABLE packages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    type VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    -- ... other fields
```

**Should Be**:
```sql
CREATE TABLE packages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    type VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    -- ... other fields
```

**Add Index**:
```sql
CREATE INDEX idx_packages_tenant_id ON packages(tenant_id);
```

---

### **2. payments Table**

**Add After Line 402**:
```sql
CREATE TABLE payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_payments_tenant_id ON payments(tenant_id);
```

---

### **3. vouchers Table**

**Add tenant_id**:
```sql
CREATE TABLE vouchers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_vouchers_tenant_id ON vouchers(tenant_id);
```

---

### **4. hotspot_users Table**

**Add tenant_id**:
```sql
CREATE TABLE hotspot_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_hotspot_users_tenant_id ON hotspot_users(tenant_id);
```

---

### **5. user_sessions Table**

**Add tenant_id**:
```sql
CREATE TABLE user_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_user_sessions_tenant_id ON user_sessions(tenant_id);
```

---

### **6. hotspot_sessions Table**

**Add tenant_id**:
```sql
CREATE TABLE hotspot_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_hotspot_sessions_tenant_id ON hotspot_sessions(tenant_id);
```

---

### **7. router_services Table**

**Current** (Line 284):
```sql
CREATE TABLE router_services (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    router_id UUID NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Should Be**:
```sql
CREATE TABLE router_services (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    router_id UUID NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_router_services_tenant_id ON router_services(tenant_id);
```

---

### **8. access_points Table**

**Current** (Line 310):
```sql
CREATE TABLE access_points (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    router_id UUID REFERENCES routers(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Should Be**:
```sql
CREATE TABLE access_points (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    router_id UUID REFERENCES routers(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_access_points_tenant_id ON access_points(tenant_id);
```

---

### **9. system_logs Table**

**Add tenant_id**:
```sql
CREATE TABLE system_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_system_logs_tenant_id ON system_logs(tenant_id);
```

---

### **10. ap_active_sessions Table**

**Add tenant_id**:
```sql
CREATE TABLE ap_active_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_ap_active_sessions_tenant_id ON ap_active_sessions(tenant_id);
```

---

### **11. service_control_logs Table**

**Add tenant_id**:
```sql
CREATE TABLE service_control_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_service_control_logs_tenant_id ON service_control_logs(tenant_id);
```

---

### **12. payment_reminders Table**

**Add tenant_id**:
```sql
CREATE TABLE payment_reminders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_payment_reminders_tenant_id ON payment_reminders(tenant_id);
```

---

### **13. router_vpn_configs Table**

**Current** (Line 252):
```sql
CREATE TABLE router_vpn_configs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    router_id UUID UNIQUE REFERENCES routers(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Should Be**:
```sql
CREATE TABLE router_vpn_configs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    router_id UUID UNIQUE REFERENCES routers(id) ON DELETE CASCADE,
    -- ... rest of fields
```

**Add Index**:
```sql
CREATE INDEX idx_router_vpn_configs_tenant_id ON router_vpn_configs(tenant_id);
```

---

## 🛠️ **FIX OPTIONS**

### **Option 1: Run Fix Script (Recommended)**

File created: `postgres/init-tenant-aware-fix.sql`

```bash
# Run this script on existing database
psql -U postgres -d wifi_hotspot -f postgres/init-tenant-aware-fix.sql
```

This will add `tenant_id` to all tables that need it.

---

### **Option 2: Manual Update of init.sql**

1. Backup current init.sql
2. Add `tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE` to each table listed above
3. Add indexes for each tenant_id column
4. Test with fresh database

---

## ⚠️ **IMPACT ANALYSIS**

### **Current State**:
- ❌ init.sql creates tables WITHOUT tenant_id
- ❌ Fresh installations won't have tenant isolation
- ❌ Migrations add tenant_id later (inconsistent)
- ❌ Risk of data corruption

### **After Fix**:
- ✅ init.sql creates tenant-aware tables from start
- ✅ Fresh installations have proper isolation
- ✅ Consistent with Laravel migrations
- ✅ No data corruption risk

---

## 📋 **VERIFICATION CHECKLIST**

After fixing init.sql, verify:

- [ ] All 13 tables have tenant_id column
- [ ] All tenant_id columns have foreign key constraints
- [ ] All tenant_id columns have indexes
- [ ] All tenant_id columns are NOT NULL
- [ ] CASCADE DELETE configured
- [ ] Test fresh database creation
- [ ] Verify migrations still work
- [ ] Test tenant isolation

---

## 🔄 **SYNC WITH MIGRATIONS**

The init.sql should match the Laravel migrations:

**Migration**: `2025_10_28_000002_add_tenant_id_to_tables.php`

**Tables in Migration**:
1. ✅ users
2. ✅ packages
3. ✅ routers
4. ✅ payments
5. ✅ user_sessions
6. ✅ vouchers
7. ✅ hotspot_users
8. ✅ hotspot_sessions
9. ✅ router_vpn_configs
10. ✅ router_services
11. ✅ access_points
12. ✅ ap_active_sessions
13. ✅ service_control_logs
14. ✅ payment_reminders
15. ✅ system_logs

**All these tables MUST have tenant_id in init.sql!**

---

## 🎯 **ACTION ITEMS**

### **Immediate** (Today):
1. ⏳ Run `init-tenant-aware-fix.sql` on existing databases
2. ⏳ Update init.sql with tenant_id for all tables
3. ⏳ Test fresh database creation
4. ⏳ Verify tenant isolation works

### **Short-term** (This Week):
5. ⏳ Update documentation
6. ⏳ Create test suite for init.sql
7. ⏳ Verify all environments updated

---

**Status**: 🔴 **CRITICAL FIX REQUIRED**  
**Priority**: **IMMEDIATE**  
**Impact**: **HIGH - Data isolation at risk**  
**Fix Available**: ✅ **YES - init-tenant-aware-fix.sql created**

**Run the fix script immediately to ensure tenant isolation!** 🚨🔒
