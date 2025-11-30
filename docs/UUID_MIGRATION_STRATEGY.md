# UUID Migration Strategy - Complete Implementation Plan

**Date:** 2025-10-10 21:51  
**Status:** 🔍 **ANALYSIS COMPLETE - READY FOR IMPLEMENTATION**  
**Risk Level:** ⚠️ **MEDIUM** (Requires careful execution)

---

## 📊 Current System State

### **Database Analysis:**
- **Total Tables:** 30
- **Tables Requiring UUID:** 17 application tables
- **RADIUS Tables:** 5 (keep as-is for FreeRADIUS compatibility)
- **Laravel System Tables:** 8 (keep as-is)

### **Current Data:**
- **Routers:** 1 (ID: 2, wwe-hsp-01, Status: online)
- **Users:** 2 (admin + 1 user)
- **Packages:** 4
- **Payments:** 0
- **System Status:** ✅ Stable, no active deployments

### **Foreign Key Relationships:**
```
routers (id)
├── wireguard_peers (router_id)
├── router_configs (router_id)
├── router_vpn_configs (router_id)
└── payments (router_id)

users (id)
├── sessions (user_id)
├── payments (user_id)
├── user_subscriptions (user_id)
└── session_disconnections (disconnected_by)

packages (id)
├── payments (package_id)
├── user_subscriptions (package_id)
├── vouchers (package_id)
├── hotspot_users (package_id)
└── radius_sessions (package_id)

payments (id)
├── user_subscriptions (payment_id)
├── vouchers (payment_id)
├── user_sessions (payment_id)
├── radius_sessions (payment_id)
└── hotspot_credentials (payment_id)

hotspot_users (id)
├── hotspot_sessions (hotspot_user_id)
├── radius_sessions (hotspot_user_id)
├── hotspot_credentials (hotspot_user_id)
├── session_disconnections (hotspot_user_id)
└── data_usage_logs (hotspot_user_id)

radius_sessions (id)
├── session_disconnections (radius_session_id)
└── data_usage_logs (radius_session_id)
```

---

## 🎯 Migration Strategy

### **Phase 1: Preparation (Non-Breaking)**
1. Create UUID trait for models
2. Add UUID columns alongside existing ID columns
3. Generate UUIDs for all existing records
4. Update application code to use UUIDs internally

### **Phase 2: Transition (Dual-Key Support)**
1. Update models to support both ID and UUID
2. Update API responses to include both
3. Update frontend to use UUIDs
4. Test all functionality

### **Phase 3: Cleanup (Breaking Changes)**
1. Remove ID columns
2. Rename UUID columns to `id`
3. Update all foreign keys
4. Remove dual-key support code

---

## 📋 Tables to Migrate

### **Application Tables (UUID Required):**

1. ✅ **routers** - Core router management
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: wireguard_peers, router_configs, router_vpn_configs, payments

2. ✅ **users** - User accounts
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: sessions, payments, user_subscriptions, session_disconnections

3. ✅ **packages** - Hotspot packages
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: payments, user_subscriptions, vouchers, hotspot_users, radius_sessions

4. ✅ **payments** - Payment transactions
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: user_subscriptions, vouchers, user_sessions, radius_sessions, hotspot_credentials

5. ✅ **user_subscriptions** - Active subscriptions
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: None

6. ✅ **vouchers** - Voucher codes
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: None

7. ✅ **user_sessions** - User session tracking
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: None

8. ✅ **system_logs** - System logging
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: None

9. ✅ **router_configs** - Router configurations
   - Current: `id SERIAL PRIMARY KEY`
   - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
   - Foreign Keys: None

10. ✅ **router_vpn_configs** - VPN configurations
    - Current: `id SERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: None

11. ✅ **wireguard_peers** - WireGuard peer configurations
    - Current: `id SERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: None

12. ✅ **hotspot_users** - Hotspot user accounts
    - Current: `id BIGSERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: hotspot_sessions, radius_sessions, hotspot_credentials, session_disconnections, data_usage_logs

13. ✅ **hotspot_sessions** - Hotspot session tracking
    - Current: `id BIGSERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: None

14. ✅ **radius_sessions** - RADIUS session tracking
    - Current: `id BIGSERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: session_disconnections, data_usage_logs

15. ✅ **hotspot_credentials** - Credential delivery tracking
    - Current: `id BIGSERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: None

16. ✅ **session_disconnections** - Disconnection audit log
    - Current: `id BIGSERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: None

17. ✅ **data_usage_logs** - Data usage tracking
    - Current: `id BIGSERIAL PRIMARY KEY`
    - Target: `id UUID PRIMARY KEY DEFAULT gen_random_uuid()`
    - Foreign Keys: None

### **Tables to Keep As-Is:**

**RADIUS Tables (FreeRADIUS Compatibility):**
- ❌ radcheck (id SERIAL)
- ❌ radreply (id SERIAL)
- ❌ radacct (radacctid BIGSERIAL)
- ❌ radpostauth (id BIGSERIAL)
- ❌ nas (id SERIAL)

**Laravel System Tables:**
- ❌ personal_access_tokens (id BIGSERIAL)
- ❌ password_reset_tokens (email PRIMARY KEY)
- ❌ sessions (id VARCHAR PRIMARY KEY)
- ❌ jobs (id BIGSERIAL)
- ❌ job_batches (id VARCHAR PRIMARY KEY)
- ❌ failed_jobs (id BIGSERIAL, uuid VARCHAR)

---

## 🔧 Implementation Steps

### **Step 1: Create UUID Trait**

**File:** `backend/app/Traits/HasUuid.php`

```php
<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Boot the UUID trait for the model.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return 'string';
    }
}
```

### **Step 2: Update init.sql**

Add UUID extension and update table definitions:

```sql
-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Example table update
CREATE TABLE routers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    -- ... rest of columns
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### **Step 3: Create Migration Files**

**Migration Order (to handle foreign keys):**
1. Add UUID columns to all tables
2. Generate UUIDs for existing data
3. Update foreign key references
4. Drop old ID columns
5. Rename UUID columns to `id`

### **Step 4: Update Models**

Add `HasUuid` trait to all application models:

```php
<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    use HasUuid;
    
    protected $fillable = [
        // ... existing fields
    ];
    
    protected $casts = [
        'id' => 'string',
        // ... other casts
    ];
}
```

---

## ⚠️ Risk Mitigation

### **Risks:**
1. **Data Loss** - If migration fails mid-process
2. **Foreign Key Violations** - If relationships break
3. **Application Downtime** - During migration
4. **API Breaking Changes** - Existing integrations fail

### **Mitigation Strategies:**

1. **Full Database Backup**
   ```bash
   docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup_pre_uuid.sql
   ```

2. **Test on Development First**
   - Clone database
   - Run migration
   - Test all functionality
   - Verify data integrity

3. **Rollback Plan**
   - Keep backup SQL
   - Document rollback steps
   - Test rollback procedure

4. **Gradual Migration**
   - Phase 1: Add UUID columns (non-breaking)
   - Phase 2: Dual-key support (non-breaking)
   - Phase 3: Remove old IDs (breaking)

---

## 📝 Migration Checklist

### **Pre-Migration:**
- [ ] Full database backup
- [ ] Document current state
- [ ] Test environment setup
- [ ] Rollback plan documented
- [ ] All services stopped

### **Migration:**
- [ ] Enable UUID extensions
- [ ] Add UUID columns
- [ ] Generate UUIDs for existing data
- [ ] Update foreign keys
- [ ] Test data integrity
- [ ] Update application code
- [ ] Test all functionality

### **Post-Migration:**
- [ ] Verify all relationships
- [ ] Test CRUD operations
- [ ] Check API responses
- [ ] Monitor logs for errors
- [ ] Performance testing
- [ ] Update documentation

---

## 🎯 Success Criteria

1. ✅ All tables use UUID primary keys
2. ✅ All foreign key relationships intact
3. ✅ No data loss
4. ✅ All application functionality works
5. ✅ API responses use UUIDs
6. ✅ Performance acceptable
7. ✅ Existing router (ID: 2) accessible via UUID

---

## 📊 Estimated Timeline

- **Preparation:** 2 hours
- **Implementation:** 4 hours
- **Testing:** 2 hours
- **Deployment:** 1 hour
- **Total:** ~9 hours

---

## 🚀 Next Steps

1. Review and approve this strategy
2. Create full database backup
3. Implement UUID trait
4. Update init.sql
5. Create migration files
6. Test in development
7. Deploy to production

---

**Document Prepared By:** Cascade AI  
**Date:** 2025-10-10  
**Status:** Ready for Implementation  
**Approval Required:** YES
