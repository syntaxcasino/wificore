# Frontend Naming Conventions - Best Practices

## 📋 Current State Analysis

### Issues Found:
1. ❌ Inconsistent component naming (some PascalCase, some not)
2. ❌ Mixed file naming patterns
3. ❌ Some files not following Vue style guide
4. ❌ Composables naming inconsistency

## ✅ Standard Naming Conventions (Vue.js Official Style Guide)

### 1. Components (`.vue` files)

#### Rule: **PascalCase** for multi-word components
```
✅ CORRECT:
- AppHeader.vue
- UserProfile.vue
- PackageCard.vue
- PaymentModal.vue
- RouterList.vue

❌ WRONG:
- appHeader.vue
- userprofile.vue
- package-card.vue
- payment_modal.vue
```

#### Single-Word Components (Avoid)
```
❌ AVOID:
- Header.vue (too generic)
- Card.vue (too generic)

✅ BETTER:
- AppHeader.vue
- PackageCard.vue
```

### 2. Views/Pages (`.vue` files)

#### Rule: **PascalCase** with descriptive suffix
```
✅ CORRECT:
- LoginView.vue
- DashboardView.vue
- PackagesView.vue
- RoutersView.vue
- UserListView.vue

✅ ALTERNATIVE (also acceptable):
- LoginPage.vue
- DashboardPage.vue
```

### 3. Composables (`.js` files)

#### Rule: **camelCase** starting with `use`
```
✅ CORRECT:
- useAuth.js
- usePackages.js
- useDashboard.js
- useRouterUtils.js
- usePaymentWebSocket.js

❌ WRONG:
- Auth.js
- packages.js
- router-utils.js
```

### 4. Utilities (`.js` files)

#### Rule: **camelCase** for functions, **PascalCase** for classes
```
✅ CORRECT:
- formatDate.js
- validateEmail.js
- apiClient.js
- HttpService.js (class)

❌ WRONG:
- format-date.js
- ValidateEmail.js (unless it's a class)
```

### 5. Stores (Pinia) (`.js` files)

#### Rule: **camelCase** with descriptive name
```
✅ CORRECT:
- auth.js (exports useAuthStore)
- dashboard.js (exports useDashboardStore)
- theme.js (exports useThemeStore)

❌ WRONG:
- authStore.js
- Auth.js
```

### 6. Directories

#### Rule: **kebab-case** (lowercase with hyphens)
```
✅ CORRECT:
- components/
- composables/
- views/
- router/
- stores/
- assets/
- utils/

❌ WRONG:
- Components/
- Composables/
- myComponents/
```

### 7. Component Organization

#### Base Components (Reusable)
```
✅ PREFIX with "Base", "App", or "V":
- BaseButton.vue
- BaseInput.vue
- AppHeader.vue
- AppFooter.vue
- VButton.vue (if using V prefix)
```

#### Single-Instance Components
```
✅ PREFIX with "The":
- TheHeader.vue
- TheSidebar.vue
- TheFooter.vue
```

#### Tightly Coupled Components
```
✅ PREFIX with parent name:
- TodoList.vue
  - TodoListItem.vue
  - TodoListItemButton.vue

- PackageCard.vue
  - PackageCardHeader.vue
  - PackageCardFooter.vue
```

## 📁 Recommended File Structure

```
frontend/src/
├── components/
│   ├── base/              # Base reusable components
│   │   ├── BaseButton.vue
│   │   ├── BaseInput.vue
│   │   └── BaseModal.vue
│   │
│   ├── common/            # Common shared components
│   │   ├── AppHeader.vue
│   │   ├── AppFooter.vue
│   │   └── LoadingSpinner.vue
│   │
│   ├── dashboard/         # Feature-specific components
│   │   ├── cards/
│   │   │   └── StatsCard.vue
│   │   ├── charts/
│   │   │   ├── ActiveUsersChart.vue
│   │   │   └── PaymentsChart.vue
│   │   └── widgets/
│   │       └── DataUsageWidget.vue
│   │
│   └── packages/
│       ├── PackageCard.vue
│       ├── PackageList.vue
│       └── PackageSelector.vue
│
├── composables/
│   ├── auth/
│   │   └── useAuth.js
│   ├── data/
│   │   ├── useDashboard.js
│   │   ├── usePackages.js
│   │   └── useRouters.js
│   └── utils/
│       ├── useTheme.js
│       └── useDebounce.js
│
├── views/
│   ├── auth/
│   │   ├── LoginView.vue
│   │   └── RegisterView.vue
│   ├── dashboard/
│   │   ├── DashboardView.vue
│   │   └── routers/
│   │       └── RoutersView.vue
│   └── public/
│       ├── HomeView.vue
│       └── PackagesView.vue
│
├── stores/
│   ├── auth.js           # exports useAuthStore
│   ├── dashboard.js      # exports useDashboardStore
│   └── theme.js          # exports useThemeStore
│
├── router/
│   └── index.js
│
└── utils/
    ├── formatters.js
    ├── validators.js
    └── constants.js
```

## 🔄 Migration Plan for Current Files

### Components to Rename:

#### Current Issues:
```
❌ components/Sidebar.vue → ✅ AppSidebar.vue (already correct)
❌ components/AppHeader.vue → ✅ (already correct)
❌ components/PackageSelector.vue → ✅ (already correct)
```

### Views to Standardize:

#### Current:
```
views/Dashboard.vue → DashboardView.vue (optional, for consistency)
views/public/HomeView.vue → ✅ (already correct)
views/public/PackagesView.vue → ✅ (already correct)
views/auth/LoginView.vue → ✅ (already correct)
```

### Composables:
```
✅ All composables already follow convention:
- useAuth.js
- useDashboard.js
- usePackages.js
- useRouters.js
```

## 📝 Naming Patterns by Type

### 1. Modal/Dialog Components
```
✅ Suffix with "Modal" or "Dialog":
- PaymentModal.vue
- ConfirmDialog.vue
- UserEditModal.vue
```

### 2. Form Components
```
✅ Suffix with "Form":
- LoginForm.vue
- UserForm.vue
- PackageForm.vue
```

### 3. List Components
```
✅ Suffix with "List":
- RouterList.vue
- UserList.vue
- PackageList.vue
```

### 4. Card Components
```
✅ Suffix with "Card":
- StatsCard.vue
- PackageCard.vue
- UserCard.vue
```

### 5. Layout Components
```
✅ Prefix with "App" or "The":
- AppLayout.vue
- AppSidebar.vue
- TheHeader.vue
- TheFooter.vue
```

## 🎯 Best Practices Summary

### DO:
✅ Use PascalCase for components
✅ Use camelCase for composables (start with `use`)
✅ Use kebab-case for directories
✅ Be descriptive and specific
✅ Use consistent suffixes (Modal, Form, List, Card)
✅ Group related components
✅ Use barrel exports (index.js)

### DON'T:
❌ Use single-word component names
❌ Mix naming conventions
❌ Use abbreviations (unless very common)
❌ Use generic names (Button.vue, Card.vue)
❌ Use snake_case or kebab-case for components
❌ Use PascalCase for composables

## 📚 Vue.js Official Style Guide Priority Levels

### Priority A (Essential - Error Prevention)
1. **Multi-word component names** - Always use multi-word
2. **Component data** - Must be a function
3. **Prop definitions** - Should be detailed
4. **Keyed v-for** - Always use :key

### Priority B (Strongly Recommended)
1. **Component files** - One component per file
2. **Single-file component filename casing** - PascalCase
3. **Base component names** - Prefix with Base/App/V
4. **Tightly coupled component names** - Prefix with parent

### Priority C (Recommended)
1. **Component/instance options order** - Consistent order
2. **Element attribute order** - Consistent order
3. **Empty lines in component/instance options** - Improve readability

## 🔧 Tools for Enforcement

### ESLint Rules:
```javascript
// .eslintrc.js
{
  "extends": [
    "plugin:vue/vue3-strongly-recommended"
  ],
  "rules": {
    "vue/multi-word-component-names": "error",
    "vue/component-name-in-template-casing": ["error", "PascalCase"],
    "vue/filename-case": ["error", "PascalCase"]
  }
}
```

### VS Code Settings:
```json
{
  "files.associations": {
    "*.vue": "vue"
  },
  "editor.codeActionsOnSave": {
    "source.fixAll.eslint": true
  }
}
```

## ✅ Quick Reference

| Type | Convention | Example |
|------|-----------|---------|
| Components | PascalCase | `UserProfile.vue` |
| Views | PascalCase + View | `DashboardView.vue` |
| Composables | camelCase + use | `useAuth.js` |
| Stores | camelCase | `auth.js` |
| Utils | camelCase | `formatDate.js` |
| Directories | kebab-case | `components/` |
| Constants | UPPER_SNAKE_CASE | `API_BASE_URL` |
| Variables | camelCase | `userName` |
| Functions | camelCase | `getUserData()` |
| Classes | PascalCase | `HttpService` |

## 📖 Resources

- [Vue.js Style Guide](https://vuejs.org/style-guide/)
- [Airbnb JavaScript Style Guide](https://github.com/airbnb/javascript)
- [Vue.js Best Practices](https://vuejs.org/guide/best-practices/)

---

**Status:** Ready for implementation  
**Priority:** High (for maintainability)  
**Impact:** Improves code consistency and readability
