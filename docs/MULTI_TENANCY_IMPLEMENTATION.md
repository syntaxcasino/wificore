# Multi-Tenancy Implementation Guide

## Overview

This WiFi Hotspot Management System has been successfully converted to a **multi-tenancy architecture** without introducing breaking changes. The implementation uses a **single-database, shared-schema** approach with tenant isolation via `tenant_id` columns.

## Architecture

### Strategy: Single Database Multi-Tenancy

- **Database**: Single PostgreSQL database
- **Schema**: Shared schema with `tenant_id` foreign key
- **Isolation**: Row-level security via global scopes
- **Backward Compatibility**: Existing data migrated to default tenant

### Key Components

1. **Tenants Table**: Central tenant management
2. **Tenant Model**: `App\Models\Tenant`
3. **BelongsToTenant Trait**: Automatic tenant scoping
4. **TenantScope**: Global query scope for isolation
5. **SetTenantContext Middleware**: Request-level tenant context
6. **TenantController**: Tenant CRUD operations

## Database Schema Changes

### New Tables

#### `tenants` Table
```sql
- id (UUID, PK)
- name (string)
- slug (string, unique)
- domain (string, nullable, unique)
- email (string, nullable)
- phone (string, nullable)
- address (text, nullable)
- settings (json, nullable)
- is_active (boolean, default: true)
- trial_ends_at (timestamp, nullable)
- suspended_at (timestamp, nullable)
- suspension_reason (string, nullable)
- timestamps
- soft deletes
```

### Modified Tables

All core tables now include:
- `tenant_id` (UUID, FK to tenants.id, indexed)

**Tables with tenant isolation:**
- users
- packages
- routers
- payments
- user_sessions
- vouchers
- hotspot_users
- hotspot_sessions
- router_vpn_configs
- router_services
- access_points
- ap_active_sessions
- service_control_logs
- payment_reminders
- system_logs

## Models Updated

All tenant-scoped models now use the `BelongsToTenant` trait:

```php
use App\Traits\BelongsToTenant;

class Package extends Model
{
    use HasFactory, HasUuid, BelongsToTenant;
    
    protected $fillable = [
        'tenant_id',
        // ... other fields
    ];
}
```

### Models with Tenant Scoping

- ✅ User
- ✅ Package
- ✅ Router
- ✅ Payment
- ✅ HotspotUser
- ✅ Voucher
- ✅ SystemLog
- ✅ RouterService
- ✅ AccessPoint
- ✅ (All related models)

## Automatic Tenant Scoping

### Global Scope Behavior

The `TenantScope` automatically filters queries by the authenticated user's `tenant_id`:

```php
// Automatically scoped to current tenant
$packages = Package::all();

// Explicitly scope to different tenant (admin only)
$packages = Package::withTenant($tenantId)->get();

// Bypass tenant scope (super admin only)
$allPackages = Package::withoutTenant()->get();
```

### Automatic tenant_id Assignment

When creating records, `tenant_id` is automatically set:

```php
// tenant_id automatically set from auth()->user()->tenant_id
$package = Package::create([
    'name' => 'Premium Package',
    'price' => 1000,
    // tenant_id is auto-assigned
]);
```

## Middleware

### SetTenantContext Middleware

Validates tenant status on every authenticated request:

```php
// Applied to all authenticated routes
Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    // Routes here
});
```

**Checks:**
- Tenant exists
- Tenant is active
- Tenant is not suspended

## API Endpoints

### Tenant Management (Admin Only)

```http
GET    /api/tenants              # List all tenants
POST   /api/tenants              # Create tenant
GET    /api/tenants/{tenant}     # Get tenant details
PUT    /api/tenants/{tenant}     # Update tenant
DELETE /api/tenants/{tenant}     # Delete tenant (soft delete)
POST   /api/tenants/{tenant}/suspend   # Suspend tenant
POST   /api/tenants/{tenant}/activate  # Activate tenant
```

### Current Tenant Info

```http
GET    /api/tenant/current       # Get current user's tenant
```

### Example Requests

#### Create Tenant
```bash
POST /api/tenants
{
  "name": "New ISP",
  "slug": "new-isp",
  "email": "admin@newisp.com",
  "phone": "+254700000000",
  "settings": {
    "timezone": "Africa/Nairobi",
    "currency": "KES",
    "max_routers": 10
  }
}
```

#### Get Current Tenant
```bash
GET /api/tenant/current
Authorization: Bearer {token}

Response:
{
  "success": true,
  "tenant": {
    "id": "uuid",
    "name": "Default Tenant",
    "slug": "default",
    "is_active": true,
    ...
  }
}
```

## Migration Guide

### Running Migrations

```bash
# Run migrations (creates tenants table and adds tenant_id columns)
php artisan migrate

# Seed demo tenants (optional)
php artisan db:seed --class=TenantSeeder
```

### Migration Process

1. **Creates `tenants` table** with default tenant
2. **Adds `tenant_id` column** to all tenant-scoped tables
3. **Migrates existing data** to default tenant
4. **Adds foreign key constraints** for referential integrity

### Rollback

```bash
# Rollback tenant migrations
php artisan migrate:rollback --step=2
```

## User Management

### User-Tenant Relationship

Each user belongs to exactly one tenant:

```php
$user->tenant_id;  // UUID of tenant
$user->tenant;     // Tenant model instance
```

### Creating Users for Tenants

```php
// Admin creates user for specific tenant
User::create([
    'tenant_id' => $tenantId,
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'role' => 'admin',
]);

// Or let it auto-assign from current user
User::create([
    // tenant_id auto-assigned from auth()->user()->tenant_id
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
]);
```

## Tenant Settings

Tenants can store custom settings in JSON:

```php
// Set setting
$tenant->setSetting('max_routers', 20);

// Get setting
$maxRouters = $tenant->getSetting('max_routers', 10); // default: 10

// Bulk settings
$tenant->update([
    'settings' => [
        'timezone' => 'Africa/Nairobi',
        'currency' => 'KES',
        'max_routers' => 10,
        'max_users' => 1000,
        'features' => [
            'vpn' => true,
            'analytics' => true,
        ],
    ],
]);
```

## Security Considerations

### Data Isolation

- ✅ **Automatic scoping** via global scopes
- ✅ **Foreign key constraints** prevent orphaned records
- ✅ **Middleware validation** checks tenant status
- ✅ **Soft deletes** for tenant removal

### Access Control

- **Admin users** can only access their tenant's data
- **Super admin** can bypass tenant scope (use carefully)
- **Suspended tenants** cannot access the system

### Best Practices

1. **Never bypass tenant scope** in regular operations
2. **Always validate tenant_id** when accepting user input
3. **Use middleware** on all authenticated routes
4. **Audit tenant access** for compliance

## Testing

### Test Tenant Isolation

```php
// Create test tenants
$tenant1 = Tenant::factory()->create();
$tenant2 = Tenant::factory()->create();

// Create users for each tenant
$user1 = User::factory()->create(['tenant_id' => $tenant1->id]);
$user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

// Test isolation
$this->actingAs($user1);
$packages = Package::all(); // Only tenant1's packages

$this->actingAs($user2);
$packages = Package::all(); // Only tenant2's packages
```

## Performance Optimization

### Indexes

All `tenant_id` columns are indexed for fast filtering:

```sql
CREATE INDEX idx_packages_tenant_id ON packages(tenant_id);
CREATE INDEX idx_routers_tenant_id ON routers(tenant_id);
-- etc.
```

### Caching

Cache keys should include tenant_id:

```php
$cacheKey = "packages_list_tenant_{$tenantId}";
Cache::remember($cacheKey, 600, function () {
    return Package::all();
});
```

### Query Optimization

```php
// Eager load tenant relationship
$users = User::with('tenant')->get();

// Count by tenant
$stats = Package::selectRaw('tenant_id, COUNT(*) as count')
    ->groupBy('tenant_id')
    ->get();
```

## Backward Compatibility

### Existing Data

All existing data is automatically assigned to the **default tenant**:
- Tenant ID: Auto-generated UUID
- Slug: `default`
- Name: `Default Tenant`

### No Breaking Changes

- ✅ All existing API endpoints work unchanged
- ✅ Existing authentication flows preserved
- ✅ Current user sessions remain valid
- ✅ All relationships maintained

### Migration Path

1. **Before migration**: Single-tenant system
2. **After migration**: Multi-tenant with default tenant
3. **Gradual adoption**: Create new tenants as needed
4. **Data migration**: Move users/data to new tenants if required

## Troubleshooting

### Common Issues

#### 1. Tenant Not Found
```
Error: Tenant not found
Solution: Ensure user has valid tenant_id
```

#### 2. Tenant Suspended
```
Error: Tenant account is suspended or inactive
Solution: Activate tenant via admin panel
```

#### 3. Cross-Tenant Access
```
Error: Cannot access resource from different tenant
Solution: Verify tenant_id matches authenticated user
```

### Debug Mode

```php
// Disable tenant scope temporarily (development only)
Package::withoutTenantScope()->get();

// Check current tenant
auth()->user()->tenant;

// Verify tenant status
$tenant = auth()->user()->tenant;
dd($tenant->isActive(), $tenant->isSuspended());
```

## Future Enhancements

### Planned Features

1. **Tenant Domains**: Custom domain mapping
2. **Tenant Themes**: Custom branding per tenant
3. **Resource Limits**: Enforce tenant quotas
4. **Tenant Analytics**: Usage statistics per tenant
5. **Tenant Billing**: Subscription management
6. **Multi-Database**: Option for database-per-tenant

### Scalability

- **Horizontal scaling**: Add read replicas
- **Caching layer**: Redis for tenant data
- **CDN integration**: Static assets per tenant
- **Load balancing**: Distribute tenant load

## Support

For issues or questions:
1. Check this documentation
2. Review migration logs
3. Test in development environment first
4. Contact system administrator

---

**Version**: 1.0.0  
**Last Updated**: 2025-10-28  
**Status**: Production Ready ✅
