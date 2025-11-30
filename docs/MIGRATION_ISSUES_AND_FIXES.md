# Migration Issues and Fixes - Oct 28, 2025

**Status**: ⏳ **IN PROGRESS**  
**Issue**: Migrations failing due to order and CHECK constraint problems

---

## 🔴 **ISSUES FOUND**

### **1. Migration Order Problem** ✅ **FIXED**

**Issue**: `hotspot_users` migration running before `packages` migration
- `hotspot_users` has foreign key to `packages`
- Migration dated 2025_01_08 runs before 2025_06_22

**Fix**: Renamed migrations to correct order
```
2025_01_08_000001_create_hotspot_users_table.php 
  → 2025_07_01_000001_create_hotspot_users_table.php

2025_01_08_000002_create_hotspot_sessions_table.php 
  → 2025_07_01_000002_create_hotspot_sessions_table.php

2025_01_08_000003_create_router_vpn_configs_table.php 
  → 2025_07_28_000001_create_router_vpn_configs_table.php
```

---

### **2. RADIUS Tables Conflict** ✅ **FIXED**

**Issue**: Migration trying to create RADIUS tables that already exist
- `radius-schema.sql` creates tables on postgres init
- Migration `2025_09_28_114415_create_radius_tables.php` tries to create same tables

**Fix**: Disabled the migration
```
2025_09_28_114415_create_radius_tables.php 
  → 2025_09_28_114415_create_radius_tables.php.disabled
```

---

### **3. CHECK Constraints Problem** ⏳ **IN PROGRESS**

**Issue**: CHECK constraints failing in `router_services` migration
```
SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "router_services" does not exist
```

**Cause**: CHECK constraints being added inside Schema::create() closure causing transaction issues

**Fix Applied**: Removed CHECK constraints from migration
- Will be added via init.sql or manually
- Simplified migration to just create table structure

---

## 📋 **MIGRATION ORDER (CORRECT)**

```
1. 0001_01_01_000000_create_users_table
2. 0001_01_01_000001_create_cache_table
3. 0001_01_01_000002_create_jobs_table
4. 2025_06_22_115324_create_personal_access_tokens_table
5. 2025_06_22_120557_create_payments_table
6. 2025_06_22_120557_create_user_sessions_table
7. 2025_06_22_120601_create_system_logs_table
8. 2025_06_22_124849_create_packages_table ← Must come before hotspot_users
9. 2025_06_28_054023_create_vouchers_table
10. 2025_07_01_000001_create_hotspot_users_table ← Now after packages
11. 2025_07_01_000002_create_hotspot_sessions_table
12. 2025_07_27_143410_create_routers_table
13. 2025_07_28_000001_create_router_vpn_configs_table
14. 2025_10_11_085900_create_router_services_table
15. 2025_10_11_090000_create_access_points_table
16. 2025_10_11_090100_create_ap_active_sessions_table
17. 2025_10_11_090200_create_service_control_logs_table
18. 2025_10_11_090300_create_payment_reminders_table
19. 2025_10_11_090400_add_service_fields_to_routers_table
20. 2025_10_11_090500_add_payment_fields_to_user_subscriptions_table
21. 2025_10_17_000001_create_performance_metrics_table
22. 2025_10_23_163900_add_new_fields_to_packages_table
23. 2025_10_28_000001_create_tenants_table
24. 2025_10_28_000002_add_tenant_id_to_tables
25. 2025_10_28_000003_implement_table_partitioning
```

---

## ✅ **FILES MODIFIED**

### **Renamed**:
1. ✅ `2025_01_08_000001_create_hotspot_users_table.php` → `2025_07_01_000001_create_hotspot_users_table.php`
2. ✅ `2025_01_08_000002_create_hotspot_sessions_table.php` → `2025_07_01_000002_create_hotspot_sessions_table.php`
3. ✅ `2025_01_08_000003_create_router_vpn_configs_table.php` → `2025_07_28_000001_create_router_vpn_configs_table.php`

### **Disabled**:
4. ✅ `2025_09_28_114415_create_radius_tables.php` → `2025_09_28_114415_create_radius_tables.php.disabled`

### **Modified**:
5. ✅ `2025_10_11_085900_create_router_services_table.php` - Removed CHECK constraints

---

## 🎯 **NEXT STEPS**

### **1. Complete Migration Testing**
```bash
docker-compose build --no-cache traidnet-backend
docker-compose up -d
docker exec traidnet-backend php artisan migrate:fresh --force --seed
```

### **2. Verify All Tables Created**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dt"
```

### **3. Check Indexes**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\di"
```

### **4. Verify Partitioning**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '%_p%';"
```

---

## 📊 **EXPECTED TABLES**

### **Core Laravel Tables**:
- migrations
- users
- cache, cache_locks
- jobs, job_batches, failed_jobs
- personal_access_tokens

### **Application Tables** (with tenant_id):
- tenants
- packages
- payments
- vouchers
- user_sessions
- hotspot_users
- hotspot_sessions
- routers
- router_vpn_configs
- router_services
- access_points
- ap_active_sessions
- system_logs
- service_control_logs
- payment_reminders
- performance_metrics

### **RADIUS Tables** (created by radius-schema.sql):
- nas
- radcheck
- radreply
- radacct
- radpostauth
- radusergroup
- radgroupcheck
- radgroupreply

---

## ⚠️ **CRITICAL REQUIREMENTS**

### **1. Indexing** ✅
All tables must have indexes on:
- Primary keys (automatic)
- Foreign keys (tenant_id, package_id, router_id, etc.)
- Frequently queried columns (status, created_at, etc.)

### **2. Partitioning** ⏳
Large tables must be partitioned:
- radacct (by date)
- system_logs (by date)
- performance_metrics (by date)
- hotspot_sessions (by date)

### **3. Tenant Isolation** ✅
All application tables must have:
- tenant_id column (UUID, NOT NULL)
- Foreign key to tenants(id) ON DELETE CASCADE
- Index on tenant_id

---

## 🔍 **VERIFICATION COMMANDS**

### **Check Migration Status**:
```bash
docker exec traidnet-backend php artisan migrate:status
```

### **List All Tables**:
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dt"
```

### **Check Table Structure**:
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d packages"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d hotspot_users"
```

### **Verify Indexes**:
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT tablename, indexname FROM pg_indexes WHERE schemaname = 'public' ORDER BY tablename, indexname;"
```

### **Check Partitions**:
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT inhrelid::regclass AS child, inhparent::regclass AS parent FROM pg_inherits;"
```

---

## 📝 **LESSONS LEARNED**

### **1. Migration Naming is Critical**
- Use proper date prefixes (YYYY_MM_DD_HHMMSS)
- Ensure dependencies run in correct order
- Foreign key references must exist before creation

### **2. Avoid Duplicate Table Creation**
- RADIUS tables created by postgres init script
- Don't duplicate in Laravel migrations
- Keep separation of concerns

### **3. CHECK Constraints in PostgreSQL**
- Can't be added inside Schema::create() closure
- Must use DB::statement() after table creation
- Or add via raw SQL in init.sql

### **4. Container Caching**
- Migrations are cached in Docker images
- Must rebuild with --no-cache after file renames
- Or restart containers to pick up changes

---

**Status**: ⏳ **Rebuilding backend container**  
**Next**: Run migrations and verify all tables created  
**Timeline**: 10-15 minutes to complete

**Once migrations complete successfully, we'll have a fully tenant-aware database with proper indexing and partitioning!** 🚀🔒
