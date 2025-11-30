<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Admin Users"
      subtitle="Manage system administrators and staff accounts"
      icon="Shield"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="openCreateModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Admin
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Search and Filters Bar -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search admin users..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.role" placeholder="All Roles" class="w-36">
            <option value="">All Roles</option>
            <option value="super_admin">Super Admin</option>
            <option value="admin">Admin</option>
            <option value="staff">Staff</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Stats Badges -->
        <div class="ml-auto flex items-center gap-2">
          <BaseBadge variant="info">{{ totalUsers }} Total</BaseBadge>
          <BaseBadge variant="success" dot pulse>{{ activeUsers.length }} Active</BaseBadge>
          <BaseBadge variant="warning">{{ inactiveUsers.length }} Inactive</BaseBadge>
        </div>
      </div>
    </div>

    <!-- Content -->
    <PageContent :padding="false">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="5" />
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="p-6">
        <BaseAlert variant="danger" :title="error" dismissible>
          <div class="mt-2">
            <BaseButton @click="fetchUsers" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No admin users found' : 'No admin users yet'"
          :description="searchQuery ? 'No admin users match your search criteria.' : 'Get started by creating your first admin account.'"
          icon="Shield"
          :actionText="searchQuery ? 'Clear Search' : 'Add Admin'"
          actionIcon="Plus"
          @action="searchQuery ? (searchQuery = '') : openCreateModal()"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Admin User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Contact</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Role</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Last Login</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Created</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="user in paginatedData"
                  :key="user.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                  @click="openUserDetails(user)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500">@{{ user.username }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ user.email || 'N/A' }}</div>
                    <div class="text-xs text-slate-500">{{ user.phone || 'No phone' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="getRoleVariant(user.role)" size="sm">
                      {{ user.role || 'staff' }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge 
                      :variant="getStatusVariant(user.status)" 
                      :dot="user.status === 'active'"
                      :pulse="user.status === 'active'"
                    >
                      {{ user.status || 'inactive' }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600">
                    {{ formatDate(user.last_login) }}
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600">
                    {{ formatDate(user.created_at) }}
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="openEditModal(user)" variant="ghost" size="sm">
                        <Edit2 class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        @click="handleToggleStatus(user)" 
                        :variant="user.status === 'inactive' ? 'success' : 'warning'" 
                        size="sm"
                      >
                        {{ user.status === 'inactive' ? 'Activate' : 'Deactivate' }}
                      </BaseButton>
                      <BaseButton @click="handleDelete(user)" variant="ghost" size="sm" class="text-red-600">
                        <Trash2 class="w-3 h-3" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Footer -->
    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} users
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
      />
    </PageFooter>

    <!-- Modals -->
    <CreateUserModal v-model="showCreateModal" @success="handleUserCreated" />
    <EditUserModal v-model="showEditModal" :user="selectedUser" @success="handleUserUpdated" />
    <UserDetailsModal v-model="showDetailsModal" :user="selectedUser" />
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Plus, X, RefreshCw, Edit2, Trash2 } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import PageFooter from '@/modules/common/components/layout/templates/PageFooter.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import CreateUserModal from '@/modules/tenant/components/users/CreateUserModal.vue'
import EditUserModal from '@/modules/tenant/components/users/EditUserModal.vue'
import UserDetailsModal from '@/modules/tenant/components/users/UserDetailsModal.vue'

// Composables
import { useUsers } from '@/modules/tenant/composables/data/useUsers'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'

// Data management
const { 
  users, 
  loading, 
  error, 
  activeUsers, 
  inactiveUsers, 
  blockedUsers, 
  totalUsers,
  fetchUsers, 
  deleteUser,
  toggleUserStatus 
} = useUsers()

// Filtering
const { 
  filters, 
  searchQuery, 
  filteredData, 
  hasActiveFilters, 
  clearFilters 
} = useFilters(users, { status: '', role: '' })

// Pagination
const { 
  currentPage, 
  itemsPerPage, 
  paginatedData, 
  totalPages, 
  paginationInfo 
} = usePagination(filteredData, 10)

// Modal state
const showCreateModal = ref(false)
const showEditModal = ref(false)
const showDetailsModal = ref(false)
const selectedUser = ref(null)

// Computed
const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Admin Users' }
])

// Methods
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning'
  }
  return variants[status] || 'default'
}

const getRoleVariant = (role) => {
  const variants = {
    super_admin: 'danger',
    admin: 'purple',
    staff: 'info'
  }
  return variants[role] || 'default'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const openCreateModal = () => {
  showCreateModal.value = true
}

const openEditModal = (user) => {
  selectedUser.value = user
  showEditModal.value = true
}

const openUserDetails = (user) => {
  selectedUser.value = user
  showDetailsModal.value = true
}

const handleUserCreated = () => {
  showCreateModal.value = false
  fetchUsers()
}

const handleUserUpdated = () => {
  showEditModal.value = false
  fetchUsers()
}

const handleToggleStatus = async (user) => {
  const action = user.status === 'inactive' ? 'activate' : 'deactivate'
  const confirmed = confirm(`Are you sure you want to ${action} ${user.name || user.username}?`)
  
  if (confirmed) {
    try {
      await toggleUserStatus(user.id, action === 'deactivate')
      await fetchUsers()
    } catch (err) {
      console.error(`Failed to ${action} user:`, err)
    }
  }
}

const handleDelete = async (user) => {
  const confirmed = confirm(`Are you sure you want to delete ${user.name || user.username}? This action cannot be undone.`)
  
  if (confirmed) {
    try {
      await deleteUser(user.id)
    } catch (err) {
      console.error('Failed to delete user:', err)
    }
  }
}

// Lifecycle
onMounted(() => {
  fetchUsers()
})
</script>
