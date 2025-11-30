# Package-to-Router Assignment System - Implementation Complete

**Date:** October 30, 2025, 5:30 PM  
**Status:** ✅ **BACKEND COMPLETE - FRONTEND IN PROGRESS**

---

## 📋 Overview

Implemented a comprehensive system allowing tenants to:
1. **Assign packages to specific routers** or make them **global** (available to all routers)
2. **Track revenue per router** to identify which routers generate more income
3. **Filter packages for hotspot users** based on their connected router

---

## ✅ Backend Implementation (COMPLETED)

### **1. Database Schema**

#### **Migration: `2025_10_30_142300_add_router_assignment_to_packages.php`**

**Added to `packages` table:**
```sql
- is_global (boolean, default: true) - Whether package is available to all routers
- Index on is_global for faster queries
```

**Created `package_router` pivot table:**
```sql
- id (UUID, primary key)
- package_id (UUID, foreign key to packages)
- router_id (UUID, foreign key to routers)
- tenant_id (UUID, for data integrity)
- timestamps
- Unique constraint on (package_id, router_id)
```

**Added to `payments` table:**
```sql
- router_id (UUID, nullable, foreign key to routers)
- Index on router_id for revenue queries
```

---

### **2. Models Updated**

#### **Package Model** (`app/Models/Package.php`)

**Added:**
- `is_global` to fillable and casts
- `routers()` relationship (belongsToMany)
- `isAvailableForRouter($routerId)` - Check if package is available for a router
- `getRevenue($routerId = null)` - Get revenue for this package (optionally filtered by router)

```php
public function routers()
{
    return $this->belongsToMany(Router::class, 'package_router')
        ->withTimestamps();
}

public function isAvailableForRouter($routerId): bool
{
    if ($this->is_global) {
        return true;
    }
    return $this->routers()->where('router_id', $routerId)->exists();
}
```

#### **Router Model** (`app/Models/Router.php`)

**Added:**
- `packages()` relationship (belongsToMany)
- `getTotalRevenue()` - Get total revenue for this router
- `getRevenueByPackage()` - Get revenue breakdown by package

```php
public function packages()
{
    return $this->belongsToMany(Package::class, 'package_router')
        ->withTimestamps();
}

public function getTotalRevenue()
{
    return $this->payments()->where('status', 'completed')->sum('amount');
}
```

#### **Payment Model** (`app/Models/Payment.php`)

**Already had:**
- `router_id` in fillable
- `router()` relationship

---

### **3. Controllers Updated**

#### **PackageController** (`app/Http/Controllers/Api/PackageController.php`)

**Changes:**

**index()** - Load router relationships:
```php
return Package::where('tenant_id', $tenantId)
    ->with(['routers:id,name'])
    ->orderBy('created_at', 'desc')
    ->get();
```

**store()** - Handle router assignments:
```php
// Validation
'is_global' => 'boolean',
'router_ids' => 'nullable|array',
'router_ids.*' => 'exists:routers,id',

// Create package
$package = Package::create([
    // ... other fields
    'is_global' => $request->is_global ?? true,
]);

// Attach routers if not global
if (!$package->is_global && $request->has('router_ids')) {
    $package->routers()->attach($request->router_ids, [
        'tenant_id' => $tenantId,
        'created_at' => now(),
        'updated_at' => now()
    ]);
}
```

**update()** - Sync router assignments:
```php
if ($request->has('router_ids')) {
    if ($package->is_global) {
        $package->routers()->detach(); // Remove all assignments
    } else {
        $package->routers()->sync($syncData); // Sync assignments
    }
}
```

#### **PublicPackageController** (`app/Http/Controllers/Api/PublicPackageController.php`)

**Changes:**

**getPublicPackages()** - Filter by router:
```php
private function identifyRouter(Request $request)
{
    // Detect router from IP, headers, or query params
    // Returns router_id or null
}

// In getPublicPackages():
if ($routerId) {
    $query->where(function($q) use ($routerId) {
        $q->where('is_global', true)
          ->orWhereHas('routers', function($rq) use ($routerId) {
              $rq->where('router_id', $routerId);
          });
    });
} else {
    $query->where('is_global', true);
}
```

**Returns:**
- Global packages (is_global = true)
- Packages specifically assigned to the detected router
- Only if router is detected, otherwise only global packages

#### **PaymentController** (`app/Http/Controllers/Api/PaymentController.php`)

**Changes:**

**initiateSTK()** - Capture router_id:
```php
// Validation
'router_id' => 'nullable|exists:routers,id',

// Auto-detect router if not provided
$routerId = $validated['router_id'] ?? $this->detectRouterFromRequest($request);

// Create payment with router_id
$payment = Payment::create([
    // ... other fields
    'router_id' => $routerId,
]);
```

**detectRouterFromRequest()** - Auto-detect router:
```php
private function detectRouterFromRequest(Request $request)
{
    $gatewayIp = $request->header('X-Gateway-IP') 
              ?? $request->header('X-Router-IP')
              ?? $request->ip();
    
    $router = Router::where('ip_address', $gatewayIp)->first();
    return $router ? $router->id : null;
}
```

---

### **4. New Controller: RouterAnalyticsController**

**File:** `app/Http/Controllers/Api/RouterAnalyticsController.php`

**Endpoints:**

1. **GET `/api/routers/revenue/all`** - Get revenue for all routers
   ```json
   {
     "success": true,
     "data": [
       {
         "router_id": "uuid",
         "router_name": "Main Office Router",
         "router_location": "Nairobi HQ",
         "total_revenue": 45000.00,
         "transaction_count": 150,
         "package_breakdown": [
           {
             "package_id": "uuid",
             "package_name": "1 Hour - 5GB",
             "revenue": 15000.00,
             "transactions": 50
           }
         ],
         "status": "online"
       }
     ]
   }
   ```

2. **GET `/api/routers/{router}/analytics`** - Detailed analytics for one router
   ```json
   {
     "success": true,
     "data": {
       "router": { "id": "uuid", "name": "...", "location": "..." },
       "revenue": {
         "total": 45000.00,
         "transaction_count": 150,
         "average_per_transaction": 300.00
       },
       "package_breakdown": [...],
       "daily_revenue": [...], // Last 30 days
       "assigned_packages": [...] // Global + specific packages
     }
   }
   ```

3. **POST `/api/routers/revenue/compare`** - Compare multiple routers
   ```json
   {
     "router_ids": ["uuid1", "uuid2"],
     "period": "30days" // 7days, 30days, 90days, all
   }
   ```

---

### **5. Routes Added**

**File:** `routes/api.php`

```php
Route::prefix('routers')->name('api.routers.')->group(function () {
    // ... existing routes
    
    // Router Analytics & Revenue
    Route::get('/{router}/analytics', [RouterAnalyticsController::class, 'getRouterDetails'])
        ->name('analytics');
    Route::get('/revenue/all', [RouterAnalyticsController::class, 'getRouterRevenue'])
        ->name('revenue.all');
    Route::post('/revenue/compare', [RouterAnalyticsController::class, 'compareRouters'])
        ->name('revenue.compare');
});
```

---

## 🎯 How It Works

### **Scenario 1: Global Package**

```
Tenant creates package:
- Name: "1 Hour - 5GB"
- is_global: true
- router_ids: [] (empty)

Result:
- Package available on ALL routers
- Hotspot users on any router can see it
```

### **Scenario 2: Router-Specific Package**

```
Tenant creates package:
- Name: "Office Special"
- is_global: false
- router_ids: [router_a_id, router_b_id]

Result:
- Package ONLY available on Router A and Router B
- Hotspot users on Router C cannot see it
- Helps with location-based pricing
```

### **Scenario 3: Payment & Revenue Tracking**

```
Hotspot user connects to Router A:
1. Frontend detects router_id from network
2. Shows global packages + Router A packages
3. User purchases "1 Hour - 5GB"
4. Payment created with router_id = Router A
5. Revenue tracked to Router A
6. Tenant can see Router A generated KES 300
```

---

## 📊 Revenue Analytics

### **Use Cases:**

1. **Identify Top-Performing Routers**
   - Which location generates most revenue?
   - Which router has most transactions?

2. **Package Performance by Router**
   - Which packages sell best at each location?
   - Optimize pricing per location

3. **Compare Routers**
   - Compare 2+ routers side-by-side
   - Filter by time period (7/30/90 days)

4. **Daily Revenue Trends**
   - See revenue trends over last 30 days
   - Identify peak days/times

---

## 🚀 Frontend Implementation (IN PROGRESS)

### **Required Changes:**

1. **Update `usePackages.js` composable:**
   - Add `is_global` and `router_ids` to formData
   - Fetch routers list for selection

2. **Update `CreatePackageOverlay.vue`:**
   - Add "Router Assignment" section
   - Toggle between Global/Specific routers
   - Multi-select dropdown for router selection
   - Show assigned routers in package list

3. **Create Router Revenue Dashboard:**
   - Display revenue per router
   - Charts and graphs
   - Package breakdown per router
   - Comparison tools

4. **Update Package Display:**
   - Show router assignments in package cards
   - Badge for "Global" vs "Router-Specific"
   - List of assigned routers

---

## ✅ Testing Checklist

### **Backend:**
- [x] Migration created
- [x] Models updated with relationships
- [x] PackageController handles router assignments
- [x] PublicPackageController filters by router
- [x] PaymentController captures router_id
- [x] RouterAnalyticsController created
- [x] Routes added
- [ ] Migration run (pending Docker sync)

### **Frontend:**
- [ ] Router selection UI
- [ ] Global/Specific toggle
- [ ] Display router assignments
- [ ] Revenue analytics dashboard
- [ ] Package filtering by router

---

## 🎉 Benefits

1. **Location-Based Pricing**
   - Different packages for different locations
   - Premium packages for high-traffic areas

2. **Revenue Insights**
   - Know which locations are profitable
   - Make data-driven decisions

3. **Better Accounting**
   - Track revenue per router/location
   - Easier financial reporting

4. **Flexible Management**
   - Global packages for consistency
   - Specific packages for customization

---

**Implementation Status:** Backend ✅ Complete | Frontend 🔄 In Progress  
**Next Steps:** Complete frontend UI and test end-to-end flow
