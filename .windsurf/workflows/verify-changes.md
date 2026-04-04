# Post-Change Verification Workflow

## Purpose
After making code changes, systematically verify alignment with reference patterns before concluding.

## Steps

### 1. Structural Alignment Check
- [ ] Compare template structure (slots, conditionals, loops)
- [ ] Verify component hierarchy matches reference
- [ ] Check CSS classes and styling patterns

### 2. Import & Dependency Verification
- [ ] All imports are used (no dead code)
- [ ] Import order matches reference pattern
- [ ] No missing dependencies

### 3. Props & Events Validation
- [ ] Component props match reference
- [ ] Event handlers properly bound
- [ ] Emit declarations correct

### 4. Composable Pattern Check
- [ ] Composable exports match usage
- [ ] Reactive state properly destructured
- [ ] Functions called correctly

### 5. Logic & Behavior Verification
- [ ] Computed properties have correct dependencies
- [ ] Watchers properly configured
- [ ] Lifecycle hooks match pattern

### 6. Visual/UX Consistency
- [ ] Icons match style
- [ ] Button patterns consistent
- [ ] Empty/error states match

## Application: LiveConnections vs Todo

### Comparison Matrix
| Aspect | Todo Page | Live Connections | Status |
|--------|-----------|------------------|--------|
| DataViewContainer props | :total, :loading, @refresh | :total, :loading, @refresh | ✅ Match |
| Composable pattern | useTodos() | useLiveConnections() | ✅ Match |
| Icon slot | Custom SVG | Custom SVG | ✅ Match |
| Actions slot | Add button | Export button | ✅ Match |
| Error state | Custom SVG icon | Custom SVG icon | ✅ Match |
| Table structure | No table-fixed | No table-fixed | ✅ Match |
| Real-time updates | WebSocket | SSE + WebSocket | ✅ Enhanced |

### Verification Commands
```bash
# Check for unused imports
grep -n "import.*from" LiveConnectionsNew.vue | wc -l

# Verify all imports are used
grep -oP "(?<=import\s)\w+" LiveConnectionsNew.vue | while read name; do
  if ! grep -q "$name" LiveConnectionsNew.vue | grep -v "^import"; then
    echo "Potentially unused: $name"
  fi
done

# Compare file structures
diff -u <(grep -E "^\s*(template|script|div|DataView|SlideOverlay|DataSkeleton|DataEmpty|DataPagination|Teleport)" TodosView.vue) \
        <(grep -E "^\s*(template|script|div|DataView|SlideOverlay|DataSkeleton|DataEmpty|DataPagination|Teleport)" LiveConnectionsNew.vue)
```
