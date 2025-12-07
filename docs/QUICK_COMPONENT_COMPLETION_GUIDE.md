# Quick Component Completion Guide
## Create All 14 Remaining Components in 30 Minutes

**Status**: Ready to execute  
**Time Required**: 30-45 minutes  
**Difficulty**: Easy (copy-paste with find-replace)

---

## 🎯 **Strategy**

Use the **TodoCard.vue** and **TodoForm.vue** as templates. Simply:
1. Copy the template file
2. Find & Replace key terms
3. Adjust fields specific to each module
4. Save and test

---

## 📋 **Quick Reference Table**

| Component | Template | Icon | Color | Key Fields |
|-----------|----------|------|-------|------------|
| **DepartmentCard** | TodoCard | Building2 | purple | name, code, status, employee_count |
| **DepartmentForm** | TodoForm | Building2 | purple | name, code, description, location, budget |
| **PositionCard** | TodoCard | Briefcase | blue | title, code, level, min_salary, max_salary |
| **PositionForm** | TodoForm | Briefcase | blue | title, code, description, level, salary range |
| **EmployeeCard** | TodoCard | Users | green | full_name, employee_number, department, position |
| **EmployeeForm** | TodoForm | Users | green | first_name, last_name, email, hire_date, employment_type |
| **ExpenseCard** | TodoCard | DollarSign | red | expense_number, category, amount, status, vendor |
| **ExpenseForm** | TodoForm | DollarSign | red | category, amount, expense_date, vendor_name, description |
| **RevenueCard** | TodoCard | TrendingUp | emerald | revenue_number, source, amount, status |
| **RevenueForm** | TodoForm | TrendingUp | emerald | source, amount, revenue_date, reference_number, description |

---

## 🚀 **Step-by-Step Instructions**

### **Step 1: Create DepartmentCard.vue** (2 minutes)

```bash
# Copy template
cp frontend/src/modules/tenant/components/TodoCard.vue frontend/src/modules/tenant/components/DepartmentCard.vue
```

**Find & Replace in DepartmentCard.vue**:
- `todo` → `department`
- `Todo` → `Department`
- `CheckSquare` → `Building2`
- `blue` → `purple`

**Update display fields** (around line 10-30):
```vue
<h3 class="text-lg font-bold text-gray-900">{{ department.name }}</h3>
<p class="text-sm text-gray-600">{{ department.code }}</p>
<span class="px-2 py-1 text-xs font-semibold rounded-full"
  :class="{
    'bg-green-100 text-green-800': department.status === 'active',
    'bg-orange-100 text-orange-800': department.status === 'pending_approval',
    'bg-gray-100 text-gray-800': department.status === 'inactive'
  }">
  {{ department.status }}
</span>
<p class="text-sm text-gray-600">{{ department.employee_count }} employees</p>
```

---

### **Step 2: Create DepartmentForm.vue** (3 minutes)

```bash
# Copy template
cp frontend/src/modules/tenant/components/TodoForm.vue frontend/src/modules/tenant/components/DepartmentForm.vue
```

**Find & Replace in DepartmentForm.vue**:
- `todo` → `department`
- `Todo` → `Department`

**Update form fields** (around line 20-80):
```vue
<!-- Name -->
<div>
  <label class="block text-sm font-medium text-gray-700 mb-2">Department Name *</label>
  <input v-model="formData.name" type="text" required
    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" />
</div>

<!-- Code -->
<div>
  <label class="block text-sm font-medium text-gray-700 mb-2">Department Code *</label>
  <input v-model="formData.code" type="text" required
    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" />
</div>

<!-- Description -->
<div>
  <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
  <textarea v-model="formData.description" rows="3"
    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"></textarea>
</div>

<!-- Location -->
<div>
  <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
  <input v-model="formData.location" type="text"
    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" />
</div>

<!-- Budget -->
<div>
  <label class="block text-sm font-medium text-gray-700 mb-2">Budget</label>
  <input v-model="formData.budget" type="number" step="0.01"
    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" />
</div>
```

---

### **Step 3: Repeat for All Components** (25 minutes)

Use the same pattern for each component:

#### **Positions** (Blue theme):
- **PositionsView.vue**: Copy DepartmentsView.vue, replace Department → Position, purple → blue, Building2 → Briefcase
- **PositionCard.vue**: Show title, code, level, salary range
- **PositionForm.vue**: Fields: title, code, description, level, min_salary, max_salary

#### **Employees** (Green theme):
- **EmployeesView.vue**: Copy DepartmentsView.vue, replace Department → Employee, purple → green, Building2 → Users
- **EmployeeCard.vue**: Show full_name, employee_number, department, position, employment_status
- **EmployeeForm.vue**: Fields: first_name, last_name, email, phone, hire_date, employment_type

#### **Expenses** (Red theme):
- **ExpensesView.vue**: Copy DepartmentsView.vue, replace Department → Expense, purple → red, Building2 → DollarSign
- **ExpenseCard.vue**: Show expense_number, category, amount, status, vendor_name
- **ExpenseForm.vue**: Fields: category, description, amount, expense_date, vendor_name

#### **Revenues** (Emerald theme):
- **RevenuesView.vue**: Copy DepartmentsView.vue, replace Department → Revenue, purple → emerald, Building2 → TrendingUp
- **RevenueCard.vue**: Show revenue_number, source, amount, status
- **RevenueForm.vue**: Fields: source, description, amount, revenue_date, reference_number

---

## 📝 **Router Configuration** (5 minutes)

Add to `frontend/src/router/index.js`:

```javascript
// HR Module Routes
{
  path: '/employee/hr',
  meta: { requiresAuth: true, roles: ['admin', 'employee'] },
  children: [
    {
      path: 'departments',
      name: 'Departments',
      component: () => import('@/modules/tenant/views/DepartmentsView.vue')
    },
    {
      path: 'positions',
      name: 'Positions',
      component: () => import('@/modules/tenant/views/PositionsView.vue')
    },
    {
      path: 'employees',
      name: 'Employees',
      component: () => import('@/modules/tenant/views/EmployeesView.vue')
    }
  ]
},

// Finance Module Routes
{
  path: '/employee/finance',
  meta: { requiresAuth: true, roles: ['admin', 'employee'] },
  children: [
    {
      path: 'expenses',
      name: 'Expenses',
      component: () => import('@/modules/tenant/views/ExpensesView.vue')
    },
    {
      path: 'revenues',
      name: 'Revenues',
      component: () => import('@/modules/tenant/views/RevenuesView.vue')
    }
  ]
}
```

---

## 🎨 **Color Scheme Reference**

```javascript
// Department (Purple)
from-purple-600 to-indigo-600
bg-purple-100 text-purple-700
border-purple-500

// Position (Blue)
from-blue-600 to-indigo-600
bg-blue-100 text-blue-700
border-blue-500

// Employee (Green)
from-green-600 to-emerald-600
bg-green-100 text-green-700
border-green-500

// Expense (Red)
from-red-600 to-rose-600
bg-red-100 text-red-700
border-red-500

// Revenue (Emerald)
from-emerald-600 to-teal-600
bg-emerald-100 text-emerald-700
border-emerald-500
```

---

## ✅ **Verification Checklist**

After creating each component:

- [ ] Component imports correct composable
- [ ] Icon is correct (Building2, Briefcase, Users, DollarSign, TrendingUp)
- [ ] Color scheme matches module
- [ ] Form fields match model
- [ ] Props and emits are correct
- [ ] WebSocket listeners setup in View
- [ ] No console errors

---

## 🚀 **Quick Test Commands**

```bash
# Start frontend dev server
cd frontend
npm run dev

# Test each route:
# http://localhost:5173/employee/hr/departments
# http://localhost:5173/employee/hr/positions
# http://localhost:5173/employee/hr/employees
# http://localhost:5173/employee/finance/expenses
# http://localhost:5173/employee/finance/revenues
```

---

## 📊 **Progress Tracker**

```
HR Module:
[ ] DepartmentCard.vue
[ ] DepartmentForm.vue
[ ] PositionsView.vue
[ ] PositionCard.vue
[ ] PositionForm.vue
[ ] EmployeesView.vue
[ ] EmployeeCard.vue
[ ] EmployeeForm.vue

Finance Module:
[ ] ExpensesView.vue
[ ] ExpenseCard.vue
[ ] ExpenseForm.vue
[ ] RevenuesView.vue
[ ] RevenueCard.vue
[ ] RevenueForm.vue

Configuration:
[ ] Router configured
[ ] Navigation menu updated
[ ] All routes tested
```

---

## 🎯 **Final Steps**

1. **Create all 14 components** (30 minutes)
2. **Configure router** (5 minutes)
3. **Test each module** (10 minutes)
4. **Commit and push** (2 minutes)

**Total Time**: ~45 minutes to 100% completion

---

**Status**: ⏳ **READY TO EXECUTE**  
**Estimated Completion**: 45 minutes  
**Final Progress**: 88% → 100%
