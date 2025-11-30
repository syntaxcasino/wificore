<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="PPPoE Users"
      subtitle="Manage PPPoE customer accounts"
      icon="Network"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="$router.push('/dashboard/pppoe/add-user')" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add PPPoE User
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Search and Filters Bar -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search PPPoE users..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="blocked">Blocked</option>
            <option value="expired">Expired</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.package" placeholder="All Packages" class="w-40">
            <option value="">All Packages</option>
            <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
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
          :title="searchQuery ? 'No PPPoE users found' : 'No PPPoE users yet'"
          :description="searchQuery ? 'No users match your search criteria.' : 'Get started by creating your first PPPoE user account.'"
          icon="Network"
          actionText="Add PPPoE User"
          actionIcon="Plus"
          @action="$router.push('/dashboard/pppoe/add-user')"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Contact</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expiry</th>
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
                      <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500">{{ user.username }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ user.email || 'N/A' }}</div>
                    <div class="text-xs text-slate-500">{{ user.phone || 'No phone' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ user.package?.name || 'No package' }}</div>
                    <div class="text-xs text-slate-500">{{ user.package?.speed || 'N/A' }}</div>
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
                    {{ formatDate(user.expiry_date) }}
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="handleEdit(user)" variant="ghost" size="sm">
                        <Edit2 class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        @click="handleToggleStatus(user)" 
                        :variant="user.status === 'blocked' ? 'success' : 'warning'" 
                        size="sm"
                      >
                        {{ user.status === 'blocked' ? 'Unblock' : 'Block' }}
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

import { useUsers } from '@/modules/tenant/composables/data/useUsers'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'

// Data management
const { 
  users, 
  loading, 
  error, 
  activeUsers, 
  inactiveUsers, 
  totalUsers,
  fetchUsers, 
  deleteUser,
  toggleUserStatus 
} = useUsers()

const { packages, fetchPackages } = usePackages()

// Filtering
const { 
  filters, 
  searchQuery, 
  filteredData, 
  hasActiveFilters, 
  clearFilters 
} = useFilters(users, { status: '', package: '' })

// Pagination
const { 
  currentPage, 
  itemsPerPage, 
  paginatedData, 
  totalPages, 
  paginationInfo 
} = usePagination(filteredData, 10)

// Computed
const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'PPPoE', to: '/dashboard/pppoe/sessions' },
  { label: 'Users' }
])

// Methods
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning',
    blocked: 'danger',
    expired: 'danger'
  }
  return variants[status] || 'default'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const openUserDetails = (user) => {
  console.log('View user details:', user)
  // TODO: Implement user details modal
}

const handleEdit = (user) => {
  console.log('Edit user:', user)
  // TODO: Navigate to edit page or open modal
}

const handleToggleStatus = async (user) => {
  const action = user.status === 'blocked' ? 'unblock' : 'block'
  const confirmed = confirm(`Are you sure you want to ${action} ${user.name || user.username}?`)
  
  if (confirmed) {
    try {
      await toggleUserStatus(user.id, action === 'block')
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
  fetchPackages()
})
</script>
