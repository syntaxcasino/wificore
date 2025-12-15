# Router Management Z-Index Fix

## Issue
The router management page header was overlapping the sidebar, causing visual layering problems.

## Root Cause
The content area was allowing child elements to overflow and escape their container, causing the router header to visually overlap the fixed sidebar. The issue was:
1. Content area lacked `overflow-x: hidden` to contain children
2. Content area lacked `position: relative` to create a proper stacking context
3. Negative margins in child components were pulling content outside boundaries

## Z-Index Hierarchy

### Application-Wide Z-Index Levels:
```
z-[9999] - Dropdown menus (highest)
z-50     - Modals and overlays
z-40     - Sidebar (AppSidebar.vue)
z-30     - Top navigation bar
z-10     - Page headers (below sidebar)
z-[5]    - Sticky table headers (within page content)
z-0      - Default content
```

## Changes Made

### 1. DashboardLayout Content Area (PRIMARY FIX)
**File:** `frontend/src/modules/common/components/layout/DashboardLayout.vue`

**Before:**
```css
.content-area {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  padding-top: 80px;
}
```

**After:**
```css
.content-area {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;  /* ← Prevents horizontal overflow */
  padding: 20px;
  padding-top: 80px;
  position: relative;  /* ← Creates stacking context */
}
```

**Reason:** 
- `overflow-x: hidden` prevents child elements from escaping horizontally
- `position: relative` creates a new stacking context, containing all child z-indexes
- This ensures content stays within the designated area and doesn't overlap the sidebar

### 2. MikrotikList Wrapper
**File:** `frontend/src/modules/tenant/views/dashboard/routers/MikrotikList.vue`

**Before:**
```vue
<div class="flex flex-col h-full">
```

**After:**
```vue
<div class="flex flex-col h-full -m-5">
```

**Reason:** Negative margin `-m-5` (20px) expands to fill the parent padding, creating a full-bleed layout while staying contained.

### 3. RouterView Container
**File:** `frontend/src/modules/tenant/views/dashboard/routers/RoutersView.vue`

**Before:**
```vue
<div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg">
```

**After:**
```vue
<div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg overflow-hidden">
```

**Reason:** `overflow-hidden` ensures rounded corners work properly and prevents any internal content from escaping.

### 4. Sticky Table Header
**Before:**
```vue
<thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-10">
```

**After:**
```vue
<thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
```

**Reason:** Reduced z-index to maintain proper hierarchy within the page content area.

## Z-Index Best Practices

### 1. Use Consistent Levels
- Define z-index levels in increments (e.g., 10, 20, 30, 40, 50)
- Leave gaps for future additions
- Document the hierarchy

### 2. Stacking Context Rules
- Elements with `position: relative/absolute/fixed` create new stacking contexts
- Z-index only works within the same stacking context
- Parent's z-index affects all children

### 3. Common Patterns
```css
/* Sidebar - Always visible */
z-40

/* Page Headers - Below sidebar */
z-10

/* Sticky Elements - Within content */
z-[5]

/* Modals - Above everything except dropdowns */
z-50

/* Dropdowns - Highest priority */
z-[9999]
```

## Testing Checklist

- [x] Sidebar doesn't get covered by router header
- [x] Sticky table header works correctly
- [x] Dropdown menu appears above all content
- [x] Modals appear above page content
- [x] No z-index conflicts on mobile view
- [x] Search bar and action buttons work correctly

## Related Files

1. `frontend/src/modules/common/components/layout/AppSidebar.vue` - Sidebar with `z-40`
2. `frontend/src/modules/tenant/views/dashboard/routers/RoutersView.vue` - Router management page
3. All other dashboard pages should follow the same z-index pattern

## Future Considerations

If adding new components with layering:
1. Check the z-index hierarchy above
2. Use appropriate level based on component purpose
3. Test with sidebar open/closed
4. Test on mobile and desktop
5. Document any new z-index levels

## Visual Hierarchy (Top to Bottom)

```
┌─────────────────────────────────────┐
│  Dropdown Menus (z-[9999])          │ ← Highest
├─────────────────────────────────────┤
│  Modals & Overlays (z-50)           │
├─────────────────────────────────────┤
│  Sidebar (z-40)                     │
├─────────────────────────────────────┤
│  Top Navigation (z-30)              │
├─────────────────────────────────────┤
│  Page Headers (z-10)                │
├─────────────────────────────────────┤
│  Sticky Table Headers (z-[5])       │
├─────────────────────────────────────┤
│  Regular Content (z-0)              │ ← Lowest
└─────────────────────────────────────┘
```

## Deployment

No special deployment steps required. Changes are CSS-only and will take effect immediately after rebuild:

```bash
cd d:\traidnet\wifi-hotspot
docker-compose build frontend
docker-compose up -d frontend
```
