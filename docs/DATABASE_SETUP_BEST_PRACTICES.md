# Database Setup - Best Practices Implementation

**Date**: Oct 28, 2025  
**Status**: ✅ **IMPLEMENTED**  
**Approach**: **Migrations + Seeders** (Industry Standard)

---

## 🎯 **DECISION: Use Migrations + Seeders**

### **Why This is Better**

✅ **Version Control**
- All database changes tracked in Git
- Easy to see what changed and when
- Team collaboration friendly

✅ **Rollback Capability**
- Can undo migrations if needed
- Safe deployment process
- Easy to fix mistakes

✅ **Framework Native**
- Laravel best practice
- Automatic in CI/CD
- Well-documented

✅ **Environment-Specific**
- Different seeds for dev/staging/production
- Conditional demo data
- Flexible configuration

✅ **Database Agnostic**
- Works with PostgreSQL, MySQL, SQLite
- Easy to switch databases
- Portable across environments

---

## 📁 **FILES CREATED**

### **1. Seeders** (3 files)

#### **DefaultTenantSeeder.php**
- Creates default tenant for initial setup
- Required for system to function
- Runs in all environments

#### **DefaultSystemAdminSeeder.php**
- Creates system administrator account
- Username: `sysadmin@system.local`
- Password: `Admin@123!` (change immediately!)
- Cannot be deleted

#### **DemoDataSeeder.php**
- Creates demo tenants (Tenant A, Tenant B)
- Creates demo admin users
- Creates demo packages and routers
- **Only runs in development/staging**
- Skipped in production

### **2. Deployment Scripts** (2 files)

#### **deploy.sh** (Linux/Mac)
- Waits for database to be ready
- Runs migrations automatically
- Runs seeders based on environment
- Optimizes application (cache, routes, views)
- Sets proper permissions

#### **deploy.ps1** (Windows)
- Same functionality as deploy.sh
- PowerShell version for Windows servers

### **3. Docker Integration**

#### **entrypoint.sh** (Updated)
- Automatic migration on container start
- Controlled by environment variables:
  - `AUTO_MIGRATE=true` - Run migrations
  - `AUTO_SEED=true` - Run seeders
  - `FRESH_INSTALL=true` - Drop all tables and recreate
  - `APP_ENV=development` - Environment detection

#### **docker-compose.yml** (Updated)
- Added auto-migration variables
- Configured for development by default
- Easy to change for production

---

## 🚀 **USAGE**

### **Development (Docker)**

```bash
# Start with automatic migrations and seeders
docker-compose up -d

# The backend will automatically:
# 1. Wait for database
# 2. Run migrations
# 3. Run seeders (including demo data)
# 4. Optimize application
# 5. Start services
```

### **Production (Manual)**

```bash
cd backend

# Run deployment script
chmod +x deploy.sh
./deploy.sh

# Or on Windows
.\deploy.ps1 -Environment production
```

### **Fresh Install**

```bash
# Docker
docker-compose down -v  # Remove volumes
docker-compose up -d    # Recreate everything

# Or set in docker-compose.yml:
# - FRESH_INSTALL=true

# Manual
cd backend
php artisan migrate:fresh --seed
```

---

## 🔧 **CONFIGURATION**

### **Environment Variables**

```env
# In .env or docker-compose.yml

# Enable automatic migrations
AUTO_MIGRATE=true

# Enable automatic seeders
AUTO_SEED=true

# Fresh install (drops all tables)
FRESH_INSTALL=false

# Environment (affects which seeders run)
APP_ENV=development  # or production, staging
```

### **Seeder Behavior by Environment**

| Seeder | Development | Staging | Production |
|--------|-------------|---------|------------|
| DefaultTenantSeeder | ✅ Runs | ✅ Runs | ✅ Runs |
| DefaultSystemAdminSeeder | ✅ Runs | ✅ Runs | ✅ Runs |
| DemoDataSeeder | ✅ Runs | ✅ Runs | ❌ Skipped |

---

## 📊 **WHAT GETS CREATED**

### **In All Environments**

1. **Default Tenant**
   - Name: Default Tenant
   - Slug: default
   - Email: admin@default-tenant.local

2. **System Administrator**
   - Username: sysadmin
   - Email: sysadmin@system.local
   - Password: Admin@123!
   - Role: system_admin
   - Cannot be deleted

### **In Development/Staging Only**

3. **Tenant A**
   - Admin: admin-a@tenant-a.com / Password123!
   - 3 packages (Basic, Standard, Premium)
   - 1 router (192.168.1.1)

4. **Tenant B**
   - Admin: admin-b@tenant-b.com / Password123!
   - 3 packages (Basic, Standard, Premium)
   - 1 router (192.168.2.1)

---

## 🔄 **MIGRATION WORKFLOW**

### **Creating New Migrations**

```bash
# Create a new migration
php artisan make:migration create_new_table

# Edit the migration file
# database/migrations/YYYY_MM_DD_HHMMSS_create_new_table.php

# Run the migration
php artisan migrate

# Rollback if needed
php artisan migrate:rollback

# Rollback all migrations
php artisan migrate:reset

# Rollback and re-run all
php artisan migrate:refresh
```

### **Creating New Seeders**

```bash
# Create a new seeder
php artisan make:seeder NewDataSeeder

# Edit the seeder file
# database/seeders/NewDataSeeder.php

# Add to DatabaseSeeder.php
$this->call([
    NewDataSeeder::class,
]);

# Run the seeder
php artisan db:seed --class=NewDataSeeder

# Or run all seeders
php artisan db:seed
```

---

## 🎯 **BEST PRACTICES**

### **✅ DO**

1. **Always use migrations** for schema changes
2. **Use seeders** for initial/demo data
3. **Version control** all migrations and seeders
4. **Test migrations** before deploying
5. **Use transactions** in seeders for safety
6. **Document** what each migration does
7. **Keep migrations small** and focused

### **❌ DON'T**

1. **Don't edit** existing migrations after deployment
2. **Don't use** init.sql for schema management
3. **Don't hardcode** production data in seeders
4. **Don't skip** migration testing
5. **Don't delete** migrations that have run
6. **Don't use** `migrate:fresh` in production

---

## 🔒 **SECURITY**

### **Default Passwords**

⚠️ **CRITICAL**: Change all default passwords immediately!

```bash
# System Admin
Username: sysadmin@system.local
Password: Admin@123!  # CHANGE THIS!

# Demo Accounts (dev/staging only)
Tenant A: admin-a@tenant-a.com / Password123!
Tenant B: admin-b@tenant-b.com / Password123!
```

### **Production Deployment**

```bash
# Set environment to production
APP_ENV=production

# This will:
# - Run migrations
# - Create system admin
# - Create default tenant
# - Skip demo data
# - Optimize for production
```

---

## 📋 **DEPLOYMENT CHECKLIST**

### **Before Deployment**

- [ ] All migrations tested locally
- [ ] Seeders tested in staging
- [ ] Default passwords documented
- [ ] Backup strategy in place
- [ ] Rollback plan ready

### **During Deployment**

- [ ] Run `deploy.sh` or `deploy.ps1`
- [ ] Verify migrations completed
- [ ] Verify seeders completed
- [ ] Test system admin login
- [ ] Test tenant creation

### **After Deployment**

- [ ] Change default system admin password
- [ ] Create first real tenant
- [ ] Test tenant isolation
- [ ] Monitor application logs
- [ ] Verify all services running

---

## 🆚 **Comparison: Migrations vs init.sql**

| Feature | Migrations + Seeders | init.sql |
|---------|---------------------|----------|
| Version Control | ✅ Yes | ❌ No |
| Rollback | ✅ Yes | ❌ No |
| Team Collaboration | ✅ Easy | ❌ Hard |
| Framework Integration | ✅ Native | ❌ Manual |
| Environment-Specific | ✅ Yes | ❌ No |
| Database Agnostic | ✅ Yes | ❌ No |
| CI/CD Integration | ✅ Automatic | ❌ Manual |
| Maintenance | ✅ Easy | ❌ Hard |
| Initial Setup Speed | ⚠️ Slower | ✅ Faster |
| **Recommendation** | ✅ **USE THIS** | ❌ Avoid |

---

## 🎉 **RESULT**

**Status**: ✅ **COMPLETE**

**Implementation**:
- ✅ Migrations for all tables
- ✅ Seeders for initial data
- ✅ Automatic deployment scripts
- ✅ Docker integration
- ✅ Environment-specific behavior
- ✅ Production-ready

**Benefits**:
- ✅ Industry best practices
- ✅ Version controlled
- ✅ Rollback capability
- ✅ Team friendly
- ✅ CI/CD ready
- ✅ Maintainable

---

**Recommendation**: ✅ **Use Migrations + Seeders**  
**Status**: ✅ **Fully Implemented**  
**Production Ready**: ✅ **YES**

**The system now follows Laravel and industry best practices for database management!** 🚀🔒
