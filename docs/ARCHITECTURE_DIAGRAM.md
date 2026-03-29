# Package Management System - Architecture

## 🏗️ System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        USER INTERFACE                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────┐        ┌──────────────────────┐        │
│  │  Public View        │        │   Admin Dashboard    │        │
│  │  (PackagesView)     │        │   (AllPackages)      │        │
│  ├─────────────────────┤        ├──────────────────────┤        │
│  │ • Hotspot Only      │        │ • List View          │        │
│  │ • Active Only       │        │ • Search & Filter    │        │
│  │ • Grid Display      │        │ • CRUD Operations    │        │
│  │ • Purchase Flow     │        │ • Status Management  │        │
│  └─────────────────────┘        └──────────────────────┘        │
│           │                               │                      │
│           └───────────────┬───────────────┘                      │
│                           │                                      │
│                    ┌──────▼──────┐                              │
│                    │  usePackages │                              │
│                    │  Composable  │                              │
│                    └──────┬───────┘                              │
└───────────────────────────┼──────────────────────────────────────┘
                            │
                    ┌───────▼────────┐
                    │   Axios HTTP   │
                    │    Client      │
                    └───────┬────────┘
                            │
┌───────────────────────────▼──────────────────────────────────────┐
│                      BACKEND API                                  │
├───────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────────────────────────┐     │
│  │           PackageController                             │     │
│  ├────────────────────────────────────────────────────────┤     │
│  │  • index()    - GET /api/packages                      │     │
│  │  • store()    - POST /api/packages                     │     │
│  │  • update()   - PUT /api/packages/{id}                 │     │
│  │  • destroy()  - DELETE /api/packages/{id}              │     │
│  └─────────────────────────┬──────────────────────────────┘     │
│                            │                                     │
│                    ┌───────▼────────┐                           │
│                    │ Cache Layer    │                           │
│                    │ (Redis/File)   │                           │
│                    │ TTL: 10 min    │                           │
│                    └───────┬────────┘                           │
│                            │                                     │
│                    ┌───────▼────────┐                           │
│                    │ Package Model  │                           │
│                    │ (Eloquent ORM) │                           │
│                    └───────┬────────┘                           │
└────────────────────────────┼──────────────────────────────────────┘
                             │
┌────────────────────────────▼──────────────────────────────────────┐
│                      DATABASE LAYER                                │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │              PostgreSQL Database                          │    │
│  ├──────────────────────────────────────────────────────────┤    │
│  │  packages Table:                                          │    │
│  │  ├─ id (UUID, PK)                                         │    │
│  │  ├─ type (VARCHAR) - hotspot/pppoe                       │    │
│  │  ├─ name (VARCHAR)                                        │    │
│  │  ├─ description (TEXT)                                    │    │
│  │  ├─ duration (VARCHAR)                                    │    │
│  │  ├─ upload_speed (VARCHAR)                                │    │
│  │  ├─ download_speed (VARCHAR)                              │    │
│  │  ├─ speed (VARCHAR)                                       │    │
│  │  ├─ price (FLOAT)                                         │    │
│  │  ├─ devices (INTEGER)                                     │    │
│  │  ├─ data_limit (VARCHAR)                                  │    │
│  │  ├─ validity (VARCHAR)                                    │    │
│  │  ├─ enable_burst (BOOLEAN)                                │    │
│  │  ├─ enable_schedule (BOOLEAN)                             │    │
│  │  ├─ hide_from_client (BOOLEAN)                            │    │
│  │  ├─ status (VARCHAR) - active/inactive                    │    │
│  │  ├─ is_active (BOOLEAN)                                   │    │
│  │  ├─ users_count (INTEGER)                                 │    │
│  │  ├─ created_at (TIMESTAMP)                                │    │
│  │  └─ updated_at (TIMESTAMP)                                │    │
│  │                                                            │    │
│  │  Indexes:                                                  │    │
│  │  • idx_packages_type                                      │    │
│  │  • idx_packages_status                                    │    │
│  │  • idx_packages_is_active                                 │    │
│  └──────────────────────────────────────────────────────────┘    │
└────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Data Flow

### 1. Fetching Packages (Read)
```
User → AllPackages.vue → usePackages.fetchPackages() 
     → Axios GET /api/packages 
     → PackageController.index() 
     → Cache Check → Database Query 
     → Return JSON → Update Vue State 
     → Render UI
```

### 2. Creating Package (Create)
```
User → Click "Add Package" 
     → CreatePackageOverlay opens 
     → Fill form → Submit 
     → usePackages.addPackage() 
     → Axios POST /api/packages 
     → PackageController.store() 
     → Validate → Create in DB 
     → Clear Cache → Return JSON 
     → Show success → Refresh list
```

### 3. Editing Package (Update)
```
User → Click 3-dot menu → Edit 
     → CreatePackageOverlay opens (edit mode) 
     → Modify form → Submit 
     → usePackages.updatePackage() 
     → Axios PUT /api/packages/{id} 
     → PackageController.update() 
     → Validate → Update in DB 
     → Clear Cache → Return JSON 
     → Close overlay → Refresh list
```

### 4. Deleting Package (Delete)
```
User → Click 3-dot menu → Delete 
     → Confirm dialog 
     → usePackages.deletePackage(id) 
     → Axios DELETE /api/packages/{id} 
     → PackageController.destroy() 
     → Check constraints → Delete from DB 
     → Clear Cache → Return success 
     → Refresh list
```

### 5. Toggling Status
```
User → Click toggle icon 
     → Confirm dialog 
     → usePackages.toggleStatus(pkg) 
     → Axios PUT /api/packages/{id} 
     → PackageController.update() 
     → Update status & is_active 
     → Clear Cache → Return JSON 
     → Refresh list
```

---

## 🎨 Component Hierarchy

```
AllPackages.vue (Main View)
├── Header Section
│   ├── Title & Icon
│   ├── Search Bar
│   └── Action Buttons
│       ├── Refresh Button
│       └── Add Package Button
│
├── Content Area
│   ├── Loading State (Skeleton)
│   ├── Error State (Retry)
│   └── Success State
│       ├── Table Header
│       └── Package Rows
│           ├── Package Info (Icon, Name, Description)
│           ├── Type Badge
│           ├── Price
│           ├── Speed
│           ├── Validity
│           ├── Status Badge
│           └── Actions
│               ├── View Button
│               ├── Toggle Button
│               └── 3-Dot Menu
│                   ├── Edit
│                   ├── Duplicate
│                   └── Delete
│
└── Overlays
    ├── ViewPackageOverlay
    │   └── Package Details Display
    │
    └── CreatePackageOverlay (Dual Purpose)
        ├── Create Mode
        └── Edit Mode
```

---

## 🔐 Security & Validation

### Frontend Validation
```
CreatePackageOverlay
├── Required Fields
│   ├── name
│   ├── type
│   ├── price
│   ├── devices
│   ├── speed
│   ├── upload_speed
│   ├── download_speed
│   ├── duration
│   └── validity
│
├── Optional Fields
│   ├── description
│   ├── data_limit
│   ├── enable_burst
│   ├── enable_schedule
│   └── hide_from_client
│
└── Validation Rules
    ├── Type: hotspot or pppoe
    ├── Price: numeric, min 0
    ├── Devices: integer, min 1
    └── Status: active or inactive
```

### Backend Validation
```
PackageController
├── store() Validation
│   ├── Type: required, in:hotspot,pppoe
│   ├── Name: required, max:255
│   ├── Description: nullable, string
│   ├── Duration: required, max:50
│   ├── Upload Speed: required, max:50
│   ├── Download Speed: required, max:50
│   ├── Speed: nullable, max:50
│   ├── Price: required, numeric, min:0
│   ├── Devices: required, integer, min:1
│   ├── Data Limit: nullable, max:50
│   ├── Validity: nullable, max:50
│   ├── Enable Burst: boolean
│   ├── Enable Schedule: boolean
│   ├── Hide from Client: boolean
│   ├── Status: nullable, in:active,inactive
│   └── Is Active: boolean
│
└── update() Validation
    └── Same as store() but with 'sometimes' rule
```

---

## 💾 Cache Strategy

```
Cache Flow:
┌─────────────────────────────────────────────────────┐
│                 Cache Management                     │
├─────────────────────────────────────────────────────┤
│                                                      │
│  Read Operations:                                    │
│  ┌────────────────────────────────────────┐        │
│  │ 1. Check cache for 'packages_list'     │        │
│  │ 2. If exists → Return cached data      │        │
│  │ 3. If not → Query database             │        │
│  │ 4. Store in cache (TTL: 10 min)        │        │
│  │ 5. Return data                          │        │
│  └────────────────────────────────────────┘        │
│                                                      │
│  Write Operations (Create/Update/Delete):            │
│  ┌────────────────────────────────────────┐        │
│  │ 1. Perform database operation           │        │
│  │ 2. Clear 'packages_list' cache          │        │
│  │ 3. Next read will refresh cache         │        │
│  └────────────────────────────────────────┘        │
│                                                      │
└─────────────────────────────────────────────────────┘
```

---

## 🎯 State Management

### usePackages Composable State
```javascript
State Tree:
├── packages (ref<Array>)
│   └── List of all packages
│
├── Loading States
│   ├── loading (ref<Boolean>)
│   ├── refreshing (ref<Boolean>)
│   └── formSubmitting (ref<Boolean>)
│
├── Error States
│   ├── listError (ref<String>)
│   └── formError (ref<String>)
│
├── Overlay States
│   ├── showFormOverlay (ref<Boolean>)
│   ├── showDetailsOverlay (ref<Boolean>)
│   └── showUpdateOverlay (ref<Boolean>)
│
├── Current Data
│   ├── currentPackage (ref<Object>)
│   ├── selectedPackage (ref<Object>)
│   └── formData (ref<Object>)
│
├── Form States
│   ├── formMessage (ref<Object>)
│   └── formSubmitted (ref<Boolean>)
│
└── UI States
    └── showMenu (ref<String|null>)
```

---

## 🔄 Lifecycle & Events

### Component Lifecycle
```
AllPackages.vue Lifecycle:
┌────────────────────────────────────────┐
│ 1. Component Created                   │
│    └── Initialize composable           │
│                                        │
│ 2. onMounted()                         │
│    ├── fetchPackages()                 │
│    └── Add click listener             │
│                                        │
│ 3. User Interactions                   │
│    ├── Search input                    │
│    ├── Button clicks                   │
│    ├── Menu toggles                    │
│    └── Overlay operations              │
│                                        │
│ 4. onBeforeUnmount()                   │
│    └── Remove click listener           │
└────────────────────────────────────────┘
```

### Event Flow
```
User Action → Component Method → Composable Method 
           → API Call → Backend Processing 
           → Database Operation → Response 
           → State Update → UI Re-render
```

---

## 📊 Performance Considerations

### Optimization Strategies
```
1. Caching
   ├── Server-side cache (10 min TTL)
   ├── Reduces database queries
   └── Invalidated on mutations

2. Computed Properties
   ├── filteredPackages (search)
   ├── activeCount (statistics)
   └── inactiveCount (statistics)

3. Lazy Loading
   ├── Overlays loaded on demand
   └── Components rendered conditionally

4. Efficient Re-renders
   ├── Vue 3 reactivity system
   ├── Targeted updates
   └── Virtual DOM diffing

5. Debouncing
   ├── Search input (implicit via v-model)
   └── API calls batched
```

---

## 🎨 Design Patterns Used

### 1. Composition Pattern
- usePackages composable
- Reusable logic extraction
- Separation of concerns

### 2. Observer Pattern
- Vue reactivity system
- State changes trigger updates
- Event-driven architecture

### 3. Factory Pattern
- Overlay components (create/edit modes)
- Reusable form component
- Configuration-based rendering

### 4. Strategy Pattern
- Different views (public/admin)
- Filtering strategies
- Validation strategies

### 5. Repository Pattern
- PackageController as repository
- Abstraction over data access
- Centralized data operations

---

**Architecture Version:** 1.0.0  
**Last Updated:** October 23, 2025  
**Status:** Production Ready
