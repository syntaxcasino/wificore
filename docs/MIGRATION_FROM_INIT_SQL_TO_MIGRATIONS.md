# Migration from init.sql to Laravel Migrations

**Date**: Oct 28, 2025  
**Status**: ✅ **COMPLETE**  
**Change**: Removed init.sql dependency, using Laravel migrations

---

## 🎯 **WHAT CHANGED**

### **Before** ❌
```yaml
# docker-compose.yml
volumes:
  - postgres_data:/var/lib/postgresql/data
  - ./postgres/init.sql:/docker-entrypoint-initdb.d/01-init.sql
  - ./postgres/radius-schema.sql:/docker-entrypoint-initdb.d/02-radius-schema.sql
```

**Problems**:
- ❌ init.sql runs only on first container creation
- ❌ No version control of schema changes
- ❌ No rollback capability
- ❌ Hard to maintain
- ❌ Doesn't integrate with Laravel
- ❌ Team members can't easily update schema

### **After** ✅
```yaml
# docker-compose.yml
volumes:
  - postgres_data:/var/lib/postgresql/data
  # init.sql removed - using Laravel migrations instead
  # - ./postgres/init.sql:/docker-entrypoint-initdb.d/01-init.sql
  - ./postgres/radius-schema.sql:/docker-entrypoint-initdb.d/01-radius-schema.sql
```

**Benefits**:
- ✅ Migrations run automatically on container start
- ✅ Version controlled schema changes
- ✅ Rollback capability
- ✅ Easy to maintain
- ✅ Laravel native
- ✅ Team friendly

---

## 📋 **CHANGES MADE**

### **1. docker-compose.yml** ✅
- ✅ Removed `init.sql` mount
- ✅ Kept `radius-schema.sql` (required for FreeRADIUS)
- ✅ Added auto-migration environment variables:
  - `AUTO_MIGRATE=true`
  - `AUTO_SEED=true`
  - `FRESH_INSTALL=false`
  - `APP_ENV=development`

### **2. backend/docker/entrypoint.sh** ✅
- ✅ Added automatic migration logic
- ✅ Waits for database to be ready
- ✅ Runs migrations on container start
- ✅ Runs seeders based on environment
- ✅ Optimizes application (cache, routes, views)

### **3. Seeders Created** ✅
- ✅ `DefaultTenantSeeder.php` - Creates default tenant
- ✅ `DefaultSystemAdminSeeder.php` - Creates system admin
- ✅ `DemoDataSeeder.php` - Creates demo data (dev/staging only)

### **4. Deployment Scripts** ✅
- ✅ `deploy.sh` - Linux/Mac deployment
- ✅ `deploy.ps1` - Windows deployment

---

## 🔄 **HOW IT WORKS NOW**

### **Container Startup Flow**

```
1. Docker starts postgres container
   ↓
2. Postgres runs radius-schema.sql (FreeRADIUS tables)
   ↓
3. Docker starts backend container
   ↓
4. entrypoint.sh detects AUTO_MIGRATE=true
   ↓
5. Waits for database to be ready
   ↓
6. Runs: php artisan migrate --force
   ↓
7. Runs: php artisan db:seed --force
   ↓
8. Optimizes application (cache, routes, views)
   ↓
9. Starts PHP-FPM and services
```

---

## 📊 **WHAT GETS CREATED**

### **By radius-schema.sql** (PostgreSQL init)
- ✅ RADIUS tables (radcheck, radreply, radacct, etc.)
- ✅ NAS table
- ✅ Required for FreeRADIUS authentication

### **By Laravel Migrations** (Automatic)
- ✅ tenants table
- ✅ users table (with tenant_id)
- ✅ packages table (with tenant_id)
- ✅ payments table (with tenant_id)
- ✅ routers table (with tenant_id)
- ✅ vouchers table (with tenant_id)
- ✅ hotspot_users table (with tenant_id)
- ✅ hotspot_sessions table (with tenant_id)
- ✅ user_sessions table (with tenant_id)
- ✅ router_services table (with tenant_id)
- ✅ access_points table (with tenant_id)
- ✅ ap_active_sessions table (with tenant_id)
- ✅ router_vpn_configs table (with tenant_id)
- ✅ system_logs table (with tenant_id)
- ✅ All other application tables

### **By Seeders** (Automatic)
- ✅ Default tenant (slug: default)
- ✅ System admin (sysadmin@system.local)
- ✅ Demo data (development/staging only)

---

## 🚀 **USAGE**

### **Fresh Start**

```bash
# Remove all data and start fresh
docker-compose down -v
docker-compose up -d

# Database will be:
# 1. Created by postgres
# 2. RADIUS tables created by radius-schema.sql
# 3. Application tables created by migrations
# 4. Initial data created by seeders
```

### **Update Schema**

```bash
# Create new migration
cd backend
php artisan make:migration add_new_field_to_users

# Edit migration file
# database/migrations/YYYY_MM_DD_HHMMSS_add_new_field_to_users.php

# Restart backend to apply
docker-compose restart traidnet-backend

# Or run manually
docker-compose exec traidnet-backend php artisan migrate
```

### **Add New Data**

```bash
# Create new seeder
php artisan make:seeder NewDataSeeder

# Edit seeder file
# database/seeders/NewDataSeeder.php

# Run seeder
docker-compose exec traidnet-backend php artisan db:seed --class=NewDataSeeder
```

---

## ⚠️ **IMPORTANT NOTES**

### **Why Keep radius-schema.sql?**

FreeRADIUS requires specific table structures that are:
- ✅ Defined by FreeRADIUS project
- ✅ Expected to be in specific format
- ✅ Not managed by Laravel
- ✅ Separate from application tables

**Decision**: Keep radius-schema.sql for FreeRADIUS, use migrations for everything else.

### **What Happens to init.sql?**

- ✅ **Kept in repository** for reference
- ✅ **Not mounted** in docker-compose.yml
- ✅ **Not used** by Docker
- ✅ **Updated** to match migrations (for documentation)

**Purpose**: Documentation and reference only.

---

## 🔧 **CONFIGURATION**

### **Enable/Disable Auto-Migration**

```yaml
# docker-compose.yml
environment:
  - AUTO_MIGRATE=true   # Set to false to disable
  - AUTO_SEED=true      # Set to false to skip seeders
  - FRESH_INSTALL=false # Set to true to drop all tables
  - APP_ENV=development # production, staging, development
```

### **Production Configuration**

```yaml
# docker-compose.yml (production)
environment:
  - AUTO_MIGRATE=true
  - AUTO_SEED=true
  - FRESH_INSTALL=false
  - APP_ENV=production  # Skips demo data
```

---

## 📋 **MIGRATION CHECKLIST**

### **Completed** ✅
- [x] Removed init.sql from docker-compose.yml
- [x] Kept radius-schema.sql for FreeRADIUS
- [x] Added auto-migration to entrypoint.sh
- [x] Created all necessary seeders
- [x] Updated docker-compose.yml with environment variables
- [x] Created deployment scripts
- [x] Tested fresh installation
- [x] Documented changes

### **Verification**
- [x] Container starts successfully
- [x] Database is created
- [x] RADIUS tables exist
- [x] Application tables exist
- [x] Default tenant created
- [x] System admin created
- [x] Demo data created (dev/staging)

---

## 🎯 **BENEFITS**

### **For Developers**
- ✅ Easy schema updates
- ✅ Version controlled changes
- ✅ Rollback capability
- ✅ Team collaboration
- ✅ Consistent environments

### **For Operations**
- ✅ Automatic deployment
- ✅ No manual SQL scripts
- ✅ Environment-specific data
- ✅ Easy rollback
- ✅ Monitoring and logging

### **For the Project**
- ✅ Industry best practices
- ✅ Maintainable codebase
- ✅ Scalable architecture
- ✅ CI/CD ready
- ✅ Production ready

---

## 🆚 **COMPARISON**

| Aspect | init.sql (Old) | Migrations (New) |
|--------|----------------|------------------|
| **Runs** | Only on first start | Every container start |
| **Updates** | Manual SQL editing | `php artisan make:migration` |
| **Version Control** | ❌ No | ✅ Yes |
| **Rollback** | ❌ No | ✅ Yes |
| **Team Sync** | ❌ Hard | ✅ Easy |
| **Environment-Specific** | ❌ No | ✅ Yes |
| **Maintenance** | ❌ Hard | ✅ Easy |

---

## 🎉 **RESULT**

**Status**: ✅ **MIGRATION COMPLETE**

**Changes**:
- ✅ init.sql removed from Docker
- ✅ radius-schema.sql kept for FreeRADIUS
- ✅ Auto-migration enabled
- ✅ Seeders configured
- ✅ Deployment scripts ready

**Benefits**:
- ✅ Following Laravel best practices
- ✅ Version controlled schema
- ✅ Automatic deployment
- ✅ Team friendly
- ✅ Production ready

---

**Recommendation**: ✅ **Keep using migrations**  
**Status**: ✅ **Fully Implemented**  
**Production Ready**: ✅ **YES**

**The system now uses industry-standard database migration practices!** 🚀🔒
