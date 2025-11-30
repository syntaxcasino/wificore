# Multi-Tenancy Quick Start Guide

## 🚀 Getting Started

This guide will help you quickly set up and use the multi-tenancy features in your WiFi Hotspot Management System.

## Prerequisites

- Existing WiFi Hotspot system installed
- Database access
- Admin credentials

## Installation Steps

### 1. Run Migrations

```bash
cd backend
php artisan migrate
```

This will:
- ✅ Create the `tenants` table
- ✅ Add `tenant_id` to all relevant tables
- ✅ Create a default tenant
- ✅ Migrate existing data to default tenant

### 2. Seed Demo Tenants (Optional)

```bash
php artisan db:seed --class=TenantSeeder
```

This creates two demo tenants for testing.

### 3. Verify Installation

```bash
# Check tenants table
php artisan tinker
>>> Tenant::count()
=> 1  # Default tenant exists

>>> Tenant::first()
=> App\Models\Tenant {
     id: "uuid",
     name: "Default Tenant",
     slug: "default",
     is_active: true,
   }
```

## Basic Usage

### Creating a New Tenant

#### Via API

```bash
POST /api/tenants
Authorization: Bearer {admin_token}
Content-Type: application/json

{
  "name": "My ISP Company",
  "slug": "my-isp",
  "email": "admin@myisp.com",
  "phone": "+254700000000",
  "address": "Nairobi, Kenya",
  "settings": {
    "timezone": "Africa/Nairobi",
    "currency": "KES",
    "max_routers": 10,
    "max_users": 1000
  }
}
```

#### Via Tinker

```bash
php artisan tinker

>>> $tenant = Tenant::create([
...   'name' => 'My ISP Company',
...   'slug' => 'my-isp',
...   'email' => 'admin@myisp.com',
...   'is_active' => true,
... ]);
```

### Creating Users for a Tenant

```bash
>>> $user = User::create([
...   'tenant_id' => $tenant->id,
...   'name' => 'John Doe',
...   'email' => 'john@myisp.com',
...   'password' => bcrypt('password'),
...   'role' => 'admin',
... ]);
```

### Checking Current Tenant

```bash
GET /api/tenant/current
Authorization: Bearer {token}

Response:
{
  "success": true,
  "tenant": {
    "id": "uuid",
    "name": "My ISP Company",
    "slug": "my-isp",
    "is_active": true,
    "settings": {...}
  }
}
```

## Common Operations

### 1. List All Tenants

```bash
GET /api/tenants
Authorization: Bearer {admin_token}

Response:
{
  "success": true,
  "tenants": {
    "data": [
      {
        "id": "uuid",
        "name": "Default Tenant",
        "slug": "default",
        "is_active": true,
        "users_count": 5,
        "routers_count": 3,
        "packages_count": 10
      }
    ]
  }
}
```

### 2. Get Tenant Details

```bash
GET /api/tenants/{tenant_id}
Authorization: Bearer {admin_token}

Response:
{
  "success": true,
  "tenant": {...},
  "stats": {
    "total_users": 50,
    "active_users": 45,
    "total_routers": 5,
    "total_revenue": 150000
  }
}
```

### 3. Update Tenant

```bash
PUT /api/tenants/{tenant_id}
Authorization: Bearer {admin_token}

{
  "name": "Updated ISP Name",
  "settings": {
    "max_routers": 20
  }
}
```

### 4. Suspend Tenant

```bash
POST /api/tenants/{tenant_id}/suspend
Authorization: Bearer {admin_token}

{
  "reason": "Non-payment"
}
```

### 5. Activate Tenant

```bash
POST /api/tenants/{tenant_id}/activate
Authorization: Bearer {admin_token}
```

## Data Isolation Examples

### Automatic Scoping

When a user is authenticated, all queries are automatically scoped to their tenant:

```php
// User from Tenant A logs in
auth()->user()->tenant_id; // => "tenant-a-uuid"

// All queries automatically filtered
Package::all();  // Only Tenant A's packages
Router::all();   // Only Tenant A's routers
Payment::all();  // Only Tenant A's payments
```

### Creating Resources

Resources are automatically assigned to the current tenant:

```php
// User from Tenant A creates a package
$package = Package::create([
    'name' => 'Premium Package',
    'price' => 1000,
    // tenant_id is automatically set to Tenant A
]);

echo $package->tenant_id; // => "tenant-a-uuid"
```

### Cross-Tenant Queries (Admin Only)

```php
// Get all packages across all tenants
$allPackages = Package::withoutTenant()->get();

// Get packages for specific tenant
$tenantPackages = Package::withTenant($tenantId)->get();
```

## Testing Tenant Isolation

### Test Script

```bash
php artisan tinker

# Create two tenants
>>> $tenant1 = Tenant::create(['name' => 'ISP 1', 'slug' => 'isp-1']);
>>> $tenant2 = Tenant::create(['name' => 'ISP 2', 'slug' => 'isp-2']);

# Create users for each tenant
>>> $user1 = User::create([
...   'tenant_id' => $tenant1->id,
...   'name' => 'User 1',
...   'email' => 'user1@isp1.com',
...   'password' => bcrypt('password'),
...   'role' => 'admin',
... ]);

>>> $user2 = User::create([
...   'tenant_id' => $tenant2->id,
...   'name' => 'User 2',
...   'email' => 'user2@isp2.com',
...   'password' => bcrypt('password'),
...   'role' => 'admin',
... ]);

# Create packages for each tenant
>>> Package::create([
...   'tenant_id' => $tenant1->id,
...   'name' => 'ISP 1 Package',
...   'type' => 'hotspot',
...   'price' => 100,
...   'devices' => 1,
...   'speed' => '10M',
...   'upload_speed' => '10M',
...   'download_speed' => '10M',
...   'duration' => '30d',
... ]);

>>> Package::create([
...   'tenant_id' => $tenant2->id,
...   'name' => 'ISP 2 Package',
...   'type' => 'hotspot',
...   'price' => 200,
...   'devices' => 1,
...   'speed' => '20M',
...   'upload_speed' => '20M',
...   'download_speed' => '20M',
...   'duration' => '30d',
... ]);

# Test isolation
>>> auth()->login($user1);
>>> Package::all()->pluck('name');
=> ["ISP 1 Package"]  // Only sees their tenant's package

>>> auth()->login($user2);
>>> Package::all()->pluck('name');
=> ["ISP 2 Package"]  // Only sees their tenant's package
```

## Troubleshooting

### Issue: "Tenant not found"

**Cause**: User doesn't have a valid `tenant_id`

**Solution**:
```bash
php artisan tinker
>>> $user = User::find($userId);
>>> $user->tenant_id = Tenant::first()->id;
>>> $user->save();
```

### Issue: "Tenant account is suspended"

**Cause**: Tenant has been suspended

**Solution**:
```bash
POST /api/tenants/{tenant_id}/activate
```

Or via tinker:
```bash
>>> $tenant = Tenant::find($tenantId);
>>> $tenant->activate();
```

### Issue: Can't see any data after migration

**Cause**: Data might not be assigned to default tenant

**Solution**:
```bash
php artisan tinker
>>> $defaultTenant = Tenant::where('slug', 'default')->first();
>>> User::whereNull('tenant_id')->update(['tenant_id' => $defaultTenant->id]);
>>> Package::whereNull('tenant_id')->update(['tenant_id' => $defaultTenant->id]);
>>> Router::whereNull('tenant_id')->update(['tenant_id' => $defaultTenant->id]);
```

## Best Practices

### 1. Always Use Middleware

```php
Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    // Your routes here
});
```

### 2. Validate Tenant Status

The `tenant.context` middleware automatically validates:
- Tenant exists
- Tenant is active
- Tenant is not suspended

### 3. Use Tenant Settings

Store tenant-specific configuration in the `settings` JSON field:

```php
$tenant->setSetting('max_routers', 10);
$maxRouters = $tenant->getSetting('max_routers', 5); // default: 5
```

### 4. Audit Tenant Access

Log important tenant operations:

```php
SystemLog::create([
    'tenant_id' => auth()->user()->tenant_id,
    'action' => 'tenant.suspended',
    'details' => ['reason' => 'Non-payment'],
]);
```

### 5. Test Isolation

Always test that users can't access other tenants' data:

```bash
php artisan test --filter MultiTenancyTest
```

## Performance Tips

### 1. Cache Tenant Data

```php
$tenant = Cache::remember(
    "tenant_{$tenantId}",
    3600,
    fn() => Tenant::find($tenantId)
);
```

### 2. Eager Load Relationships

```php
$users = User::with('tenant')->get();
```

### 3. Use Indexes

All `tenant_id` columns are automatically indexed for fast filtering.

## Next Steps

1. ✅ Run migrations
2. ✅ Create your first tenant
3. ✅ Create users for the tenant
4. ✅ Test tenant isolation
5. ✅ Configure tenant settings
6. 📖 Read full documentation: `MULTI_TENANCY_IMPLEMENTATION.md`

## Support

- 📖 Full Documentation: `MULTI_TENANCY_IMPLEMENTATION.md`
- 🧪 Run Tests: `php artisan test --filter MultiTenancyTest`
- 💬 Issues: Open a GitHub issue

---

**Quick Start Version**: 1.0.0  
**Last Updated**: 2025-10-28
