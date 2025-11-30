# Router Card Sorting Fix

## Problem
Router cards were shifting positions on every refresh because the routers array wasn't consistently ordered. This happened because:
1. The API response order could vary between requests
2. WebSocket updates could modify the array without maintaining order
3. No explicit sorting was applied to maintain consistent positioning

## Solution
Implemented **consistent sorting by router ID** at two critical points:

### 1. Initial Fetch - `useRouters.js` (Lines 85-90)
Sort routers immediately when fetched from the API:

```javascript
const fetchRouters = async () => {
  loading.value = true
  listError.value = ''
  try {
    console.log('Sending GET to /routers')
    const response = await axios.get('/routers')
    const fetchedRouters = response.data.data || []
    
    // Sort by ID to maintain consistent order
    routers.value = fetchedRouters.sort((a, b) => {
      const idA = a.id || 0
      const idB = b.id || 0
      return idA - idB
    })
    
    console.log('fetchRouters response:', response.data)
    console.log('Routers sorted by ID:', routers.value.map(r => ({ id: r.id, name: r.name })))
  } catch (err) {
    // ... error handling
  }
}
```

**Benefits:**
- Routers are sorted as soon as they're loaded
- Console log shows the sorted order for debugging
- Handles missing IDs gracefully (defaults to 0)

### 2. Display Filtering - `RouterManagement.vue` (Lines 517-536)
Sort routers in the computed property that feeds the display:

```javascript
const filteredRouters = computed(() => {
  let filtered = routers.value
  
  // Apply search filter if query exists
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = routers.value.filter(router => 
      router.name.toLowerCase().includes(query) ||
      (router.ip_address && router.ip_address.includes(query)) ||
      (router.model && router.model.toLowerCase().includes(query))
    )
  }
  
  // Sort by router ID to maintain consistent order
  return [...filtered].sort((a, b) => {
    const idA = a.id || 0
    const idB = b.id || 0
    return idA - idB
  })
})
```

**Benefits:**
- Maintains sort order even after search filtering
- Creates new array (`[...filtered]`) to avoid mutating original
- Ensures consistent order regardless of WebSocket updates
- Works with both filtered and unfiltered views

## How It Works

### Sorting Algorithm
```javascript
.sort((a, b) => {
  const idA = a.id || 0
  const idB = b.id || 0
  return idA - idB
})
```

- **Ascending order**: Lower IDs appear first
- **Null-safe**: Routers without IDs default to 0
- **Numeric comparison**: Uses subtraction for proper number sorting

### Order Preservation

**Before Fix:**
```
Refresh 1: Router-3, Router-1, Router-5, Router-2, Router-4
Refresh 2: Router-1, Router-4, Router-2, Router-5, Router-3
Refresh 3: Router-5, Router-2, Router-1, Router-4, Router-3
```

**After Fix:**
```
Refresh 1: Router-1, Router-2, Router-3, Router-4, Router-5
Refresh 2: Router-1, Router-2, Router-3, Router-4, Router-5
Refresh 3: Router-1, Router-2, Router-3, Router-4, Router-5
```

## WebSocket Updates
WebSocket updates (CPU, Memory, Disk, Status) don't affect order because:
1. Updates modify existing router objects in-place
2. `filteredRouters` computed property re-sorts on every access
3. Vue's reactivity triggers re-render with sorted data

Example WebSocket update:
```javascript
// Updates router data but doesn't change array order
const idx = routers.value.findIndex((r) => r.id === e.router_id);
if (idx !== -1) {
  routers.value[idx].live_data = e.data;  // In-place update
  routers.value[idx].last_updated = e.timestamp;
}
// filteredRouters computed property will re-sort automatically
```

## Search Behavior
When searching, routers remain sorted by ID:

**Search for "Router-3":**
```
Before: Router-1, Router-2, Router-3, Router-4, Router-5
Search: Router-3
After:  Router-3 (only match, maintains ID order)
```

**Search for "RB":**
```
Before: Router-1 (RB750), Router-2 (CCR), Router-3 (RB4011), Router-4 (RB750), Router-5 (CCR)
Search: "RB"
After:  Router-1 (RB750), Router-3 (RB4011), Router-4 (RB750)  ← Still sorted by ID
```

## Pagination
Pagination works correctly with sorted data:

```
Total: 25 routers (IDs 1-25)
Page Size: 10

Page 1: Routers 1-10
Page 2: Routers 11-20
Page 3: Routers 21-25
```

Each page maintains ID order, and routers don't shift between pages on refresh.

## Testing

### Manual Testing
1. **Initial Load**: Verify routers appear in ID order (1, 2, 3, ...)
2. **Refresh**: Click refresh button multiple times - order should remain stable
3. **Search**: Search for routers - results should maintain ID order
4. **WebSocket**: Wait for live updates - cards shouldn't shift
5. **Pagination**: Navigate pages - routers should stay in same positions

### Console Verification
Check browser console for:
```
fetchRouters response: { data: [...] }
Routers sorted by ID: [
  { id: 1, name: 'Router-01' },
  { id: 2, name: 'Router-02' },
  { id: 3, name: 'Router-03' },
  ...
]
```

### Expected Behavior
✅ **Stable Order**: Cards never shift position on refresh
✅ **Predictable**: Same router always in same position
✅ **Search Stable**: Filtered results maintain ID order
✅ **Update Safe**: Live data updates don't cause reordering

## Edge Cases Handled

1. **Missing IDs**: Routers without IDs default to 0, appear first
2. **Duplicate IDs**: Maintain original order (stable sort)
3. **String IDs**: Converted to numbers for proper sorting
4. **Empty Array**: Returns empty array (no errors)
5. **Single Router**: Returns as-is (no sorting needed)

## Performance

- **Sorting Cost**: O(n log n) - negligible for typical router counts (<1000)
- **Memory**: Creates new array only in computed property (minimal overhead)
- **Reactivity**: Computed property caches result until dependencies change
- **No Impact**: Sorting happens in JavaScript, no API calls

## Future Enhancements

Potential improvements if needed:
- [ ] Add user preference for sort order (ID, Name, Status, etc.)
- [ ] Add ascending/descending toggle
- [ ] Add drag-and-drop custom ordering
- [ ] Persist sort preference in localStorage

## Summary

✅ **Fixed**: Router cards now maintain consistent order based on ID
✅ **Stable**: No more shifting on refresh or live updates
✅ **Predictable**: Same router always in same position
✅ **Efficient**: Minimal performance impact
✅ **Robust**: Handles edge cases and missing data
