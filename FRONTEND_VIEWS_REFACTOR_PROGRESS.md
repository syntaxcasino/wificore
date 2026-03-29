# Tenant Views Refactoring Progress

## Summary
Total files to refactor: ~50+ Vue files in `/frontend/src/modules/tenant/views/`

## Already Refactored (6 files) ✓
1. `finance/ExpensesView.vue` - Has DataViewContainer + SlideOverlay
2. `finance/RevenuesView.vue` - Has DataViewContainer + SlideOverlay
3. `hr/EmployeesView.vue` - Has DataViewContainer + SlideOverlay
4. `hr/DepartmentsView.vue` - Has DataViewContainer + SlideOverlay
5. `hr/PositionsView.vue` - Has DataViewContainer + SlideOverlay
6. `todos/TodosView.vue` - Has DataViewContainer + SlideOverlay

## Dashboard Views Needing Refactoring

### PPPoE Module (2 files)
- [ ] `dashboard/pppoe/PPPoEUsers.vue` - Has SlideOverlay, needs DataViewContainer
- [ ] `dashboard/pppoe/PPPoESessions.vue` - Check and refactor

### Users Module (1 file)
- [ ] `dashboard/users/UserListNew.vue` - Uses old modal pattern, needs both

### Packages Module (1 file)
- [ ] `dashboard/packages/PackageGroupsNew.vue` - Has SlideOverlay, needs DataViewContainer

### Billing Module (4 files)
- [ ] `dashboard/billing/MpesaTransactionsNew.vue` - Has SlideOverlay, needs DataViewContainer
- [ ] `dashboard/billing/PaymentMethodsNew.vue` - Check and refactor
- [ ] `dashboard/billing/InvoicesNew.vue` - Check and refactor
- [ ] `dashboard/billing/PaymentsNew.vue` - Check and refactor

### Support Module (1 file)
- [ ] `dashboard/support/AllTicketsNew.vue` - Has SlideOverlay, needs DataViewContainer

### Routers Module (2 files)
- [ ] `dashboard/routers/RoutersView.vue` - Uses custom overlays, needs DataViewContainer
- [ ] `dashboard/routers/AccessPointsView.vue` - Check and refactor

### Admin Module (1 file)
- [ ] `dashboard/admin/RolesPermissionsNew.vue` - Check and refactor

### Settings Module (8 files)
- [ ] `dashboard/settings/GeneralSettingsNew.vue` - Check and refactor
- [ ] `dashboard/settings/CommunicationChannels.vue` - Check and refactor
- [ ] `dashboard/settings/MpesaApiKeysNew.vue` - Check and refactor
- [ ] `dashboard/settings/TimezoneLocaleNew.vue` - Check and refactor
- And 4 more...

### Monitoring Module (6 files)
- [ ] `dashboard/monitoring/LiveConnectionsNew.vue` - Check and refactor
- And 5 more...

### Hotspot Module (8 files)
- [ ] `dashboard/hotspot/HotspotUsers.vue` - Check and refactor
- [ ] `dashboard/hotspot/VouchersGenerateNew.vue` - Check and refactor
- And 6 more...

### Reports Module (6 files)
- All need checking

## Pattern Guide

### Standard Refactoring Template:
1. Wrap with `<DataViewContainer>`
2. Add icon slot
3. Add SlideOverlay for create/edit forms
4. Use DataSkeleton for loading
5. Use MobileDataCard for mobile view
6. Use DataPagination
7. Use DataEmptyState
8. Use EntityStatusBadge for status

### Import Pattern:
```javascript
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
```

## Status Key
- [ ] Not started
- [~] In progress
- [x] Complete
