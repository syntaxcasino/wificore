# Frontend Implementation Guide
## Step-by-Step Implementation for World-Class UI/UX

---

## 🎯 Phase 1: Base Components (Start Here)

### Step 1: Create Base Component Structure

```bash
# Create directory structure
mkdir -p frontend/src/components/base
mkdir -p frontend/src/components/layout/templates
mkdir -p frontend/src/composables/utils
mkdir -p frontend/src/design-system
```

### Step 2: BaseButton Component

**File:** `frontend/src/components/base/BaseButton.vue`

```vue
<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="buttonClasses"
    @click="handleClick"
  >
    <span v-if="loading" class="mr-2">
      <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </span>
    <slot />
  </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'secondary', 'danger', 'ghost', 'success'].includes(value)
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  },
  type: {
    type: String,
    default: 'button'
  },
  disabled: Boolean,
  loading: Boolean,
  fullWidth: Boolean
})

const emit = defineEmits(['click'])

const buttonClasses = computed(() => {
  const base = 'inline-flex items-center justify-center font-medium rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2'
  
  const variants = {
    primary: 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white hover:from-blue-700 hover:to-indigo-700 focus:ring-blue-500 shadow-md hover:shadow-lg',
    secondary: 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 focus:ring-slate-500',
    danger: 'bg-gradient-to-r from-red-600 to-rose-600 text-white hover:from-red-700 hover:to-rose-700 focus:ring-red-500 shadow-md hover:shadow-lg',
    ghost: 'text-slate-700 hover:bg-slate-100 focus:ring-slate-500',
    success: 'bg-gradient-to-r from-emerald-600 to-green-600 text-white hover:from-emerald-700 hover:to-green-700 focus:ring-emerald-500 shadow-md hover:shadow-lg'
  }
  
  const sizes = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
    lg: 'px-6 py-3 text-base'
  }
  
  const disabled = props.disabled || props.loading ? 'opacity-50 cursor-not-allowed' : ''
  const width = props.fullWidth ? 'w-full' : ''
  
  return [base, variants[props.variant], sizes[props.size], disabled, width].join(' ')
})

const handleClick = (event) => {
  if (!props.disabled && !props.loading) {
    emit('click', event)
  }
}
</script>
```

### Step 3: BaseCard Component

**File:** `frontend/src/components/base/BaseCard.vue`

```vue
<template>
  <div :class="cardClasses">
    <div v-if="$slots.header || title" class="px-6 py-4 border-b border-slate-200">
      <slot name="header">
        <h3 class="text-lg font-semibold text-slate-900">{{ title }}</h3>
        <p v-if="subtitle" class="text-sm text-slate-600 mt-1">{{ subtitle }}</p>
      </slot>
    </div>
    
    <div :class="contentClasses">
      <slot />
    </div>
    
    <div v-if="$slots.footer" class="px-6 py-4 border-t border-slate-200 bg-slate-50">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: String,
  subtitle: String,
  padding: {
    type: Boolean,
    default: true
  },
  hoverable: Boolean
})

const cardClasses = computed(() => {
  const base = 'bg-white rounded-xl border border-slate-200 shadow-sm'
  const hover = props.hoverable ? 'hover:shadow-lg transition-shadow duration-300' : ''
  return [base, hover].join(' ')
})

const contentClasses = computed(() => {
  return props.padding ? 'px-6 py-4' : ''
})
</script>
```

### Step 4: BaseBadge Component

**File:** `frontend/src/components/base/BaseBadge.vue`

```vue
<template>
  <span :class="badgeClasses">
    <slot />
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'success', 'warning', 'danger', 'info'].includes(value)
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  }
})

const badgeClasses = computed(() => {
  const base = 'inline-flex items-center font-medium rounded-md'
  
  const variants = {
    default: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-100 text-emerald-700',
    warning: 'bg-amber-100 text-amber-700',
    danger: 'bg-red-100 text-red-700',
    info: 'bg-blue-100 text-blue-700'
  }
  
  const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-sm',
    lg: 'px-3 py-1.5 text-base'
  }
  
  return [base, variants[props.variant], sizes[props.size]].join(' ')
})
</script>
```

### Step 5: BaseInput Component

**File:** `frontend/src/components/base/BaseInput.vue`

```vue
<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="block text-sm font-medium text-slate-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>
    
    <div class="relative">
      <input
        :id="id"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        :class="inputClasses"
        @input="handleInput"
        @blur="handleBlur"
      />
      
      <div v-if="$slots.icon" class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <slot name="icon" />
      </div>
    </div>
    
    <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-sm text-slate-500">{{ hint }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  id: String,
  modelValue: [String, Number],
  type: {
    type: String,
    default: 'text'
  },
  label: String,
  placeholder: String,
  error: String,
  hint: String,
  required: Boolean,
  disabled: Boolean
})

const emit = defineEmits(['update:modelValue', 'blur'])

const inputClasses = computed(() => {
  const base = 'block w-full rounded-lg border transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-0'
  const padding = props.$slots?.icon ? 'pl-10 pr-3 py-2' : 'px-3 py-2'
  const state = props.error
    ? 'border-red-300 text-red-900 focus:ring-red-500 focus:border-red-500'
    : 'border-slate-300 text-slate-900 focus:ring-blue-500 focus:border-blue-500'
  const disabled = props.disabled ? 'bg-slate-100 cursor-not-allowed' : 'bg-white'
  
  return [base, padding, state, disabled, 'text-sm'].join(' ')
})

const handleInput = (event) => {
  emit('update:modelValue', event.target.value)
}

const handleBlur = (event) => {
  emit('blur', event)
}
</script>
```

---

## 🎨 Phase 2: Layout Templates

### PageHeader Component

**File:** `frontend/src/components/layout/templates/PageHeader.vue`

```vue
<template>
  <div class="bg-white border-b border-slate-200 shadow-sm">
    <div class="px-6 py-5">
      <div class="flex items-center justify-between gap-6">
        <!-- Left: Title & Icon -->
        <div class="flex items-center gap-3">
          <div v-if="icon" class="w-11 h-11 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
            <component :is="iconComponent" class="h-6 w-6 text-white" />
          </div>
          <div>
            <h2 class="text-xl font-bold text-slate-900">{{ title }}</h2>
            <p v-if="subtitle" class="text-xs text-slate-500 mt-0.5">{{ subtitle }}</p>
          </div>
        </div>
        
        <!-- Right: Actions -->
        <div class="flex items-center gap-3">
          <slot name="actions" />
        </div>
      </div>
      
      <!-- Breadcrumbs -->
      <div v-if="breadcrumbs && breadcrumbs.length" class="mt-3">
        <nav class="flex" aria-label="Breadcrumb">
          <ol class="flex items-center space-x-2">
            <li v-for="(crumb, index) in breadcrumbs" :key="index" class="flex items-center">
              <router-link
                v-if="crumb.to"
                :to="crumb.to"
                class="text-sm text-slate-600 hover:text-slate-900"
              >
                {{ crumb.label }}
              </router-link>
              <span v-else class="text-sm text-slate-900 font-medium">{{ crumb.label }}</span>
              <svg
                v-if="index < breadcrumbs.length - 1"
                class="w-4 h-4 text-slate-400 mx-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </li>
          </ol>
        </nav>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import * as LucideIcons from 'lucide-vue-next'

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  subtitle: String,
  icon: String,
  breadcrumbs: Array
})

const iconComponent = computed(() => {
  return props.icon ? LucideIcons[props.icon] : null
})
</script>
```

---

## 📊 Phase 3: Example Module Implementation

### Users Module - UserList.vue

**File:** `frontend/src/views/dashboard/users/UserListNew.vue`

```vue
<template>
  <div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg">
    <!-- Header -->
    <PageHeader
      title="User Management"
      subtitle="Manage hotspot and PPPoE users"
      icon="Users"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseSearch v-model="searchQuery" placeholder="Search users..." />
        <BaseButton @click="openCreateModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add User
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Filters -->
    <div class="px-6 py-3 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3">
        <select v-model="filters.status" class="text-sm border border-slate-300 rounded-lg px-3 py-2">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="blocked">Blocked</option>
        </select>
        
        <select v-model="filters.package" class="text-sm border border-slate-300 rounded-lg px-3 py-2">
          <option value="">All Packages</option>
          <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
        </select>
        
        <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
          Clear Filters
        </BaseButton>
      </div>
    </div>

    <!-- Content -->
    <div class="flex-1 min-h-0 overflow-y-auto">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <LoadingSkeleton type="table" :rows="5" />
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="flex flex-col items-center justify-center p-12">
        <AlertCircle class="w-16 h-16 text-red-500 mb-4" />
        <h3 class="text-lg font-semibold text-slate-900 mb-2">Error Loading Users</h3>
        <p class="text-slate-600 mb-4">{{ error }}</p>
        <BaseButton @click="fetchUsers" variant="primary">Retry</BaseButton>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredUsers.length" class="flex flex-col items-center justify-center p-12">
        <Users class="w-16 h-16 text-slate-400 mb-4" />
        <h3 class="text-lg font-semibold text-slate-900 mb-2">No Users Found</h3>
        <p class="text-slate-600 mb-4">
          {{ searchQuery ? 'No users match your search criteria' : 'Get started by adding your first user' }}
        </p>
        <BaseButton @click="openCreateModal" variant="primary">Add User</BaseButton>
      </div>

      <!-- Data Table -->
      <div v-else class="px-6 pt-6">
        <BaseCard :padding="false">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Name</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Email</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Created</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="user in paginatedUsers"
                :key="user.id"
                class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                @click="openUserDetails(user)"
              >
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                      {{ user.name.charAt(0).toUpperCase() }}
                    </div>
                    <span class="text-sm font-medium text-slate-900">{{ user.name }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ user.email }}</td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ user.package?.name || 'N/A' }}</td>
                <td class="px-6 py-4">
                  <BaseBadge :variant="getStatusVariant(user.status)">{{ user.status }}</BaseBadge>
                </td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDate(user.created_at) }}</td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <BaseButton @click="editUser(user)" variant="ghost" size="sm">Edit</BaseButton>
                  <BaseButton @click="deleteUser(user)" variant="ghost" size="sm" class="text-red-600">Delete</BaseButton>
                </td>
              </tr>
            </tbody>
          </table>
        </BaseCard>
      </div>
    </div>

    <!-- Footer -->
    <div class="px-6 py-3 border-t border-slate-200 bg-white/95">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-600">
          Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} users
        </div>
        <BasePagination
          v-model="currentPage"
          :total-pages="totalPages"
          :items-per-page="itemsPerPage"
          @update:items-per-page="itemsPerPage = $event"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Plus, Users, AlertCircle } from 'lucide-vue-next'
import PageHeader from '@/components/layout/templates/PageHeader.vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseBadge from '@/components/base/BaseBadge.vue'
import BaseSearch from '@/components/base/BaseSearch.vue'
import BasePagination from '@/components/base/BasePagination.vue'
import LoadingSkeleton from '@/components/base/LoadingSkeleton.vue'

// Composables
import { useUsers } from '@/composables/data/useUsers'

const { users, loading, error, fetchUsers } = useUsers()

// State
const searchQuery = ref('')
const filters = ref({ status: '', package: '' })
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Computed
const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Users' }
])

const filteredUsers = computed(() => {
  let filtered = users.value
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(user =>
      user.name.toLowerCase().includes(query) ||
      user.email.toLowerCase().includes(query)
    )
  }
  
  if (filters.value.status) {
    filtered = filtered.filter(user => user.status === filters.value.status)
  }
  
  if (filters.value.package) {
    filtered = filtered.filter(user => user.package_id === filters.value.package)
  }
  
  return filtered
})

const paginatedUsers = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredUsers.value.slice(start, end)
})

const totalPages = computed(() => {
  return Math.ceil(filteredUsers.value.length / itemsPerPage.value)
})

const paginationInfo = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value + 1
  const end = Math.min(start + itemsPerPage.value - 1, filteredUsers.value.length)
  return { start, end, total: filteredUsers.value.length }
})

// Methods
const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning',
    blocked: 'danger'
  }
  return variants[status] || 'default'
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString()
}

// Lifecycle
onMounted(() => {
  fetchUsers()
})
</script>
```

---

## 🚀 Implementation Checklist

### Week 1-2: Foundation
- [ ] Create base components directory structure
- [ ] Implement BaseButton
- [ ] Implement BaseCard
- [ ] Implement BaseBadge
- [ ] Implement BaseInput
- [ ] Implement BaseSelect
- [ ] Implement BaseModal
- [ ] Implement BaseAlert
- [ ] Implement BaseLoading
- [ ] Implement BaseEmpty
- [ ] Implement BasePagination
- [ ] Implement BaseSearch
- [ ] Create PageHeader template
- [ ] Create PageContent template
- [ ] Create PageFooter template

### Week 3: Users Module
- [ ] Implement UserListNew.vue
- [ ] Implement CreateUserModal.vue
- [ ] Implement UserDetailsModal.vue
- [ ] Implement OnlineUsersNew.vue
- [ ] Test and validate

### Week 4-6: Continue with other modules
- [ ] Follow same pattern for all modules
- [ ] Maintain consistency
- [ ] Test each module

---

## 📝 Best Practices

1. **Always use base components** - Don't create custom buttons/inputs
2. **Follow the router management pattern** - Consistent UI across all modules
3. **Use composables** - Keep logic separate from UI
4. **Test as you go** - Don't wait until the end
5. **Mobile first** - Design for mobile, enhance for desktop
6. **Accessibility** - Add ARIA labels, keyboard navigation
7. **Performance** - Lazy load, code split, optimize

---

**Ready to start implementation!**
