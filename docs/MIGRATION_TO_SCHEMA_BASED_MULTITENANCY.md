# Migration Guide: Schema-Based Multi-Tenancy

**Date**: December 2, 2025  
**Status**: ⚠️ **REQUIRES MIGRATION**

---

## 🎯 **What's Changing?**

We're implementing **proper schema-based multi-tenancy** where each tenant has their own PostgreSQL schema containing:

- ✅ RADIUS tables (`radcheck`, `radreply`, `radacct`, `radpostauth`)
- ✅ Tenant-specific data (packages, routers, subscriptions, etc.)
- ✅ Complete data isolation from other tenants

**Before**: All RADIUS users in `public.radcheck`  
**After**: System admins in `public.radcheck`, tenant users in `tenant_schema.radcheck`

---

## ⚠️ **Prerequisites**

Before running the migration:

1. ✅ **Backup your database**
   ```bash
   docker exec traidnet-postgres pg_dump -U admin wifi_hotspot > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. ✅ **Stop all services** (optional, but recommended)
   ```bash
   docker-compose down
   ```

3. ✅ **Verify all tenants have schemas**
   ```bash
   docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
       SELECT id, name, schema_name, schema_created 
       FROM tenants;
   "
   ```

---

## 🚀 **Migration Steps**

### **Step 1: Run Migrations**

```bash
cd backend
php artisan migrate
```

**What this does**:
1. Creates RADIUS tables in each tenant schema
2. Migrates existing RADIUS data to tenant schemas
3. Cleans up public schema (keeps only system admin entries)
4. Creates PostgreSQL functions for schema-aware queries

**Expected output**:
```
Migrating: 2025_12_02_000001_implement_schema_based_multitenancy_for_radius
Migrated:  2025_12_02_000001_implement_schema_based_multitenancy_for_radius (1.23s)
Migrating: 2025_12_02_000002_create_radius_schema_switching_function
Migrated:  2025_12_02_000002_create_radius_schema_switching_function (0.45s)
```

---

### **Step 2: Verify RADIUS Tables**

Check if RADIUS tables exist in tenant schemas:

```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT 
        schemaname, 
        tablename 
    FROM pg_tables 
    WHERE tablename IN ('radcheck', 'radreply', 'radacct', 'radpostauth')
    ORDER BY schemaname, tablename;
"
```

**Expected output**:
```
   schemaname    |  tablename   
-----------------+--------------
 public          | radcheck
 public          | radreply
 public          | radacct
 public          | radpostauth
 tenant_abc      | radcheck
 tenant_abc      | radreply
 tenant_abc      | radacct
 tenant_abc      | radpostauth
 tenant_xyz      | radcheck
 tenant_xyz      | radreply
 tenant_xyz      | radacct
 tenant_xyz      | radpostauth
```

---

### **Step 3: Verify Data Migration**

Check if RADIUS data was migrated correctly:

```bash
# Check public schema (should only have system admins)
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT username, attribute, value 
    FROM public.radcheck 
    ORDER BY username;
"

# Check tenant schema (should have tenant users)
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SET search_path TO tenant_abc, public;
    SELECT username, attribute, value 
    FROM radcheck 
    ORDER BY username;
"
```

---

### **Step 4: Verify PostgreSQL Functions**

Check if schema-switching functions were created:

```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT proname, proargnames 
    FROM pg_proc 
    WHERE proname IN ('get_user_schema', 'radius_check_password', 'radius_get_reply', 'radius_accounting_start')
    ORDER BY proname;
"
```

**Expected output**:
```
         proname          |        proargnames        
--------------------------+---------------------------
 get_user_schema          | {p_username}
 radius_accounting_start  | {p_username,p_session_id,...}
 radius_check_password    | {p_username,p_password}
 radius_get_reply         | {p_username}
```

---

### **Step 5: Test Authentication**

Test RADIUS authentication for both system admins and tenant users:

```bash
# Test system admin (public schema)
radtest sysadmin Admin@123 localhost 0 testing123

# Test tenant user (tenant schema)
radtest xuxu Pa$$w0rd! localhost 0 testing123
```

**Expected output**:
```
Sent Access-Request Id 123 from 0.0.0.0:12345 to 127.0.0.1:1812 length 77
Received Access-Accept Id 123 from 127.0.0.1:1812 to 0.0.0.0:12345 length 20
```

---

### **Step 6: Restart Services**

```bash
docker-compose up -d
```

---

## 🔍 **Verification Checklist**

- [ ] All tenant schemas have RADIUS tables
- [ ] System admin RADIUS entries in public schema
- [ ] Tenant user RADIUS entries in tenant schemas
- [ ] PostgreSQL functions created successfully
- [ ] RADIUS authentication works for system admins
- [ ] RADIUS authentication works for tenant users
- [ ] No RADIUS entries for tenant users in public schema

---

## 🛠️ **Troubleshooting**

### **Issue: Migration fails with "schema does not exist"**

**Cause**: Tenant schema not created  
**Solution**:
```bash
# Check which tenants don't have schemas
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT id, name, schema_name, schema_created 
    FROM tenants 
    WHERE schema_created = false OR schema_name IS NULL;
"

# Create missing schemas manually
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    CREATE SCHEMA IF NOT EXISTS tenant_abc;
    UPDATE tenants SET schema_created = true WHERE schema_name = 'tenant_abc';
"
```

---

### **Issue: RADIUS authentication fails after migration**

**Cause**: User not found in correct schema  
**Solution**:
```bash
# Check which schema the user should be in
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SELECT get_user_schema('username');
"

# Verify RADIUS entry exists
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    SET search_path TO tenant_abc, public;
    SELECT * FROM radcheck WHERE username = 'username';
"
```

---

### **Issue: Duplicate RADIUS entries**

**Cause**: User exists in both public and tenant schema  
**Solution**:
```bash
# Remove from public schema (if not system admin)
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
    DELETE FROM public.radcheck 
    WHERE username = 'username' 
    AND username NOT IN (
        SELECT username FROM users WHERE role = 'system_admin'
    );
"
```

---

## 🔄 **Rollback (If Needed)**

If something goes wrong, you can rollback:

```bash
cd backend
php artisan migrate:rollback --step=2
```

**This will**:
- Drop RADIUS tables from tenant schemas
- Drop PostgreSQL functions
- **NOT restore data** (use backup for that)

**To restore from backup**:
```bash
docker exec -i traidnet-postgres psql -U admin wifi_hotspot < backup_YYYYMMDD_HHMMSS.sql
```

---

## 📊 **Performance Impact**

### **Before Migration**
- All RADIUS queries hit `public.radcheck`
- No tenant isolation
- Single table for all users

### **After Migration**
- RADIUS queries hit tenant-specific tables
- Complete tenant isolation
- Smaller tables per tenant = faster queries
- Schema switching overhead: **< 1ms**

---

## 🎉 **Benefits**

✅ **Complete Data Isolation** - Each tenant's RADIUS data is physically separated  
✅ **Better Performance** - Smaller tables per tenant  
✅ **Improved Security** - No cross-tenant data access  
✅ **Compliance Ready** - Meets data residency requirements  
✅ **Scalability** - Easy to add new tenants  

---

## 📝 **Post-Migration Tasks**

1. ✅ Update FreeRADIUS configuration (if needed)
2. ✅ Test authentication for all tenants
3. ✅ Verify accounting data is being logged correctly
4. ✅ Update monitoring and alerting
5. ✅ Train team on new architecture

---

## 📚 **Additional Resources**

- [Schema-Based Multi-Tenancy Documentation](./SCHEMA_BASED_MULTITENANCY.md)
- [PostgreSQL Schemas](https://www.postgresql.org/docs/current/ddl-schemas.html)
- [FreeRADIUS SQL Module](https://wiki.freeradius.org/modules/Rlm_sql)

---

## 🆘 **Need Help?**

If you encounter any issues during migration:

1. Check the logs: `docker logs traidnet-backend`
2. Check PostgreSQL logs: `docker logs traidnet-postgres`
3. Review the migration code in `backend/database/migrations/`
4. Contact the development team

---

**Status**: Ready to migrate! 🚀
