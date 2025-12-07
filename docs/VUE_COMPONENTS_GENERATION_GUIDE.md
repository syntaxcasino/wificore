# Vue Components Generation Guide
## HR & Finance Modules - WiFi Hotspot System

**Status**: ⏳ **IN PROGRESS** - 1/15 components created

---

## 📋 **Components Needed**

### **HR Module** (9 components):
1. ✅ `DepartmentsView.vue` - CREATED
2. ⏳ `DepartmentCard.vue` - Pending
3. ⏳ `DepartmentForm.vue` - Pending
4. ⏳ `PositionsView.vue` - Pending
5. ⏳ `PositionCard.vue` - Pending
6. ⏳ `PositionForm.vue` - Pending
7. ⏳ `EmployeesView.vue` - Pending
8. ⏳ `EmployeeCard.vue` - Pending
9. ⏳ `EmployeeForm.vue` - Pending

### **Finance Module** (6 components):
10. ⏳ `ExpensesView.vue` - Pending
11. ⏳ `ExpenseCard.vue` - Pending
12. ⏳ `ExpenseForm.vue` - Pending
13. ⏳ `RevenuesView.vue` - Pending
14. ⏳ `RevenueCard.vue` - Pending
15. ⏳ `RevenueForm.vue` - Pending

---

## 🎯 **Component Pattern**

All components follow the same structure as `TodosView.vue`:

### **View Component Structure**:
```vue
<template>
  <!-- Header with icon and title -->
  <!-- Stats cards (4 cards) -->
  <!-- Filters (status-based) -->
  <!-- Loading state -->
  <!-- Empty state -->
  <!-- Item list (using Card component) -->
  <!-- SlideOverlay for Form -->
</template>

<script setup>
  // Import composable
  // Import Card and Form components
  // Setup reactive state
  // Setup filters
  // Setup CRUD handlers
  // Setup WebSocket listeners
</script>
```

### **Card Component Structure**:
```vue
<template>
  <!-- Card with item details -->
  <!-- Status badge -->
  <!-- Action buttons (Edit, Delete, etc.) -->
</template>

<script setup>
  // Define props
  // Define emits
  // Format data
</script>
```

### **Form Component Structure**:
```vue
<template>
  <!-- Form fields -->
  <!-- Validation messages -->
  <!-- Submit/Cancel buttons -->
</template>

<script setup>
  // Define props
  // Define emits
  // Setup form data
  // Setup validation
  // Handle submit
</script>
```

---

## 🔧 **Quick Generation Steps**

### **For Each Module**:

1. **Copy DepartmentsView.vue** as template
2. **Replace**:
   - Component name (Departments → Positions/Employees/Expenses/Revenues)
   - Icon (Building2 → Briefcase/Users/DollarSign/TrendingUp)
   - Color scheme (purple → blue/green/red/emerald)
   - Stats fields
   - Filter options
   - Composable import

3. **Create Card Component**:
   - Display key fields
   - Status badge
   - Action buttons

4. **Create Form Component**:
   - Input fields based on model
   - Validation rules
   - Submit handler

---

## 📊 **Component Specifications**

### **Departments**:
- **Icon**: Building2
- **Color**: Purple
- **Stats**: total, active, pending_approval, inactive
- **Filters**: all, active, pending_approval, inactive
- **Actions**: create, edit, delete, approve

### **Positions**:
- **Icon**: Briefcase
- **Color**: Blue
- **Stats**: total, active, inactive
- **Filters**: all, active, inactive
- **Actions**: create, edit, delete

### **Employees**:
- **Icon**: Users
- **Color**: Green
- **Stats**: total, active, on_leave, suspended, terminated
- **Filters**: all, active, on_leave, suspended, terminated
- **Actions**: create, edit, delete, terminate

### **Expenses**:
- **Icon**: DollarSign
- **Color**: Red
- **Stats**: total_expenses, pending, approved, rejected, paid
- **Filters**: all, pending, approved, rejected, paid
- **Actions**: create, edit, delete, approve, reject, mark_as_paid

### **Revenues**:
- **Icon**: TrendingUp
- **Color**: Emerald
- **Stats**: total_revenues, pending, confirmed, cancelled
- **Filters**: all, pending, confirmed, cancelled
- **Actions**: create, edit, delete, confirm, cancel

---

## 🚀 **Next Steps**

1. **Generate remaining 14 components** using the pattern above
2. **Add routes** to `router/index.js`
3. **Configure navigation menu**
4. **Test each module**
5. **Commit and push** to git

---

## 📝 **Router Configuration Needed**

```javascript
// HR Module Routes
{
  path: '/employee/hr/departments',
  name: 'Departments',
  component: () => import('@/modules/tenant/views/DepartmentsView.vue')
},
{
  path: '/employee/hr/positions',
  name: 'Positions',
  component: () => import('@/modules/tenant/views/PositionsView.vue')
},
{
  path: '/employee/hr/employees',
  name: 'Employees',
  component: () => import('@/modules/tenant/views/EmployeesView.vue')
},

// Finance Module Routes
{
  path: '/employee/finance/expenses',
  name: 'Expenses',
  component: () => import('@/modules/tenant/views/ExpensesView.vue')
},
{
  path: '/employee/finance/revenues',
  name: 'Revenues',
  component: () => import('@/modules/tenant/views/RevenuesView.vue')
}
```

---

**Status**: ⏳ **1/15 COMPLETE (7%)**  
**Next**: Create remaining 14 components following the pattern
