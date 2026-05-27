# Base Components Library

This directory contains reusable base components that follow the router management UI pattern.

## Components

### BaseButton
Unified button component with multiple variants and sizes.

**Props:**
- `variant`: 'primary' | 'secondary' | 'danger' | 'ghost' | 'success' | 'warning'
- `size`: 'sm' | 'md' | 'lg'
- `loading`: boolean
- `disabled`: boolean
- `fullWidth`: boolean

**Usage:**
```vue
<BaseButton variant="primary" @click="handleClick">
  Click Me
</BaseButton>
```

### BaseCard
Card container with optional header and footer.

**Props:**
- `title`: string
- `subtitle`: string
- `padding`: boolean (default: true)
- `hoverable`: boolean

**Usage:**
```vue
<BaseCard title="Card Title" subtitle="Subtitle">
  <template #actions>
    <button>Action</button>
  </template>
  Card content here
</BaseCard>
```

### BaseBadge
Status badge component.

**Props:**
- `variant`: 'default' | 'success' | 'warning' | 'danger' | 'info' | 'purple' | 'pink'
- `size`: 'sm' | 'md' | 'lg'
- `dot`: boolean
- `pulse`: boolean

**Usage:**
```vue
<BaseBadge variant="success" dot pulse>Online</BaseBadge>
```

### BaseInput
Form input with validation states.

**Props:**
- `modelValue`: string | number
- `type`: string (default: 'text')
- `label`: string
- `placeholder`: string
- `error`: string
- `hint`: string
- `required`: boolean
- `disabled`: boolean

**Usage:**
```vue
<BaseInput
  v-model="form.name"
  label="Name"
  placeholder="Enter name"
  :error="errors.name"
/>
```

### BaseSelect
Dropdown select component.

**Props:**
- `modelValue`: string | number | boolean
- `label`: string
- `placeholder`: string
- `error`: string
- `required`: boolean
- `disabled`: boolean

**Usage:**
```vue
<BaseSelect v-model="form.status" label="Status">
  <option value="active">Active</option>
  <option value="inactive">Inactive</option>
</BaseSelect>
```

### BaseSearch
Search input with clear button.

**Props:**
- `modelValue`: string
- `placeholder`: string (default: 'Search...')
- `size`: 'sm' | 'md' | 'lg'

**Usage:**
```vue
<BaseSearch v-model="searchQuery" placeholder="Search users..." />
```

### BasePagination
Pagination controls with items per page selector.

**Props:**
- `modelValue`: number (current page)
- `totalPages`: number
- `itemsPerPage`: number
- `itemsPerPageOptions`: array
- `showItemsPerPage`: boolean

**Usage:**
```vue
<BasePagination
  v-model="currentPage"
  :total-pages="totalPages"
  :items-per-page="itemsPerPage"
  @update:items-per-page="itemsPerPage = $event"
/>
```

### BaseLoading
Loading states with multiple types.

**Props:**
- `type`: 'spinner' | 'skeleton' | 'table' | 'card' | 'dots'
- `size`: 'sm' | 'md' | 'lg' | 'xl'
- `text`: string
- `rows`: number (for skeleton/table types)

**Usage:**
```vue
<BaseLoading type="spinner" size="md" text="Loading..." />
<BaseLoading type="table" :rows="5" />
```

### BaseEmpty
Empty state component.

**Props:**
- `title`: string
- `description`: string
- `icon`: string (Lucide icon name)
- `actionText`: string
- `actionIcon`: string
- `size`: 'sm' | 'md' | 'lg'

**Usage:**
```vue
<BaseEmpty
  title="No Users Found"
  description="Get started by adding your first user"
  icon="Users"
  actionText="Add User"
  actionIcon="Plus"
  @action="openCreateModal"
/>
```

### BaseAlert
Alert/notification component.

**Props:**
- `variant`: 'success' | 'warning' | 'danger' | 'info'
- `title`: string
- `message`: string
- `dismissible`: boolean

**Usage:**
```vue
<BaseAlert
  variant="success"
  title="Success"
  message="User created successfully"
  dismissible
/>
```

### BaseModal
Modal/dialog component.

**Props:**
- `modelValue`: boolean
- `title`: string
- `size`: 'sm' | 'md' | 'lg' | 'xl' | 'full'
- `closable`: boolean (default: true)
- `closeOnBackdrop`: boolean (default: true)
- `noPadding`: boolean

**Usage:**
```vue
<BaseModal v-model="showModal" title="Create User" size="lg">
  Modal content here
  <template #footer>
    <BaseButton @click="showModal = false">Cancel</BaseButton>
    <BaseButton variant="primary" @click="save">Save</BaseButton>
  </template>
</BaseModal>
```

## Layout Templates

### PageHeader
Consistent page header with title, icon, breadcrumbs, and actions.

**Usage:**
```vue
<PageHeader
  title="User Management"
  subtitle="Manage system users"
  icon="Users"
  :breadcrumbs="[
    { label: 'Dashboard', to: '/dashboard' },
    { label: 'Users' }
  ]"
>
  <template #actions>
    <BaseButton>Add User</BaseButton>
  </template>
</PageHeader>
```

### PageContent
Main content wrapper with optional padding.

**Usage:**
```vue
<PageContent>
  Your content here
</PageContent>
```

### PageFooter
Footer wrapper for pagination and actions.

**Usage:**
```vue
<PageFooter>
  <BasePagination v-model="currentPage" :total-pages="totalPages" />
</PageFooter>
```

### PageContainer
Full page container with gradient background.

**Usage:**
```vue
<PageContainer>
  <PageHeader ... />
  <PageContent>...</PageContent>
  <PageFooter>...</PageFooter>
</PageContainer>
```

## Design Principles

1. **Consistency**: All components follow the router management UI pattern
2. **Flexibility**: Props and slots for customization
3. **Accessibility**: ARIA labels, keyboard navigation
4. **Performance**: Optimized for re-renders
5. **Type Safety**: Prop validation and defaults

## Color Palette

- **Primary**: Blue-600 to Indigo-600 gradients
- **Success**: Emerald-500 to Green-500
- **Warning**: Amber-500 to Yellow-500
- **Danger**: Red-500 to Rose-500
- **Neutral**: Slate scale (50-900)
