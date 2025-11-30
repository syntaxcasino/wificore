# Multi-Tenancy Implementation Plan for WiFi Hotspot System

## Executive Summary

**Current State**: The system uses a **shared-table multi-tenancy** approach with `tenant_id` column filtering.

**Target State**: Implement **schema-based multi-tenancy** as per documentation (PART1-4) for complete data isolation.

**Impact**: This is a **major architectural change** but will be implemented incrementally to avoid breaking changes.

---

## Current Architecture Analysis

### ✅ What Exists
1. **Tenant Model** (`app/Models/Tenant.php`)
   - Basic tenant management
   - Missing: `schema_name`, `schema_created`, `schema_created_at` fields

2. **SetTenantContext Middleware** (`app/Http/Middleware/SetTenantContext.php`)
   - Basic tenant validation
   - Missing: PostgreSQL `search_path` management

3. **TenantAwareService** (`app/Services/TenantAwareService.php`)
   - Manual `tenant_id` filtering
   - Good validation methods but wrong approach

4. **FreeRADIUS Dictionary** (`freeradius/dictionary`)
   - Exists but missing `Tenant-ID` attribute

5. **All Models Use tenant_id Column**
   - Routers, Packages, Payments, HotspotUsers, etc.
   - All in public schema

### ❌ What's Missing
1. **TenantContext Service** - Core service for managing tenant context
2. **TenantSchemaManager Service** - Schema lifecycle management
3. **Schema-based database structure** - Tenant schemas don't exist
4. **radius_user_schema_mapping table** - Critical for RADIUS multi-tenancy
5. **Tenant migrations directory** - `database/migrations/tenant/`
6. **Multitenancy config file** - `config/multitenancy.php`
7. **Tenant RADIUS tables** - radcheck/radreply per tenant schema

---

## Implementation Strategy

### Phase 1: Foundation (No Breaking Changes)
**Goal**: Add schema-based infrastructure alongside existing structure

#### 1.1 Add Missing Fields to Tenants Table
```php
// Migration: add_schema_fields_to_tenants_table.php
$table->string('schema_name')->unique()->nullable();
$table->boolean('schema_created')->default(false);
$table->timestamp('schema_created_at')->nullable();
```

#### 1.2 Create TenantContext Service
```php
// app/Services/TenantContext.php
- setTenant()
- getTenant()
- clearTenant()
- setSearchPath()
- runInTenantContext()
```

#### 1.3 Create TenantSchemaManager Service
```php
// app/Services/TenantSchemaManager.php
- createSchema()
- dropSchema()
- runMigrations()
- schemaExists()
```

#### 1.4 Create Multitenancy Config
```php
// config/multitenancy.php
- mode: 'hybrid' (supports both approaches)
- system_tables: [tenants, users, subscriptions, payments]
- tenant_tables: [routers, packages, hotspot_users, vouchers]
```

#### 1.5 Update FreeRADIUS Dictionary
```
ATTRIBUTE	Tenant-ID		3100	string
```

#### 1.6 Create radius_user_schema_mapping Table
```sql
CREATE TABLE radius_user_schema_mapping (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    schema_name VARCHAR(64) NOT NULL,
    tenant_id UUID REFERENCES tenants(id),
    user_role VARCHAR(32),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Phase 2: Tenant Schema Structure (Backward Compatible)
**Goal**: Create tenant schemas for new tenants, keep existing data in public

#### 2.1 Create Tenant Migrations Directory
```
database/migrations/tenant/
├── 2025_01_01_000001_create_tenant_routers_table.php
├── 2025_01_01_000002_create_tenant_packages_table.php
├── 2025_01_01_000003_create_tenant_hotspot_users_table.php
├── 2025_01_01_000004_create_tenant_vouchers_table.php
├── 2025_01_01_000005_create_tenant_payments_table.php
├── 2025_01_01_000006_create_tenant_radius_tables.php
└── ...
```

#### 2.2 Update SetTenantContext Middleware
```php
// Enhanced to set PostgreSQL search_path
if ($tenant && $tenant->schema_created) {
    DB::statement("SET search_path TO {$tenant->schema_name}, public");
}
```

#### 2.3 Create Artisan Commands
```bash
php artisan tenant:create {tenant_id}
php artisan tenant:migrate {tenant_id}
php artisan tenant:migrate --all
php artisan tenant:seed {tenant_id}
```

### Phase 3: Data Migration (Controlled Rollout)
**Goal**: Migrate existing tenants to schema-based approach

#### 3.1 Migration Script
```php
// app/Console/Commands/MigrateTenantToSchema.php
- Create tenant schema
- Copy data from public to tenant schema
- Update tenant record (schema_created = true)
- Verify data integrity
- Keep public data as backup
```

#### 3.2 Hybrid Mode Support
```php
// Models check if tenant uses schema or public
if ($tenant->schema_created) {
    // Use schema-based queries
} else {
    // Use tenant_id filtering (legacy)
}
```

### Phase 4: RADIUS Integration
**Goal**: Implement schema-based RADIUS authentication

#### 4.1 Update RADIUS SQL Queries
```sql
-- queries.conf: Add schema lookup function
CREATE OR REPLACE FUNCTION get_tenant_schema(username_param VARCHAR)
RETURNS VARCHAR AS $$
...
```

#### 4.2 Update User Creation
```php
// When creating users, add to:
1. public.users (system-wide)
2. tenant_schema.radcheck (credentials)
3. tenant_schema.radreply (Tenant-ID attribute)
4. public.radius_user_schema_mapping (schema lookup)
```

#### 4.3 Update RadiusService
```php
// app/Services/RadiusService.php
- Extract Tenant-ID from RADIUS response
- Use for tenant context setting
```

### Phase 5: Model Updates
**Goal**: Make models schema-aware

#### 5.1 Update Base Model
```php
// app/Models/TenantAwareModel.php
trait TenantAware {
    protected static function bootTenantAware() {
        // Auto-scope queries to tenant schema
    }
}
```

#### 5.2 Update WiFi-Specific Models
```php
// Router, Package, HotspotUser, Voucher, Payment
- Add TenantAware trait
- Remove manual tenant_id scoping
- Let search_path handle isolation
```

### Phase 6: Testing & Validation
**Goal**: Ensure no breaking changes

#### 6.1 Test Scenarios
- [ ] Existing tenants continue working (public schema)
- [ ] New tenants use schema-based approach
- [ ] RADIUS authentication works for both
- [ ] Data isolation verified
- [ ] No cross-tenant data leakage
- [ ] WiFi features work (router provisioning, vouchers, etc.)

#### 6.2 Rollback Plan
- Keep public schema data intact
- Can revert to tenant_id filtering
- Schema creation is additive, not destructive

---

## Implementation Checklist

### Week 1: Foundation
- [ ] Add schema fields to tenants table
- [ ] Create TenantContext service
- [ ] Create TenantSchemaManager service
- [ ] Create multitenancy config
- [ ] Update FreeRADIUS dictionary
- [ ] Create radius_user_schema_mapping table
- [ ] Update docker-compose.yml (dictionary mount)

### Week 2: Schema Structure
- [ ] Create tenant migrations directory
- [ ] Write tenant table migrations
- [ ] Update SetTenantContext middleware
- [ ] Create tenant management commands
- [ ] Test schema creation

### Week 3: RADIUS Integration
- [ ] Update RADIUS SQL queries
- [ ] Implement schema lookup function
- [ ] Update user creation flow
- [ ] Update RadiusService
- [ ] Test RADIUS authentication

### Week 4: Model Updates
- [ ] Create TenantAware trait
- [ ] Update Router model
- [ ] Update Package model
- [ ] Update HotspotUser model
- [ ] Update Voucher model
- [ ] Update Payment model

### Week 5: Testing
- [ ] Unit tests for TenantContext
- [ ] Feature tests for tenant isolation
- [ ] RADIUS authentication tests
- [ ] WiFi feature tests
- [ ] Performance benchmarks

### Week 6: Documentation & Deployment
- [ ] Update API documentation
- [ ] Create migration guide
- [ ] Deploy to staging
- [ ] Migrate test tenant
- [ ] Production deployment plan

---

## Risk Mitigation

### Risk 1: Data Loss
**Mitigation**: 
- Keep public schema data intact
- Copy, don't move data
- Extensive backups before migration

### Risk 2: Breaking Changes
**Mitigation**:
- Hybrid mode support
- Gradual rollout
- Feature flags for schema-based vs legacy

### Risk 3: RADIUS Authentication Failure
**Mitigation**:
- Test extensively in dev
- Fallback to public schema RADIUS
- Monitor authentication logs

### Risk 4: Performance Degradation
**Mitigation**:
- Benchmark before/after
- Optimize indexes
- Connection pooling
- Cache tenant objects

---

## Success Criteria

1. ✅ All new tenants use schema-based approach
2. ✅ Existing tenants continue working without interruption
3. ✅ RADIUS authentication works for all tenants
4. ✅ Complete data isolation verified
5. ✅ No performance degradation
6. ✅ All WiFi features functional
7. ✅ Zero data loss
8. ✅ Comprehensive test coverage

---

## Next Steps

1. **Review this plan** with team
2. **Create feature branch**: `feature/schema-based-multitenancy`
3. **Start with Phase 1**: Foundation components
4. **Test incrementally**: Each phase independently
5. **Document changes**: Update system documentation
6. **Deploy gradually**: Staging → Test tenant → Production

---

**Document Version**: 1.0  
**Created**: November 30, 2025  
**Status**: PENDING APPROVAL
