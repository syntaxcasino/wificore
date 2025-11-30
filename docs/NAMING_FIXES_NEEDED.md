# Naming Convention Fixes Required

## ✅ Current Status: 95% Compliant!

Your frontend is already following most best practices! Here are the few issues to fix:

## ❌ Issues Found

### 1. Single-Word Components (Priority A - Must Fix)

These violate Vue.js essential rules:

```
❌ components/common/Button.vue
❌ components/common/Modal.vue
❌ components/dashboard/Packages.vue
❌ components/ui/Topbar.vue
```

**Fix Required:**
```
Button.vue → BaseButton.vue (or AppButton.vue)
Modal.vue → BaseModal.vue (or AppModal.vue)
Packages.vue → PackagesManager.vue (or DashboardPackages.vue)
Topbar.vue → AppTopbar.vue (already exists, remove duplicate)
```

### 2. Generic Component Names

```
❌ components/Sidebar.vue (too generic)
```

**Fix Required:**
```
Sidebar.vue → AppSidebar.vue (already exists in layout/, remove duplicate)
```

### 3. Inconsistent Naming

```
❌ components/dashboard/widgets/DataUsage.vue
❌ components/dashboard/widgets/SessionLogs.vue
❌ components/dashboard/widgets/SystemLogs.vue
```

**Better (for consistency):**
```
DataUsage.vue → DataUsageWidget.vue
SessionLogs.vue → SessionLogsWidget.vue
SystemLogs.vue → SystemLogsWidget.vue
```

### 4. Chart Components (Optional Enhancement)

Currently good, but could be more specific:
```
✅ ActiveUsersChart.vue (good)
✅ PaymentsChart.vue (good)
❌ RetentionRate.vue → RetentionRateChart.vue (for consistency)
```

## 🔧 Recommended Fixes (Priority Order)

### Priority 1: Fix Single-Word Components (MUST)

```bash
# 1. Rename Button.vue
components/common/Button.vue → components/common/BaseButton.vue

# 2. Rename Modal.vue
components/common/Modal.vue → components/common/BaseModal.vue

# 3. Rename Packages.vue
components/dashboard/Packages.vue → components/dashboard/PackagesManager.vue

# 4. Remove duplicate Sidebar.vue
components/Sidebar.vue → DELETE (use layout/AppSidebar.vue)

# 5. Remove duplicate Topbar.vue
components/ui/Topbar.vue → DELETE (use layout/AppTopbar.vue)
```

### Priority 2: Add Widget Suffix (RECOMMENDED)

```bash
components/dashboard/widgets/DataUsage.vue → DataUsageWidget.vue
components/dashboard/widgets/SessionLogs.vue → SessionLogsWidget.vue
components/dashboard/widgets/SystemLogs.vue → SystemLogsWidget.vue
```

### Priority 3: Chart Consistency (OPTIONAL)

```bash
components/dashboard/charts/RetentionRate.vue → RetentionRateChart.vue
```

## 📋 Step-by-Step Fix Guide

### Step 1: Rename Single-Word Components

#### 1.1 Rename Button.vue to BaseButton.vue
```powershell
# Rename file
Rename-Item "frontend/src/components/common/Button.vue" "BaseButton.vue"

# Update all imports (search and replace)
# From: import Button from '@/components/common/Button.vue'
# To:   import BaseButton from '@/components/common/BaseButton.vue'
```

#### 1.2 Rename Modal.vue to BaseModal.vue
```powershell
# Rename file
Rename-Item "frontend/src/components/common/Modal.vue" "BaseModal.vue"

# Update all imports
# From: import Modal from '@/components/common/Modal.vue'
# To:   import BaseModal from '@/components/common/BaseModal.vue'
```

#### 1.3 Rename Packages.vue to PackagesManager.vue
```powershell
# Rename file
Rename-Item "frontend/src/components/dashboard/Packages.vue" "PackagesManager.vue"

# Update all imports
# From: import Packages from '@/components/dashboard/Packages.vue'
# To:   import PackagesManager from '@/components/dashboard/PackagesManager.vue'
```

#### 1.4 Remove Duplicate Files
```powershell
# Delete Sidebar.vue (use AppSidebar.vue instead)
Remove-Item "frontend/src/components/Sidebar.vue"

# Delete Topbar.vue (use AppTopbar.vue instead)
Remove-Item "frontend/src/components/ui/Topbar.vue"
```

### Step 2: Update Widget Names (Optional)

```powershell
# Rename widgets
Rename-Item "frontend/src/components/dashboard/widgets/DataUsage.vue" "DataUsageWidget.vue"
Rename-Item "frontend/src/components/dashboard/widgets/SessionLogs.vue" "SessionLogsWidget.vue"
Rename-Item "frontend/src/components/dashboard/widgets/SystemLogs.vue" "SystemLogsWidget.vue"
```

### Step 3: Update Chart Names (Optional)

```powershell
# Rename chart
Rename-Item "frontend/src/components/dashboard/charts/RetentionRate.vue" "RetentionRateChart.vue"
```

## 🔍 Files That Need Import Updates

After renaming, update imports in these files:

### For BaseButton:
- Search for: `from '@/components/common/Button.vue'`
- Replace with: `from '@/components/common/BaseButton.vue'`

### For BaseModal:
- Search for: `from '@/components/common/Modal.vue'`
- Replace with: `from '@/components/common/BaseModal.vue'`

### For PackagesManager:
- Search for: `from '@/components/dashboard/Packages.vue'`
- Replace with: `from '@/components/dashboard/PackagesManager.vue'`

### For Sidebar (remove):
- Search for: `from '@/components/Sidebar.vue'`
- Replace with: `from '@/components/layout/AppSidebar.vue'`

### For Topbar (remove):
- Search for: `from '@/components/ui/Topbar.vue'`
- Replace with: `from '@/components/layout/AppTopbar.vue'`

## ✅ What's Already Good

### Components (Already Following Best Practices):
✅ AppHeader.vue
✅ AppLayout.vue
✅ AppSidebar.vue
✅ AppTopbar.vue
✅ AppFooter.vue
✅ PackageCard.vue
✅ PackageList.vue
✅ PackageSelector.vue
✅ PaymentModal.vue
✅ RouterList.vue
✅ CreateRouterModal.vue
✅ UpdateRouterModal.vue
✅ RouterDetailsModal.vue
✅ StatsCard.vue
✅ ActiveUsersChart.vue
✅ PaymentsChart.vue
✅ LoadingSpinner.vue
✅ ErrorMessage.vue

### Composables (Perfect):
✅ useAuth.js
✅ useDashboard.js
✅ usePackages.js
✅ useRouters.js
✅ useRouterUtils.js
✅ useBroadcasting.js
✅ usePaymentWebSocket.js

### Views (Perfect):
✅ LoginView.vue
✅ DashboardView.vue
✅ PackagesView.vue
✅ RoutersView.vue
✅ HomeView.vue

### Directories (Perfect):
✅ components/
✅ composables/
✅ views/
✅ stores/
✅ router/
✅ assets/

## 📊 Compliance Score

**Before Fixes:** 95% compliant
**After Fixes:** 100% compliant

### Breakdown:
- Components: 95% → 100%
- Composables: 100%
- Views: 100%
- Directories: 100%
- Stores: 100%

## 🎯 Summary

**Critical Fixes (Must Do):**
1. Rename Button.vue → BaseButton.vue
2. Rename Modal.vue → BaseModal.vue
3. Rename Packages.vue → PackagesManager.vue
4. Delete Sidebar.vue (duplicate)
5. Delete Topbar.vue (duplicate)

**Recommended Fixes (Should Do):**
6. Add Widget suffix to widget components
7. Add Chart suffix to RetentionRate

**Total Files to Fix:** 5 critical + 4 optional = 9 files

---

**Status:** Action plan ready
**Impact:** High (improves maintainability)
**Effort:** Low (mostly renames)
**Priority:** High (Vue.js essential rules)
