<template>
  <DataViewContainer
    title="Hotspot Users"
    subtitle="View and manage hotspot customer accounts (auto-created on payment)"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search hotspot users..."
    :stats="[
      { color: 'bg-cyan-500', value: totalUsers },
      { color: 'bg-emerald-500', value: activeUsers.length },
      { color: 'bg-amber-500', value: expiredUsers.length },
      { color: 'bg-slate-500', value: totalDataUsage }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchUsers"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <Wifi class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="$router.push('/dashboard/hotspot/vouchers')" variant="primary" size="sm" class="shrink-0">
        <Ticket class="w-4 h-4 mr-1" /> Create Voucher
      </BaseButton>
    </template>

    <!-- Filters -->
    <template #filters>
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
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <AlertCircle class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchUsers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="user in paginatedData"
          :key="user.id"
          :title="user.username"
          :subtitle="user.phone || user.email || 'No contact info'"
          :meta-lines="[{ text: user.package_name }, { text: formatDataUsage(user.data_usage), class: 'text-slate-600' }]"
          :status="user.status"
          :badges="[{ text: formatExpiry(user.expiry), class: getExpiryClass(user.expiry) }]"
          :actions="getUserActions(user)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Username</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Contact</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Usage</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expires</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="user in paginatedData" :key="user.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <User class="w-4 h-4 text-cyan-600" />
                    <span class="text-sm font-medium text-slate-900">{{ user.username }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-600">{{ user.phone || user.email || '—' }}</div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-700">{{ user.package_name || '—' }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="user.status" size="sm" />
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden w-24">
                      <div class="h-full rounded-full transition-all" :class="getDataUsageColor(user.data_usage, user.data_limit)" :style="{ width: getDataUsagePercent(user.data_usage, user.data_limit) }"></div>
                    </div>
                    <span class="text-xs text-slate-600 w-20">{{ formatDataUsage(user.data_usage) }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span :class="getExpiryClass(user.expiry)">{{ formatExpiry(user.expiry) }}</span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button v-if="user.status === 'active'" @click="disconnectUser(user)" class="px-2 py-1 text-xs font-medium text-amber-700 bg-amber-50 rounded hover:bg-amber-100 transition-colors">Disconnect</button>
                    <button @click="viewDetails(user)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors">Details</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="users" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Users Found' : 'No Hotspot Users'"
      :description="searchQuery ? 'No users match your search criteria.' : 'Hotspot users are automatically created when customers make payments.'"
      icon="wifi"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      @clear="searchQuery = ''"
    >
      <template #action>
        <BaseButton @click="$router.push('/dashboard/hotspot/vouchers')" variant="primary" size="sm">
          <Ticket class="w-4 h-4 mr-1" /> Create Voucher
        </BaseButton>
      </template>
    </DataEmptyState>
  </DataViewContainer>

  <!-- User Details SlideOverlay -->
  <SlideOverlay v-model="showDetailsOverlay" title="User Details" subtitle="Hotspot user information" icon="User" width="480px" @close="closeDetails">
    <div v-if="selectedUser" class="p-6 space-y-6">
      <div class="flex items-center gap-4">
        <div class="w-16 h-16 bg-cyan-100 rounded-full flex items-center justify-center text-cyan-700 text-2xl font-bold">
          {{ selectedUser.username.charAt(0).toUpperCase() }}
        </div>
        <div>
          <h3 class="text-lg font-semibold text-slate-900">{{ selectedUser.username }}</h3>
          <EntityStatusBadge :status="selectedUser.status" size="sm" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Phone</div>
          <div class="text-sm font-medium text-slate-900">{{ selectedUser.phone || '—' }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Email</div>
          <div class="text-sm font-medium text-slate-900">{{ selectedUser.email || '—' }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Package</div>
          <div class="text-sm font-medium text-slate-900">{{ selectedUser.package_name || '—' }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">IP Address</div>
          <div class="text-sm font-medium text-slate-900 font-mono">{{ selectedUser.ip_address || '—' }}</div>
        </div>
      </div>

      <div class="bg-slate-50 rounded-lg p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-2">Data Usage</div>
        <div class="flex items-center gap-4">
          <div class="flex-1 h-2 bg-slate-200 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all" :class="getDataUsageColor(selectedUser.data_usage, selectedUser.data_limit)" :style="{ width: getDataUsagePercent(selectedUser.data_usage, selectedUser.data_limit) }"></div>
          </div>
          <span class="text-sm font-medium text-slate-900">{{ formatDataUsage(selectedUser.data_usage) }} / {{ formatDataUsage(selectedUser.data_limit) }}</span>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Created</div>
          <div class="text-sm font-medium text-slate-900">{{ formatDateTime(selectedUser.created_at) }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Expires</div>
          <div class="text-sm font-medium text-slate-900" :class="getExpiryClass(selectedUser.expiry)">{{ formatExpiry(selectedUser.expiry) }}</div>
        </div>
      </div>

      <div class="bg-slate-50 rounded-lg p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-2">MAC Address</div>
        <div class="text-sm font-medium text-slate-900 font-mono">{{ selectedUser.mac_address || '—' }}</div>
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="closeDetails"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Close
        </button>
        <button
          v-if="selectedUser?.status === 'active'"
          @click="disconnectUser(selectedUser); closeDetails()"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition-colors"
        >
          Disconnect
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Wifi, Ticket, AlertCircle, User } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

// State
const loading = ref(false)
const users = ref([])
const packages = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedUser = ref(null)
const error = ref(null)

const filters = ref({ status: '', package: '' })

// Computed
const totalUsers = computed(() => users.value.length)

const activeUsers = computed(() => users.value.filter(u => u.status === 'active'))
const expiredUsers = computed(() => users.value.filter(u => u.status === 'expired'))

const totalDataUsage = computed(() => {
  const total = users.value.reduce((sum, u) => sum + (u.data_usage || 0), 0)
  return formatDataUsageCompact(total)
})

const filteredData = computed(() => {
  let data = users.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(u =>
      u.username.toLowerCase().includes(query) ||
      (u.phone && u.phone.includes(query)) ||
      (u.email && u.email.toLowerCase().includes(query))
    )
  }
  if (filters.value.status) {
    data = data.filter(u => u.status === filters.value.status)
  }
  if (filters.value.package) {
    data = data.filter(u => u.package_id === filters.value.package)
  }
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.package || searchQuery.value)

// Helpers
const formatDataUsage = (bytes) => {
  if (!bytes || bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = bytes
  let unitIndex = 0
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex++
  }
  return `${size.toFixed(2)} ${units[unitIndex]}`
}

const formatDataUsageCompact = (bytes) => {
  if (!bytes || bytes === 0) return '0'
  if (bytes >= 1099511627776) return `${(bytes / 1099511627776).toFixed(1)}T`
  if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(1)}G`
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)}M`
  return `${(bytes / 1024).toFixed(1)}K`
}

const getDataUsagePercent = (usage, limit) => {
  if (!limit || limit === 0) return '0%'
  const percent = Math.min((usage || 0) / limit * 100, 100)
  return `${percent}%`
}

const getDataUsageColor = (usage, limit) => {
  if (!limit) return 'bg-slate-400'
  const percent = (usage || 0) / limit
  if (percent > 0.9) return 'bg-red-500'
  if (percent > 0.7) return 'bg-amber-500'
  return 'bg-emerald-500'
}

const formatExpiry = (expiry) => {
  if (!expiry) return 'Never'
  const expiryDate = new Date(expiry)
  const now = new Date()
  const diffMs = expiryDate - now
  const diffHours = Math.ceil(diffMs / (1000 * 60 * 60))
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24))
  if (diffHours < 0) return 'Expired'
  if (diffHours < 24) return `${diffHours}h left`
  if (diffDays === 1) return '1 day left'
  if (diffDays < 30) return `${diffDays} days left`
  return expiryDate.toLocaleDateString()
}

const getExpiryClass = (expiry) => {
  if (!expiry) return 'text-slate-500'
  const expiryDate = new Date(expiry)
  const now = new Date()
  const diffHours = (expiryDate - now) / (1000 * 60 * 60)
  if (diffHours < 0) return 'text-red-600'
  if (diffHours < 24) return 'text-amber-600'
  return 'text-emerald-600'
}

const formatDateTime = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const getUserActions = (user) => [
  ...(user.status === 'active' ? [{ label: 'Disconnect', onClick: () => disconnectUser(user), class: 'text-amber-700 bg-amber-50 hover:bg-amber-100' }] : []),
  { label: 'Details', onClick: () => viewDetails(user), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' }
]

// Actions
const fetchUsers = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/hotspot/users')
    const data = response.data?.users?.data || response.data?.users || response.data?.data || []
    users.value = data.map(u => ({
      id: u.id,
      username: u.username || 'Unknown',
      phone: u.phone || null,
      email: u.email || null,
      package_id: u.package_id || null,
      package_name: u.package_name || u.package?.name || null,
      status: u.status || 'inactive',
      data_usage: u.data_usage || u.data_used || 0,
      data_limit: u.data_limit || u.package?.data_limit || 0,
      ip_address: u.ip_address || null,
      mac_address: u.mac_address || null,
      expiry: u.expires_at || u.expiry || null,
      created_at: u.created_at || new Date().toISOString()
    }))
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load hotspot users'
    console.error('fetchUsers error:', err)
  } finally {
    loading.value = false
  }
}

const fetchPackages = async () => {
  try {
    const response = await axios.get('/hotspot/packages', { params: { per_page: 100 } })
    packages.value = response.data?.packages?.data || response.data?.packages || []
  } catch (err) {
    console.error('fetchPackages error:', err)
  }
}

const disconnectUser = async (user) => {
  try {
    await axios.post(`/hotspot/users/${user.id}/disconnect`)
    await fetchUsers()
  } catch (err) {
    console.error('disconnectUser error:', err)
    alert(err.response?.data?.message || 'Failed to disconnect user')
  }
}

const viewDetails = (user) => {
  selectedUser.value = user
  showDetailsOverlay.value = true
}

const closeDetails = () => {
  showDetailsOverlay.value = false
  selectedUser.value = null
}

onMounted(() => {
  fetchUsers()
  fetchPackages()
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
