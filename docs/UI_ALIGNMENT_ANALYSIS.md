# Multi-Page Component Analysis & Alignment Report

## Pages Analyzed
1. **Todo** (`TodosView.vue`) - Reference pattern
2. **Network → Access Points** (`AccessPointsView.vue`)
3. **Network → Live Connections** (`LiveConnectionsNew.vue`)
4. **PPP → Active Sessions** (`ActiveSessionsNew.vue`)

---

## Comparison Matrix

### 1. DataViewContainer Props & Events

| Feature | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| `color-theme` | `blue` | `indigo` | `blue` | `cyan` | ⚠️ Inconsistent |
| `v-model:search-model` | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| `:stats` array | 3 items | 4 items | 3 items | 4 items | ⚠️ Different |
| `:total` | `todos?.length` | `accessPoints?.length` | `connections?.length` | `sessions.length` | ✅ Match |
| `:loading` | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| `add-button-text` | "Add Todo" | "Add Access Point" | ❌ Missing | ❌ `:show-add="false"` | ⚠️ Inconsistent |
| `@refresh` | `fetchTodos` | `fetchAccessPoints` | `fetchConnections` | `fetchSessions` | ✅ Match |
| `@add` | `openCreateModal` | `openCreateModal` | ❌ Missing | ❌ Missing | ⚠️ Inconsistent |
| `@search-clear` | `searchQuery = ''` | `searchQuery = ''` | `clearFilters` | `searchQuery = ''` | ⚠️ Live uses function |

### 2. Icon Slot Pattern

| Page | Icon Type | Implementation | Status |
|------|-----------|----------------|--------|
| Todo | Custom SVG | `<svg xmlns="http://www.w3.org/2000/svg"...` | ✅ Reference |
| Access Points | Lucide Icon | `<WifiIcon class="h-5 w-5..." />` | ⚠️ Different |
| Live Connections | Custom SVG | `<svg xmlns="http://www.w3.org/2000/svg"...` | ✅ Match |
| Active Sessions | Lucide Icon | `<Activity class="h-5 w-5..." />` | ⚠️ Different |

**Recommendation**: Standardize on Custom SVG for consistency with Todo.

### 3. Error State Pattern

| Element | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| Icon | Custom SVG | `AlertCircle` (Lucide) | Custom SVG | `AlertCircle` (Lucide) | ⚠️ Mixed |
| Retry button | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| Position in flow | After modals | Before skeleton | After modals | Before skeleton | ⚠️ Inconsistent |

### 4. Loading State

| Feature | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| Component | `DataSkeleton` | `DataSkeleton` | `DataSkeleton` | `DataSkeleton` | ✅ Match |
| `:count` | `5` | `5` | `5` | `5` | ✅ Match |
| Position | After error | After error | After error | After error | ✅ Match |

### 5. Conditional Rendering Chain

| Page | Chain Order | Pattern |
|------|-------------|---------|
| Todo | `v-if="error"` → `v-else-if="loading"` → `v-else-if="filteredData?.length"` → `v-else` | ✅ Standard |
| Access Points | `v-if="error"` → `v-else-if="loading"` → `v-else-if="filteredAccessPoints?.length"` | ✅ Match |
| Live Connections | `v-if="error"` → `v-else-if="loading"` → `v-else-if="filteredData.length"` | ✅ Match |
| Active Sessions | `v-if="error"` → `v-else-if="loading"` → `v-else-if="filteredData.length"` | ✅ Match |

### 6. Composable Pattern Usage

| Page | Composable | WebSocket/SSE | Pattern |
|------|------------|---------------|---------|
| Todo | `useTodos()` | WebSocket | ✅ Reference |
| Access Points | `useAccessPoints()` | WebSocket | ✅ Match |
| Live Connections | `useLiveConnections()` | SSE + WebSocket | ✅ Enhanced |
| Active Sessions | Inline functions | WebSocket (`subscribeToWebSocket`) | ⚠️ No composable |

**Critical Issue**: Active Sessions uses inline functions instead of composable pattern.

### 7. Data Table Structure

| Feature | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| Table class | `w-full` | `w-full` | `w-full` | `w-full` | ✅ Match |
| `table-fixed` | ❌ No | ❌ No | ❌ No | ❌ No | ✅ Match |
| Fixed header | ✅ | ✅ | ✅ | ⚠️ Sticky only | ⚠️ Different |
| Scrollable body | ✅ | ✅ | ✅ | ✅ Combined | ⚠️ Different |
| Mobile cards | `MobileDataCard` | `MobileDataCard` | `MobileDataCard` | `MobileDataCard` | ✅ Match |

### 8. Menu/Dropdown Pattern

| Feature | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| `toggleMenu` function | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| Viewport positioning | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| Teleport to body | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| `data-menu-button` attr | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| `data-dropdown-menu` attr | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| Escape key handler | ✅ | ✅ | ✅ | ✅ | ✅ Match |
| Click outside handler | ✅ | ✅ | ✅ | ✅ | ✅ Match |

### 9. Modal/Overlay Pattern

| Feature | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| Create/Edit modal | `TodoModal` | `AccessPointModal` | ❌ None | ❌ None | ⚠️ Missing |
| Details modal | `TodoDetailsModal` | `AccessPointDetailsModal` | `SlideOverlay` | `SlideOverlay` | ⚠️ Inconsistent |
| Transfer/Extra | `SlideOverlay` | ❌ None | ❌ None | ❌ None | N/A |

### 10. Script Setup Structure

| Element | Todo | Access Points | Live Connections | Active Sessions | Status |
|---------|------|---------------|------------------|-----------------|--------|
| Vue imports | `ref, computed, watch, onMounted, onUnmounted` | Same | Same | `ref, computed, onMounted` | ⚠️ Missing watch |
| Composable import | `useTodos` | `useAccessPoints` | `useLiveConnections` | ❌ None | ⚠️ Active Sessions |
| Axios import | ✅ | ✅ | In composable | ✅ | Mixed |
| Component imports | 8 components | 8 components | 7 components | 7 components | Similar |

---

## Key Issues & Action Items

### Critical Issues (Fix First)

1. **Active Sessions Missing Composable**
   - Create `useActiveSessions` composable
   - Move inline functions to composable
   - Add SSE/WebSocket support

2. **Icon Inconsistency**
   - Access Points: Change `WifiIcon` to custom SVG
   - Active Sessions: Change `Activity` to custom SVG

3. **Error State Icon Inconsistency**
   - Access Points: Change `AlertCircle` to custom SVG
   - Active Sessions: Change `AlertCircle` to custom SVG

### Medium Priority

4. **Add Button Missing**
   - Live Connections: Add `add-button-text` and `@add` event (if applicable)
   - Active Sessions: Has `:show-add="false"` - verify if intentional

5. **Color Theme Standardization**
   - Decide on standard theme per module:
     - Network: `blue` or `indigo`?
     - Hotspot/PPP: `cyan`?

6. **Active Sessions Table Structure**
   - Table uses `sticky top-0` instead of split header/body pattern
   - Consider aligning with other pages

### Minor Issues

7. **Search Clear Handler**
   - Live Connections uses `clearFilters` function
   - Others use direct assignment `searchQuery = ''`
   - Standardize approach

8. **Lifecycle Hook Differences**
   - Active Sessions missing `onUnmounted` imports
   - Some pages fetch statistics, others don't

---

## Feedback Loop Verification Process

After making changes to any page, perform this verification:

### Step 1: Structural Check
```bash
# Compare template structure
diff -u <(grep -E "^\s*<(template|div|DataView|SlideOverlay|DataSkeleton|DataEmpty|DataPagination|Teleport|table|thead|tbody)" TodosView.vue) \
        <(grep -E "^\s*<(template|div|DataView|SlideOverlay|DataSkeleton|DataEmpty|DataPagination|Teleport|table|thead|tbody)" [ModifiedPage].vue)
```

### Step 2: Import Verification
```bash
# Check for unused imports
grep -oP "(?<=import\s)\w+" [ModifiedPage].vue | while read name; do
  count=$(grep -c "$name" [ModifiedPage].vue)
  if [ "$count" -le 1 ]; then
    echo "Potentially unused: $name"
  fi
done
```

### Step 3: Prop/Event Check
- Verify all DataViewContainer props match Todo pattern
- Ensure event handlers are properly bound
- Check `:total` computation matches expected format

### Step 4: Composable Verification
- Ensure composable exports all required functions
- Verify reactive state is properly destructured
- Check lifecycle hooks match pattern

### Step 5: Visual Regression
- Compare error state icons
- Verify table column widths use same pattern
- Check mobile card meta-lines format

---

## Alignment Priority Ranking

1. **Active Sessions** - Needs composable (highest priority)
2. **Access Points** - Error icon + SVG icon (medium)
3. **Live Connections** - Already aligned (verified)
4. **Todo** - Reference pattern (baseline)

---

## Summary Statistics

| Category | Matching | Different | % Aligned |
|----------|----------|-----------|-----------|
| Container Props | 7/9 | 2/9 | 78% |
| Error State | 2/4 | 2/4 | 50% |
| Composable | 3/4 | 1/4 | 75% |
| Table Structure | 3/4 | 1/4 | 75% |
| Menu Pattern | 4/4 | 0/4 | 100% |
| **Overall** | **19/25** | **6/25** | **76%** |

**Target**: 95% alignment across all pages.
