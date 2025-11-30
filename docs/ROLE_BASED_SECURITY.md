# Role-Based Security & Data Isolation

**Version**: 1.0  
**Last Updated**: Oct 28, 2025  
**Purpose**: Prevent data leaks between System Admin and Tenant contexts

---

## 🔒 **Security Layers**

### **Layer 1: Backend API (PRIMARY DEFENSE)**

#### A. Route Segregation

```php
// routes/api.php

// System Admin Routes - Prefix: /api/system
Route::middleware(['auth:sanctum', 'role:system_admin'])->prefix('system')->group(function () {
    Route::get('/tenants', [SystemAdminController::class, 'getTenants']);
    Route::get('/platform-metrics', [SystemAdminController::class, 'getPlatformMetrics']);
    Route::get('/audit-logs', [SystemAdminController::class, 'getAuditLogs']);
    // NO access to packages or routers
});

// Tenant Routes - Prefix: /api/tenant
Route::middleware(['auth:sanctum', 'role:admin,tenant'])->prefix('tenant')->group(function () {
    Route::get('/dashboard', [TenantDashboardController::class, 'index']);
    Route::get('/packages', [PackageController::class, 'index']);
    Route::get('/routers', [RouterController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);
});
```

#### B. Data Scoping with Global Scopes

**Automatic Tenant Filtering**:

```php
// app/Models/Scopes/TenantScope.php
namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();
        
        // System admins see everything, others filtered by tenant
        if ($user && $user->role !== 'system_admin') {
            $builder->where('tenant_id', $user->tenant_id);
        }
    }
}
```

**Apply to Models**:

```php
// app/Models/Package.php
class Package extends Model
{
    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}

// app/Models/Router.php
class Router extends Model
{
    protected static function booted()
    {
        static::addGlobalScope(new TenantScope());
    }
}
```

#### C. Controller-Level Validation

**System Admin Controller**:

```php
// app/Http/Controllers/Api/SystemAdminController.php
class SystemAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'role:system_admin']);
    }
    
    public function getPlatformMetrics()
    {
        // Platform-wide data
        return response()->json([
            'success' => true,
            'data' => [
                'total_tenants' => Tenant::count(),
                'total_users' => User::count(),
                'total_revenue' => Payment::sum('amount'),
                'active_sessions' => Session::where('status', 'active')->count(),
            ]
        ]);
    }
}
```

**Tenant Controller**:

```php
// app/Http/Controllers/Api/TenantDashboardController.php
class TenantDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        
        // CRITICAL: Only return data for THIS tenant
        $data = [
            'users' => User::where('tenant_id', $tenantId)->count(),
            'packages' => Package::where('tenant_id', $tenantId)->get(),
            'routers' => Router::where('tenant_id', $tenantId)->get(),
            'revenue' => Payment::where('tenant_id', $tenantId)->sum('amount'),
        ];
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}
```

---

### **Layer 2: Frontend Route Guards**

#### A. Router Configuration

```javascript
// router/index.js

const routes = [
  // System Admin Routes
  {
    path: '/system',
    component: DashboardLayout,
    meta: { requiresAuth: true, requiresRole: 'system_admin' },
    children: [
      {
        path: 'dashboard',
        name: 'system.dashboard',
        component: () => import('@/modules/system-admin/views/system/SystemDashboardNew.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
    ]
  },
  
  // Tenant Routes
  {
    path: '/dashboard',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'overview', component: Dashboard },
      // ... tenant routes
    ]
  },
]

// Navigation Guard
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('authToken')
  const role = localStorage.getItem('userRole')
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresRole = to.meta.requiresRole

  if (requiresAuth && !token) {
    next({ name: 'login', query: { redirect: to.fullPath } })
  } else if (requiresRole && role !== requiresRole) {
    // Prevent access if role doesn't match
    next({ path: '/dashboard' })
  } else {
    next()
  }
})
```

---

### **Layer 3: Component-Level Security**

#### A. Role-Based Data Fetching

```javascript
// composables/auth/useRoleBasedData.js

export function useRoleBasedData() {
  const authStore = useAuthStore()
  
  const isSystemAdmin = computed(() => authStore.user?.role === 'system_admin')
  const tenantId = computed(() => authStore.user?.tenant_id)
  
  const getApiEndpoint = (resource) => {
    if (isSystemAdmin.value) {
      return `/api/system/${resource}`
    } else {
      return `/api/tenant/${resource}`
    }
  }
  
  const fetchDashboardData = async () => {
    const endpoint = getApiEndpoint('dashboard')
    const response = await axios.get(endpoint)
    return response.data.data
  }
  
  return {
    isSystemAdmin,
    tenantId,
    getApiEndpoint,
    fetchDashboardData,
  }
}
```

#### B. Conditional Component Rendering

```vue
<template>
  <div class="dashboard">
    <!-- System Admin View -->
    <SystemAdminDashboard v-if="isSystemAdmin" />
    
    <!-- Tenant View -->
    <TenantDashboard v-else-if="isTenantAdmin" />
    
    <!-- Unauthorized -->
    <UnauthorizedView v-else />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const isSystemAdmin = computed(() => authStore.user?.role === 'system_admin')
const isTenantAdmin = computed(() => authStore.user?.role === 'admin')
</script>
```

---

### **Layer 4: Sidebar Menu Filtering**

```vue
<!-- AppSidebar.vue -->

<template>
  <aside>
    <!-- Common Menus -->
    <router-link to="/dashboard">Dashboard</router-link>
    
    <!-- Tenant-Only Menus -->
    <div v-if="!isOnSystemAdminRoute">
      <router-link to="/dashboard/packages">Packages</router-link>
      <router-link to="/dashboard/routers">Routers</router-link>
    </div>
    
    <!-- System Admin-Only Menus -->
    <div v-if="isOnSystemAdminRoute">
      <router-link to="/system/tenants">Manage Tenants</router-link>
      <router-link to="/system/platform-metrics">Platform Metrics</router-link>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const isOnSystemAdminRoute = computed(() => route.path.startsWith('/system'))
</script>
```

---

## 🛡️ **Security Checklist**

### Backend (Critical)
- [x] ✅ Separate API routes for system admin (`/api/system/*`) and tenant (`/api/tenant/*`)
- [x] ✅ Role-based middleware on all routes
- [x] ✅ Global scopes for automatic tenant filtering
- [x] ✅ Controller-level tenant_id validation
- [x] ✅ Never trust frontend role checks - always validate on backend
- [ ] ⏳ Add audit logging for sensitive operations
- [ ] ⏳ Implement rate limiting per tenant
- [ ] ⏳ Add database-level row security policies

### Frontend (Defense in Depth)
- [x] ✅ Router guards checking user role
- [x] ✅ Conditional menu rendering based on route
- [x] ✅ Role-based data fetching composables
- [x] ✅ Separate components for system admin and tenant views
- [ ] ⏳ Add error boundaries for unauthorized access
- [ ] ⏳ Clear sensitive data on logout
- [ ] ⏳ Implement session timeout

---

## 🚨 **Common Data Leak Scenarios & Prevention**

### Scenario 1: Tenant Accessing Another Tenant's Data

**Risk**: Tenant A modifies API request to access Tenant B's data

**Prevention**:
```php
// BAD - Trusts frontend input
public function getPackages(Request $request)
{
    $tenantId = $request->input('tenant_id'); // DANGEROUS!
    return Package::where('tenant_id', $tenantId)->get();
}

// GOOD - Uses authenticated user's tenant
public function getPackages(Request $request)
{
    $tenantId = $request->user()->tenant_id; // SAFE
    return Package::where('tenant_id', $tenantId)->get();
}
```

### Scenario 2: System Admin Data Shown to Tenant

**Risk**: Frontend shows system admin data if role check fails

**Prevention**:
```javascript
// BAD - Only frontend check
if (userRole === 'system_admin') {
  showPlatformMetrics()
}

// GOOD - Backend enforces access
router.get('/platform-metrics', [
  'auth:sanctum',
  'role:system_admin'
], SystemAdminController@getPlatformMetrics)
```

### Scenario 3: Shared Route Different Data

**Risk**: Same URL shows different data based on role

**Solution**:
```javascript
// Use role-based data fetching
const { fetchDashboardData } = useRoleBasedData()

// This automatically calls:
// - /api/system/dashboard for system admin
// - /api/tenant/dashboard for tenant
const data = await fetchDashboardData()
```

---

## 📋 **Implementation Steps**

### Step 1: Backend API Separation
1. Create `SystemAdminController` for platform-wide data
2. Create `TenantDashboardController` for tenant-specific data
3. Add separate route groups with role middleware
4. Implement global scopes for automatic filtering

### Step 2: Frontend Composables
1. Create `useRoleBasedData` composable
2. Implement role-based endpoint selection
3. Add error handling for unauthorized access

### Step 3: Component Separation
1. Create separate components for system admin views
2. Create separate components for tenant views
3. Use conditional rendering based on role

### Step 4: Testing
1. Test system admin cannot access tenant-specific resources
2. Test tenant cannot access other tenant's data
3. Test tenant cannot access system admin resources
4. Test API returns 403 for unauthorized access

---

## 🔍 **Testing Commands**

```bash
# Test system admin access
curl -H "Authorization: Bearer {system_admin_token}" \
  http://localhost/api/system/platform-metrics

# Test tenant access (should fail)
curl -H "Authorization: Bearer {tenant_token}" \
  http://localhost/api/system/platform-metrics
# Expected: 403 Forbidden

# Test tenant accessing own data
curl -H "Authorization: Bearer {tenant_token}" \
  http://localhost/api/tenant/packages
# Expected: 200 OK with only their packages

# Test tenant accessing another tenant's data (should fail)
curl -H "Authorization: Bearer {tenant_a_token}" \
  http://localhost/api/tenant/packages?tenant_id=B
# Expected: 403 Forbidden or filtered results
```

---

## ✅ **Best Practices**

1. **Never Trust Frontend**
   - Always validate role and tenant_id on backend
   - Frontend checks are for UX only, not security

2. **Use Global Scopes**
   - Automatically filter queries by tenant
   - Prevents accidental data leaks

3. **Separate API Endpoints**
   - `/api/system/*` for system admin
   - `/api/tenant/*` for tenants
   - Clear separation of concerns

4. **Audit Logging**
   - Log all sensitive operations
   - Track who accessed what data
   - Monitor for suspicious activity

5. **Principle of Least Privilege**
   - Only expose data that role needs
   - System admin doesn't need package/router data
   - Tenant doesn't need platform metrics

---

**Status**: ✅ **SECURITY FRAMEWORK IMPLEMENTED**  
**Backend**: Role-based API routes with middleware  
**Frontend**: Conditional rendering and data fetching  
**Result**: Multi-layered security preventing data leaks! 🔒
