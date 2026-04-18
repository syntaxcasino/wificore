<template>
  <DataViewContainer
    title="Hotspot Users"
    subtitle="View and manage hotspot customer accounts (auto-created on payment)"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search hotspot users..."
    :stats="[
      { color: 'bg-emerald-500', value: activeUsers.length, tooltip: 'Active users' },
      { color: 'bg-amber-500', value: expiredUsers.length, tooltip: 'Expired users' },
      { color: 'bg-slate-500', value: totalDataUsage, tooltip: 'Total data usage' }
    ]"
    :total="totalUsers"
    :loading="loading"
    add-button-text="Create Voucher"
    @refresh="fetchUsers"
    @add="$router.push('/dashboard/hotspot/vouchers')"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
      </svg>
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
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchUsers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
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
      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
          <table class="w-full">
            <thead>
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Username</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Contact</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[20%]">Data Usage</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Expires</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="user in paginatedData" :key="user.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4 w-[18%]">
                  <div class="flex items-center gap-2">
                    <User class="w-4 h-4 text-cyan-600" />
                    <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.username }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 w-[18%]">
                  <div class="text-sm text-slate-600 dark:text-slate-400">{{ user.phone || user.email || '—' }}</div>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <span class="text-sm text-slate-700">{{ user.package_name || '—' }}</span>
                </td>
                <td class="px-6 py-4 w-[10%]">
                  <EntityStatusBadge :status="user.status" size="sm" />
                </td>
                <td class="px-6 py-4 w-[20%]">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden w-24">
                      <div class="h-full rounded-full transition-all" :class="getDataUsageColor(user.data_usage, user.data_limit)" :style="{ width: getDataUsagePercent(user.data_usage, user.data_limit) }"></div>
                    </div>
                    <span class="text-xs text-slate-600 w-20">{{ formatDataUsage(user.data_usage) }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <span :class="getExpiryClass(user.expiry)">{{ formatExpiry(user.expiry) }}</span>
                </td>
                <td class="px-6 py-4 text-right w-[12%]">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewDetails(user)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors">View</button>
                    <div class="relative">
                      <button data-menu-button @click="toggleMenu(user.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
                        <MoreVertical class="w-4 h-4" />
                      </button>
                    </div>
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
      :show-add="false"
      @clear="searchQuery = ''"
    />

    <!-- Global Dropdown Menu Portal -->
    <Teleport to="body">
      <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white dark:bg-slate-800 rounded-lg shadow-2xl border border-slate-200 dark:border-slate-700 py-1 z-[9999] overflow-hidden">
        <button @click="viewDetails(users.find(u => u.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 hover:text-cyan-700 transition-colors">
          <Eye class="w-4 h-4 mr-3" />
          View Details
        </button>
        <button v-if="users.find(u => u.id === activeMenu)?.status === 'active'" @click="disconnectUser(users.find(u => u.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-amber-700 hover:bg-amber-50 hover:text-amber-700 transition-colors">
          <WifiOff class="w-4 h-4 mr-3" />
          Disconnect
        </button>
        <button v-if="users.find(u => u.id === activeMenu)?.status !== 'active'" @click="handleGrantAccess(users.find(u => u.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-emerald-700 hover:bg-emerald-50 hover:text-emerald-700 transition-colors">
          <Wifi class="w-4 h-4 mr-3" />
          Grant Access
        </button>
        <button @click="handleRevokeAccess(users.find(u => u.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
          <Ban class="w-4 h-4 mr-3" />
          Revoke Access
        </button>
      </div>
    </Teleport>
  </DataViewContainer>

  <!-- User Details SlideOverlay -->
  <SlideOverlay v-model="showDetailsOverlay" title="User Details" subtitle="Hotspot user information" icon="User" width="60%" @close="closeDetails">
    <div v-if="selectedUser" class="p-6 space-y-6">
      <div class="flex items-center gap-4">
        <div class="w-16 h-16 bg-cyan-100 rounded-full flex items-center justify-center text-cyan-700 text-2xl font-bold">
          {{ selectedUser.username.charAt(0).toUpperCase() }}
        </div>
        <div>
          <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ selectedUser.username }}</h3>
          <EntityStatusBadge :status="selectedUser.status" size="sm" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Phone</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedUser.phone || '—' }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Email</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedUser.email || '—' }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Package</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedUser.package_name || '—' }}</div>
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
          <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatDataUsage(selectedUser.data_usage) }} / {{ formatDataUsage(selectedUser.data_limit) }}</span>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Created</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatDateTime(selectedUser.created_at) }}</div>
        </div>
        <div class="bg-slate-50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase tracking-wider">Expires</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100" :class="getExpiryClass(selectedUser.expiry)">{{ formatExpiry(selectedUser.expiry) }}</div>
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
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
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
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { User, MoreVertical, WifiOff, Eye, Ban } from 'lucide-vue-next'
import { useHotspot } from '@/modules/tenant/composables/useHotspot'
import { useToast } from '@/modules/common/composables/useToast'
import { useConfirmStore } from '@/stores/confirm'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

// Get composable state and methods
const { 
  users,
  sessions,
  packages,
  loading,
  error: hotspotError,
  pagination,
  activeUsers,
  expiredUsers,
  totalUsers,
  activeSessions,
  fetchUsers,
  fetchSessions,
  fetchPackages,
  disconnectUser: disconnectUserAction,
  grantAccess,
  revokeAccess,
  getUserDetails,
  setPage,
  setPerPage,
  subscribeToWebSocket,
  unsubscribeFromWebSocket
} = useHotspot()

const { toast } = useToast()
const confirmStore = useConfirmStore()

// Local UI state
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedUser = ref(null)
const filters = ref({ status: '', package: '' })

// Menu state for dropdown actions
const activeMenu = ref(null)
const menuPosition = ref({})

// Stats
const totalDataUsage = computed(() => {
  const total = users.value.reduce((sum, u) => sum + (u.data_usage || 0), 0)
  return formatDataUsageCompact(total)
})

// Filter and paginate
const filteredData = computed(() => {
  let data = users.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(u =>
      u.username?.toLowerCase().includes(query) ||
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

// Menu toggle
const toggleMenu = (userId, event) => {
  event.stopPropagation()
  if (activeMenu.value === userId) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = userId
    const button = event.currentTarget
    const rect = button.getBoundingClientRect()
    const menuWidth = 192
    const menuHeight = 180
    const viewportHeight = window.innerHeight
    const viewportWidth = window.innerWidth
    let top = rect.bottom + 4
    let left = rect.right - menuWidth
    if (rect.bottom + menuHeight > viewportHeight) top = rect.top - menuHeight - 4
    if (left < 0) left = rect.left
    if (left + menuWidth > viewportWidth) left = viewportWidth - menuWidth - 10
    menuPosition.value = { top: `${top}px`, left: `${left}px` }
  }
}

const closeMenu = () => {
  activeMenu.value = null
  menuPosition.value = {}
}

// Click outside handler
const handleClickOutside = (event) => {
  const menu = document.querySelector('[data-dropdown-menu]')
  const menuButton = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    closeMenu()
  }
}

// Keyboard handler
const handleKeydown = (event) => {
  if (event.key === 'Escape') closeMenu()
}

// Actions using composable
const disconnectUser = async (user) => {
  closeMenu()
  const confirmed = await confirmStore.open({
    title: 'Disconnect User',
    message: `Are you sure you want to disconnect "${user.username}"?`,
    confirmText: 'Disconnect',
    cancelText: 'Cancel',
    variant: 'warning'
  })
  
  if (!confirmed) return
  
  try {
    await disconnectUserAction(user.id)
    toast.success(`Disconnected ${user.username}`)
  } catch (err) {
    toast.error(err.message || 'Failed to disconnect user')
  }
}

const handleRevokeAccess = async (user) => {
  closeMenu()
  const confirmed = await confirmStore.open({
    title: 'Revoke Access',
    message: `Revoke access for "${user.username}"? This will prevent them from using the hotspot.`,
    confirmText: 'Revoke',
    cancelText: 'Cancel',
    variant: 'danger'
  })
  
  if (!confirmed) return
  
  try {
    await revokeAccess(user.id)
    toast.success(`Access revoked for ${user.username}`)
  } catch (err) {
    toast.error(err.message || 'Failed to revoke access')
  }
}

const handleGrantAccess = async (user) => {
  closeMenu()
  try {
    await grantAccess(user.id)
    toast.success(`Access granted to ${user.username}`)
  } catch (err) {
    toast.error(err.message || 'Failed to grant access')
  }
}

const viewDetails = async (user) => {
  closeMenu()
  selectedUser.value = user
  showDetailsOverlay.value = true
  
  try {
    const freshUser = await getUserDetails(user.id)
    if (freshUser) {
      selectedUser.value = freshUser
    }
  } catch (err) {
    console.error('Failed to fetch user details:', err)
  }
}

const closeDetails = () => {
  showDetailsOverlay.value = false
  setTimeout(() => { selectedUser.value = null }, 300)
}


// Mobile card actions
const getUserActions = (user) => [
  ...(user.status === 'active' ? [{ label: 'Disconnect', onClick: () => disconnectUser(user), class: 'text-amber-700 bg-amber-50 hover:bg-amber-100' }] : []),
  { label: 'Details', onClick: () => viewDetails(user), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' }
]

// Lifecycle
onMounted(() => {
  fetchUsers()
  fetchPackages()
  subscribeToWebSocket()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  unsubscribeFromWebSocket()
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
/* Scrollbar — no Tailwind equivalent for ::-webkit-scrollbar pseudo-elements */
::-webkit-scrollbar        { width: 8px; height: 8px; }
::-webkit-scrollbar-track  { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb  { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
:global(.dark) ::-webkit-scrollbar-track { background: #1e293b; }
:global(.dark) ::-webkit-scrollbar-thumb { background: #475569; }
</style>
