# Router Disk Utilization Feature

## Summary
Added disk utilization monitoring to the RouterManagement component, displaying real-time disk usage alongside CPU and Memory metrics.

## Changes Made

### 1. Table Header Update
**File:** `frontend/src/components/dashboard/RouterManagement.vue` (Line 189)

Added a new "Disk" column header in the table:
```vue
<div class="w-[100px] ml-4 text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:block">Disk</div>
```

### 2. Disk Usage Display (Lines 264-277)
Added disk usage visualization with progress bar and percentage:
```vue
<!-- Disk Usage -->
<div class="w-[100px] ml-4 hidden xl:block">
  <div v-if="getDiskUsage(router)" class="flex items-center gap-1.5">
    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
      <div 
        class="h-full rounded-full transition-all"
        :class="getDiskColorClass(getDiskUsage(router))"
        :style="{ width: getDiskUsage(router) + '%' }"
      ></div>
    </div>
    <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ getDiskUsage(router) }}%</span>
  </div>
  <span v-else class="text-xs text-slate-400">—</span>
</div>
```

### 3. Helper Functions

#### `getDiskColorClass(diskUsage)` (Lines 586-591)
Returns color class based on disk usage percentage:
- **90%+**: Red (`bg-rose-500`) - Critical
- **80-89%**: Amber (`bg-amber-500`) - Warning
- **70-79%**: Yellow (`bg-yellow-500`) - Caution
- **<70%**: Green (`bg-emerald-500`) - Normal

#### `getDiskUsage(router)` (Lines 660-694)
Calculates disk usage percentage from MikroTik router data:
- Reads `free-hdd-space` and `total-hdd-space` from `router.live_data`
- Handles both underscore and hyphen property names
- Uses `parseMemoryValue()` for unit conversion (KB, MB, GB, TB)
- Calculates: `Used Disk = Total - Free`
- Returns percentage: `(usedDisk / totalDisk) * 100`
- Includes debug logging for troubleshooting

### 4. Data Flow

#### Initial Load
1. `fetchRouters()` called on component mount (Line 940)
2. GET request to `/routers` endpoint
3. Routers populated in `routers.value` array

#### Real-time Updates via WebSocket
All WebSocket listeners correctly use `router.id` for identification:

**RouterLiveDataUpdated** (Lines 884-891)
```javascript
const idx = routers.value.findIndex((r) => r.id === e.router_id);
if (idx !== -1) {
  routers.value[idx].live_data = e.data;  // Updates disk data here
  routers.value[idx].last_updated = e.timestamp;
}
```

**RouterStatusUpdated** (Lines 892-906)
```javascript
e.routers.forEach((updatedRouter) => {
  const idx = routers.value.findIndex((r) => r.id === updatedRouter.id);
  if (idx !== -1) {
    routers.value[idx].status = updatedRouter.status;
    // ... other updates
  }
});
```

**RouterConnected** (Lines 907-914)
```javascript
const idx = routers.value.findIndex((r) => r.id === e.router_id);
```

### 5. Expected Data Structure

The backend should provide disk data in `live_data`:
```json
{
  "id": 1,
  "name": "Router-01",
  "live_data": {
    "cpu_load": 45,
    "free-memory": "512MiB",
    "total-memory": "1024MiB",
    "free-hdd-space": "2.5GiB",    // New field
    "total-hdd-space": "10GiB"      // New field
  }
}
```

Alternative property names also supported:
- `free_hdd_space` / `free-hdd-space`
- `total_hdd_space` / `total-hdd-space`

## Visual Design

- **Column Width**: 100px (same as CPU/Memory)
- **Visibility**: Hidden on screens smaller than XL (`hidden xl:block`)
- **Progress Bar**: 1.5px height, rounded, with color-coded fill
- **Percentage Display**: Right-aligned, 8px width
- **Empty State**: Em dash (—) when no data available

## Router ID Population

✅ **Confirmed**: All router updates are correctly indexed by `router.id`:
- Initial fetch populates routers with their database IDs
- WebSocket events use `e.router_id` to match and update specific routers
- `findIndex((r) => r.id === e.router_id)` ensures correct router is updated
- No risk of updating wrong router or losing data

## Testing Checklist

- [ ] Verify disk column appears on XL+ screens
- [ ] Confirm disk usage displays correctly (0-100%)
- [ ] Check color coding (green → yellow → amber → red)
- [ ] Test with routers that have no disk data (shows "—")
- [ ] Verify WebSocket updates disk data in real-time
- [ ] Confirm router updates target correct router by ID
- [ ] Test with various disk sizes (KB, MB, GB, TB)
- [ ] Check console logs for disk calculation debugging

## Browser Console Debugging

When disk data is updated, you'll see:
```
Disk calc for router: {
  free: "2.5GiB",
  total: "10GiB",
  parsedFree: 2684354560,
  parsedTotal: 10737418240,
  usedDisk: 8053063680,
  percentage: 75
}
```

## Notes

- Disk column positioned between Memory and Model columns
- Follows same design pattern as CPU and Memory metrics
- Reuses `parseMemoryValue()` function for unit conversion
- More aggressive color thresholds than memory (80% vs 90% for amber)
- All router identification uses `router.id` for data integrity
