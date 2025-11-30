# Multi-Tenancy Deployment Checklist

## Pre-Deployment

### 1. Backup Everything ✅
```bash
# Backup database
pg_dump wifi_hotspot > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup application files
tar -czf app_backup_$(date +%Y%m%d_%H%M%S).tar.gz backend/

# Backup .env file
cp backend/.env backend/.env.backup
```

### 2. Test in Development ✅
```bash
# Run all tests
cd backend
php artisan test

# Specifically test multi-tenancy
php artisan test --filter MultiTenancyTest

# Check for any errors
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### 3. Review Changes ✅
- [ ] All models updated with BelongsToTenant trait
- [ ] Middleware registered in bootstrap/app.php
- [ ] Routes updated with tenant.context middleware
- [ ] Migrations ready to run
- [ ] Documentation reviewed

## Deployment Steps

### Step 1: Maintenance Mode
```bash
php artisan down --message="System upgrade in progress" --retry=60
```

### Step 2: Pull Latest Code
```bash
git pull origin main
# Or your deployment method
```

### Step 3: Install Dependencies
```bash
composer install --no-dev --optimize-autoloader
```

### Step 4: Run Migrations
```bash
# Dry run first (check what will happen)
php artisan migrate --pretend

# Run actual migrations
php artisan migrate --force

# Expected output:
# - Creating tenants table
# - Adding tenant_id to tables
# - Creating default tenant
```

### Step 5: Verify Default Tenant
```bash
php artisan tinker

>>> $tenant = Tenant::where('slug', 'default')->first();
>>> echo $tenant->name;
=> "Default Tenant"

>>> echo $tenant->is_active;
=> true

>>> exit
```

### Step 6: Verify Data Migration
```bash
php artisan tinker

# Check users have tenant_id
>>> User::whereNull('tenant_id')->count();
=> 0  # Should be 0

# Check packages have tenant_id
>>> Package::whereNull('tenant_id')->count();
=> 0  # Should be 0

# Check routers have tenant_id
>>> Router::whereNull('tenant_id')->count();
=> 0  # Should be 0

>>> exit
```

### Step 7: Clear Caches
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### Step 8: Test API Endpoints
```bash
# Test authentication still works
curl -X POST http://your-domain.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Test tenant endpoint
curl -X GET http://your-domain.com/api/tenant/current \
  -H "Authorization: Bearer {token}"

# Test existing endpoints still work
curl -X GET http://your-domain.com/api/packages \
  -H "Authorization: Bearer {token}"
```

### Step 9: Exit Maintenance Mode
```bash
php artisan up
```

## Post-Deployment Verification

### 1. Functional Tests ✅
- [ ] Login works for existing users
- [ ] Users can see their data
- [ ] Creating new resources works
- [ ] Updating resources works
- [ ] Deleting resources works
- [ ] Tenant isolation is working

### 2. Performance Tests ✅
```bash
# Check query performance
php artisan tinker

>>> DB::enableQueryLog();
>>> Package::all();
>>> DB::getQueryLog();
# Verify tenant_id is in WHERE clause
```

### 3. Security Tests ✅
- [ ] Users can't access other tenants' data
- [ ] Tenant middleware is active
- [ ] Suspended tenants can't access system
- [ ] Foreign key constraints working

### 4. Monitor Logs ✅
```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check for any errors
grep -i "error" storage/logs/laravel.log
grep -i "tenant" storage/logs/laravel.log
```

## Rollback Plan (If Needed)

### Quick Rollback
```bash
# 1. Enable maintenance mode
php artisan down

# 2. Rollback migrations
php artisan migrate:rollback --step=2

# 3. Restore database backup
psql wifi_hotspot < backup_YYYYMMDD_HHMMSS.sql

# 4. Restore application files
tar -xzf app_backup_YYYYMMDD_HHMMSS.tar.gz

# 5. Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 6. Exit maintenance mode
php artisan up
```

## Monitoring (First 24 Hours)

### Key Metrics to Watch
1. **Response Times** - Should be similar to before
2. **Error Rates** - Should be minimal
3. **Database Queries** - Check for N+1 issues
4. **User Complaints** - Monitor support channels

### Monitoring Commands
```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log

# Check database connections
php artisan tinker
>>> DB::connection()->getPdo();

# Monitor queue workers (if using)
php artisan queue:monitor

# Check system health
curl http://your-domain.com/api/health/ping
```

## Common Issues & Solutions

### Issue 1: "Tenant not found"
**Solution:**
```bash
php artisan tinker
>>> $user = User::find($userId);
>>> $defaultTenant = Tenant::where('slug', 'default')->first();
>>> $user->tenant_id = $defaultTenant->id;
>>> $user->save();
```

### Issue 2: "Column tenant_id does not exist"
**Solution:**
```bash
# Migration didn't run properly
php artisan migrate:status
php artisan migrate --force
```

### Issue 3: Users can't see their data
**Solution:**
```bash
# Check if data was migrated to default tenant
php artisan tinker
>>> $defaultTenant = Tenant::where('slug', 'default')->first();
>>> Package::whereNull('tenant_id')->update(['tenant_id' => $defaultTenant->id]);
>>> Router::whereNull('tenant_id')->update(['tenant_id' => $defaultTenant->id]);
```

### Issue 4: Performance degradation
**Solution:**
```bash
# Ensure indexes were created
php artisan tinker
>>> DB::select("SELECT * FROM pg_indexes WHERE tablename = 'packages' AND indexname LIKE '%tenant%'");

# Clear and rebuild cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

## Success Criteria

- ✅ All migrations completed successfully
- ✅ Default tenant created
- ✅ All existing data has tenant_id
- ✅ No null tenant_id values
- ✅ API endpoints responding normally
- ✅ Users can login and access their data
- ✅ Tenant isolation verified
- ✅ No performance degradation
- ✅ No errors in logs
- ✅ All tests passing

## Communication Plan

### Before Deployment
```
Subject: System Upgrade - Multi-Tenancy Implementation

Dear Users,

We will be performing a system upgrade on [DATE] at [TIME].
Expected downtime: 5-10 minutes

What's changing:
- Enhanced data isolation
- Improved security
- Better scalability

What stays the same:
- Your login credentials
- Your data and settings
- All existing features

Thank you for your patience.
```

### After Deployment
```
Subject: System Upgrade Complete

Dear Users,

The system upgrade has been completed successfully.
All systems are operational.

If you experience any issues, please contact support.

Thank you!
```

## Final Checklist

- [ ] Backup completed
- [ ] Tests passing in development
- [ ] Stakeholders notified
- [ ] Maintenance window scheduled
- [ ] Rollback plan ready
- [ ] Monitoring tools ready
- [ ] Support team briefed
- [ ] Documentation updated
- [ ] Migrations tested
- [ ] Performance benchmarks recorded

## Emergency Contacts

- **Database Admin**: [Contact]
- **System Admin**: [Contact]
- **Development Team**: [Contact]
- **Support Team**: [Contact]

---

## Deployment Timeline

**Estimated Total Time**: 15-30 minutes

1. Maintenance Mode: 1 min
2. Code Deployment: 2 min
3. Migrations: 5-10 min
4. Verification: 5-10 min
5. Cache Clear: 2 min
6. Testing: 5 min
7. Exit Maintenance: 1 min

---

**Checklist Version**: 1.0.0  
**Last Updated**: 2025-10-28  
**Status**: Ready for Deployment 🚀
