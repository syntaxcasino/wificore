# Migration vs init.sql Schema Comparison & Fixes

**Date:** October 30, 2025, 1:00 AM  
**Status:** 🔧 **CRITICAL MISMATCHES FOUND**

---

## 🎯 Problem

The `init copy 2.sql` defines tables with a different schema than Laravel migrations, causing conflicts when the database is initialized.

---

## 📊 Critical Mismatches Found

### 1. **ROUTERS TABLE** ❌ MISMATCH

#### init copy 2.sql (Lines 199-224)
```sql
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
    provisioning_stage VARCHAR(50),                    -- ❌ MISSING in migration
    interface_assignments JSON DEFAULT '[]',
    configurations JSON DEFAULT '[]',
    config_token VARCHAR(255) UNIQUE,                  -- ❌ VARCHAR, not UUID!
    vendor VARCHAR(50) DEFAULT 'mikrotik',
    device_type VARCHAR(50) DEFAULT 'router',
    capabilities JSON DEFAULT '[]',
    interface_list JSON DEFAULT '[]',
    reserved_interfaces JSON DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP
);
```

#### Migration (2025_07_01_140000_create_routers_table.php)
```php
Schema::create('routers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->string('name');
    $table->string('ip_address')->unique();
    $table->string('username');
    $table->string('password');
    $table->integer('port')->default(8728);
    $table->uuid('config_token')->nullable();          // ❌ UUID, not VARCHAR!
    $table->string('status', 20)->default('pending');
    $table->string('vendor', 50)->default('mikrotik');
    $table->string('device_type', 50)->default('router');
    $table->string('model')->nullable();
    $table->string('firmware_version')->nullable();
    $table->string('os_version')->nullable();
    $table->timestamp('last_checked')->nullable();
    $table->json('capabilities')->nullable();
    $table->timestamp('last_seen')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

**Differences:**
- ❌ `config_token`: VARCHAR(255) vs UUID
- ❌ `provisioning_stage`: Missing in migration
- ❌ `location`: Missing in migration
- ❌ `interface_assignments`: Missing in migration
- ❌ `configurations`: Missing in migration
- ❌ `interface_list`: Missing in migration
- ❌ `reserved_interfaces`: Missing in migration
- ❌ `firmware_version`: Missing in init.sql
- ❌ `last_checked`: Missing in init.sql

---

### 2. **USER_SESSIONS TABLE** ❌ MISMATCH

#### init copy 2.sql (Lines 526-537)
```sql
CREATE TABLE user_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    payment_id UUID REFERENCES payments(id) ON DELETE CASCADE,
    voucher VARCHAR(255) UNIQUE NOT NULL,              -- ❌ NOT NULL
    mac_address VARCHAR(17) NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### Migration (2025_06_22_120557_create_user_sessions_table.php)
```php
Schema::create('user_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('user_id');                           // ❌ Missing in init.sql
    $table->uuid('payment_id')->nullable();
    $table->uuid('package_id')->nullable();            // ❌ Missing in init.sql
    $table->string('session_token')->unique();         // ❌ Missing in init.sql
    $table->string('voucher')->nullable();             // ❌ NULLABLE, not NOT NULL
    $table->string('mac_address', 17)->nullable();
    $table->string('ip_address', 45)->nullable();      // ❌ Missing in init.sql
    $table->text('user_agent')->nullable();            // ❌ Missing in init.sql
    $table->string('status')->default('active');
    $table->timestamp('start_time')->nullable();
    $table->timestamp('end_time')->nullable();
    $table->timestamp('last_activity')->nullable();    // ❌ Missing in init.sql
    $table->timestamp('expires_at')->nullable();       // ❌ Missing in init.sql
    $table->bigInteger('data_used')->nullable();       // ❌ Missing in init.sql
    $table->timestamps();
});
```

**Differences:**
- ❌ `user_id`: Missing in init.sql
- ❌ `package_id`: Missing in init.sql
- ❌ `session_token`: Missing in init.sql
- ❌ `voucher`: NOT NULL vs NULLABLE
- ❌ `ip_address`: Missing in init.sql
- ❌ `user_agent`: Missing in init.sql
- ❌ `last_activity`: Missing in init.sql
- ❌ `expires_at`: Missing in init.sql
- ❌ `data_used`: Missing in init.sql

---

### 3. **PACKAGES TABLE** ✅ MOSTLY MATCH

#### init copy 2.sql (Lines 371-396)
```sql
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
    enable_schedule BOOLEAN DEFAULT FALSE,              // ✅ Present
    scheduled_activation_time TIMESTAMP,                // ✅ Present
    hide_from_client BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'active',
    is_active BOOLEAN DEFAULT TRUE,
    users_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Status:** ✅ Matches migration (after our fixes)

---

### 4. **PERFORMANCE_METRICS TABLE** ❌ MISMATCH

#### init copy 2.sql (Lines 1059-1089)
```sql
CREATE TABLE IF NOT EXISTS performance_metrics (
    id BIGSERIAL PRIMARY KEY,                          -- ❌ BIGSERIAL, not UUID!
    recorded_at TIMESTAMP NOT NULL,
    tps_current NUMERIC(10, 2) DEFAULT 0,
    tps_average NUMERIC(10, 2) DEFAULT 0,
    tps_max NUMERIC(10, 2) DEFAULT 0,
    tps_min NUMERIC(10, 2) DEFAULT 0,
    ops_current NUMERIC(10, 2) DEFAULT 0,
    db_active_connections INTEGER DEFAULT 0,
    db_total_queries BIGINT DEFAULT 0,
    db_slow_queries INTEGER DEFAULT 0,
    cache_keys BIGINT DEFAULT 0,
    cache_memory_used VARCHAR(50),
    cache_hit_rate NUMERIC(5, 2) DEFAULT 0,
    active_sessions INTEGER DEFAULT 0,
    pending_jobs INTEGER DEFAULT 0,
    failed_jobs INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### Migration (2025_10_17_000001_create_performance_metrics_table.php)
```php
Schema::create('performance_metrics', function (Blueprint $table) {
    $table->uuid('id')->primary();                     // ❌ UUID, not BIGSERIAL!
    $table->uuid('tenant_id');                         // ❌ Missing in init.sql
    // ... rest of fields
});
```

**Differences:**
- ❌ `id`: BIGSERIAL vs UUID
- ❌ `tenant_id`: Missing in init.sql

---

## ✅ SOLUTION: Fix Migrations to Match init.sql

Since `init copy 2.sql` appears to be the reference schema, we need to update migrations to match it.

### Fix #1: Update routers Migration

**File:** `backend/database/migrations/2025_07_01_140000_create_routers_table.php`

```php
Schema::create('routers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->string('name', 100);
    $table->string('ip_address', 45)->nullable();      // Remove unique
    $table->string('model')->nullable();
    $table->string('os_version', 50)->nullable();
    $table->timestamp('last_seen')->nullable();
    $table->integer('port')->default(8728);
    $table->string('username', 100);
    $table->text('password');
    $table->string('location')->nullable();            // ✅ ADD
    $table->string('status', 50)->default('pending');
    $table->string('provisioning_stage', 50)->nullable(); // ✅ ADD
    $table->json('interface_assignments')->default('[]'); // ✅ ADD
    $table->json('configurations')->default('[]');     // ✅ ADD
    $table->string('config_token')->unique()->nullable(); // ✅ VARCHAR, not UUID
    $table->string('vendor', 50)->default('mikrotik');
    $table->string('device_type', 50)->default('router');
    $table->json('capabilities')->default('[]');
    $table->json('interface_list')->default('[]');     // ✅ ADD
    $table->json('reserved_interfaces')->default('{}'); // ✅ ADD
    $table->timestamps();
    $table->softDeletes();
    
    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
    
    $table->index('tenant_id');
    $table->index('vendor');
    $table->index('device_type');
    $table->index('status');
});
```

---

### Fix #2: Update user_sessions Migration

**File:** `backend/database/migrations/2025_06_22_120557_create_user_sessions_table.php`

```php
Schema::create('user_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('user_id')->nullable();               // ✅ Make nullable
    $table->uuid('payment_id')->nullable();
    $table->uuid('package_id')->nullable();
    $table->string('session_token')->unique()->nullable(); // ✅ Make nullable
    $table->string('voucher')->nullable();             // ✅ Already nullable
    $table->string('mac_address', 17)->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->string('status', 20)->default('active');
    $table->timestamp('start_time')->nullable();
    $table->timestamp('end_time')->nullable();
    $table->timestamp('last_activity')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->bigInteger('data_used')->nullable();
    $table->timestamps();
    
    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    
    $table->index('tenant_id');
    $table->index('user_id');
    $table->index('payment_id');
    $table->index('package_id');
    $table->index('session_token');
    $table->index('voucher');
    $table->index('mac_address');
    $table->index('status');
    $table->index('start_time');
    $table->index('end_time');
    $table->index('expires_at');
});
```

---

## 🚀 Recommended Action

### Option 1: Use init.sql ONLY (Recommended)

**Stop using Laravel migrations for initial schema:**

1. **Rename current init.sql:**
   ```bash
   mv postgres/init.sql postgres/init-minimal.sql
   ```

2. **Use init copy 2.sql as main init.sql:**
   ```bash
   cp "postgres/init copy 2.sql" postgres/init.sql
   ```

3. **Update docker-compose.yml:**
   ```yaml
   volumes:
     - ./postgres/init.sql:/docker-entrypoint-initdb.d/01-init.sql
   ```

4. **Reset database:**
   ```bash
   docker-compose down
   docker volume rm traidnet-postgres-data
   docker-compose up -d
   ```

5. **Disable AUTO_MIGRATE in docker-compose.yml:**
   ```yaml
   environment:
     - AUTO_MIGRATE=false  # Don't run migrations
   ```

---

### Option 2: Fix All Migrations (Complex)

Update all migrations to exactly match init copy 2.sql schema. This requires:
- Fixing routers table (8+ columns)
- Fixing user_sessions table (9+ columns)
- Fixing performance_metrics table (ID type)
- Ensuring all JSON defaults match
- Ensuring all constraints match

**Time Required:** 30-60 minutes  
**Risk:** High (easy to miss something)

---

## 📋 Summary of Mismatches

| Table | Mismatches | Severity |
|-------|-----------|----------|
| `routers` | 9 columns | 🔴 Critical |
| `user_sessions` | 9 columns | 🔴 Critical |
| `packages` | 0 columns | ✅ OK |
| `performance_metrics` | 2 columns | 🟡 Medium |
| `hotspot_users` | 0 columns | ✅ OK |
| `hotspot_sessions` | 0 columns | ✅ OK |
| `payments` | 0 columns | ✅ OK |

---

## ✅ Immediate Fix

**Use init copy 2.sql as the source of truth:**

```bash
# 1. Stop containers
docker-compose down

# 2. Backup current init.sql
cp postgres/init.sql postgres/init-backup.sql

# 3. Use init copy 2.sql
cp "postgres/init copy 2.sql" postgres/init.sql

# 4. Remove database volume
docker volume rm traidnet-postgres-data

# 5. Disable auto-migrate
# Edit docker-compose.yml: AUTO_MIGRATE=false

# 6. Start containers
docker-compose up -d
```

**This will use the complete schema from init copy 2.sql and avoid migration conflicts!**

---

**Recommendation:** Use init.sql for schema, disable Laravel migrations for initial setup.
