<template>
  <PageContainer>
    <!-- Header with Filters -->
    <div class="bg-white border-b border-slate-200">
      <!-- Title, Filters and Actions -->
      <div class="px-6 py-4">
        <div class="flex items-center gap-4 flex-wrap">
          <!-- Title -->
          <div>
            <h1 class="text-2xl font-bold text-slate-900">Hotspot Users</h1>
          </div>
          
          <!-- Search Box -->
          <div class="flex-1 min-w-[250px] max-w-md">
            <BaseSearch v-model="searchQuery" placeholder="Search hotspot users..." />
          </div>
          
          <!-- Filters Group -->
          <div class="flex items-center gap-2">
            <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
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
          <div class="flex items-center gap-2">
            <BaseBadge variant="info">{{ totalUsers }} Total</BaseBadge>
            <BaseBadge variant="success" dot pulse>{{ activeUsers.length }} Active</BaseBadge>
            <BaseBadge variant="warning">{{ inactiveUsers.length }} Inactive</BaseBadge>
          </div>
          
          <!-- Action Button -->
          <div class="ml-auto">
            <BaseButton @click="$router.push('/dashboard/hotspot/vouchers/generate')" variant="primary">
              <Ticket class="w-4 h-4 mr-1" />
              Generate Vouchers
            </BaseButton>
          </div>
        </div>
        
        <!-- Subtitle -->
        <p class="text-sm text-slate-600 mt-2">View and manage hotspot customer accounts (auto-created on payment)</p>
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
          :title="searchQuery ? 'No hotspot users found' : 'No hotspot users yet'"
          :description="searchQuery ? 'No users match your search criteria.' : 'Hotspot users are automatically created when customers make payments.'"
          icon="Wifi"
          actionText="View Vouchers"
          actionIcon="Ticket"
          @action="$router.push('/dashboard/hotspot/vouchers/generate')"
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
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Voucher Code</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expiry</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Used</th>
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
                      <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500">{{ user.phone || 'No phone' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-mono text-slate-900">{{ user.voucher_code || 'N/A' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ user.package?.name || 'No package' }}</div>
                    <div class="text-xs text-slate-500">{{ user.package?.duration || 'N/A' }}</div>
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
                  <td class="px-6 py-4 text-sm text-slate-600">
                    {{ formatBytes(user.data_used) }}
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewSessions(user)" variant="ghost" size="sm">
                        <Activity class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="handleDisconnect(user)" variant="warning" size="sm" v-if="user.status === 'active'">
                        Disconnect
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
import { Ticket, X, RefreshCw, Activity } from 'lucide-vue-next'
import axios from 'axios'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
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

import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'

// Data management
const users = ref([])
const loading = ref(false)
const error = ref(null)

const { packages, fetchPackages } = usePackages()

// Fetch hotspot users
const fetchUsers = async () => {
  loading.value = true
  error.value = null
  
  try {
    const response = await axios.get('/hotspot/users')
    users.value = response.data.data || response.data
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to fetch hotspot users'
    console.error('Error fetching hotspot users:', err)
  } finally {
    loading.value = false
  }
}

// Computed
const activeUsers = computed(() => 
  users.value.filter(u => u.status === 'active')
)

const inactiveUsers = computed(() => 
  users.value.filter(u => u.status === 'inactive' || u.status === 'expired')
)

const totalUsers = computed(() => users.value.length)

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


// Methods
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning',
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

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const openUserDetails = (user) => {
  console.log('View user details:', user)
  // TODO: Implement user details modal
}

const viewSessions = (user) => {
  console.log('View sessions for:', user)
  // TODO: Navigate to sessions view filtered by user
}

const handleDisconnect = async (user) => {
  const confirmed = confirm(`Are you sure you want to disconnect ${user.name || user.username}?`)
  
  if (confirmed) {
    try {
      // TODO: Implement disconnect logic
      console.log('Disconnecting user:', user)
    } catch (err) {
      console.error('Failed to disconnect user:', err)
    }
  }
}

// Lifecycle
onMounted(() => {
  fetchUsers()
  fetchPackages()
})
</script>
