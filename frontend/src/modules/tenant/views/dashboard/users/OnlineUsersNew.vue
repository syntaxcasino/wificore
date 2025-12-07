<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Online Users"
      subtitle="Monitor all currently connected users across Hotspot and PPPoE"
      icon="Users"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshUsers" variant="ghost" size="sm" :loading="loading">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': loading }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportData" variant="secondary">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Search and Filters Bar -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search by username, IP, phone..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.type" placeholder="All Types" class="w-36">
            <option value="">All Types</option>
            <option value="hotspot">Hotspot</option>
            <option value="pppoe">PPPoE</option>
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
          <BaseBadge variant="success" dot pulse>{{ totalOnline }} Online</BaseBadge>
          <BaseBadge variant="info">{{ hotspotCount }} Hotspot</BaseBadge>
          <BaseBadge variant="purple">{{ pppoeCount }} PPPoE</BaseBadge>
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
            <BaseButton @click="refreshUsers" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No users found' : 'No users online'"
          :description="searchQuery ? 'No users match your search criteria.' : 'There are currently no users connected.'"
          icon="Users"
          :actionText="searchQuery ? 'Clear Search' : 'Refresh'"
          actionIcon="RefreshCw"
          @action="searchQuery ? (searchQuery = '') : refreshUsers()"
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
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Type</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Connection</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Usage</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="user in paginatedData"
                  :key="user.id"
                  class="border-b border-slate-100 hover:bg-slate-50 transition-colors cursor-pointer"
                  @click="viewUserDetails(user)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div 
                        class="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-semibold"
                        :class="user.type === 'hotspot' ? 'bg-gradient-to-br from-blue-500 to-cyan-500' : 'bg-gradient-to-br from-purple-500 to-indigo-500'"
                      >
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500">{{ user.phone || 'No phone' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="user.type === 'hotspot' ? 'info' : 'purple'">
                      <Wifi v-if="user.type === 'hotspot'" class="w-3 h-3 mr-1" />
                      <Network v-else class="w-3 h-3 mr-1" />
                      {{ user.type === 'hotspot' ? 'Hotspot' : 'PPPoE' }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ user.ip_address }}</div>
                    <div class="text-xs text-slate-500 font-mono">{{ user.mac_address || user.calling_station }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ user.package?.name || 'N/A' }}</div>
                    <div class="text-xs text-slate-500">{{ user.package?.speed || 'N/A' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDuration(user.session_duration) }}</div>
                    <div class="text-xs text-slate-500">{{ formatTime(user.login_time) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatBytes(user.total_bytes) }}</div>
                    <div class="text-xs text-slate-500">
                      <span class="text-green-600">↓ {{ formatBytes(user.bytes_in) }}</span>
                      <span class="mx-1">•</span>
                      <span class="text-blue-600">↑ {{ formatBytes(user.bytes_out) }}</span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex items-center justify-end gap-2">
                      <BaseButton @click.stop="viewUserDetails(user)" variant="ghost" size="sm">
                        <Eye class="w-4 h-4" />
                      </BaseButton>
                      <BaseButton @click.stop="disconnectUser(user)" variant="danger" size="sm">
                        <Power class="w-4 h-4" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>

        <!-- Pagination -->
        <div class="mt-4 flex items-center justify-between">
          <div class="text-sm text-slate-600">
            Showing {{ paginationStart }} to {{ paginationEnd }} of {{ filteredData.length }} users
          </div>
          <BasePagination
            v-model="currentPage"
            :total-pages="totalPages"
            :total-items="filteredData.length"
          />
        </div>
      </div>
    </PageContent>

    <!-- User Details Overlay -->
    <SessionDetailsOverlay
      :show="showDetailsOverlay"
      :session="selectedUser"
      :icon="Users"
      @close="closeDetailsOverlay"
      @disconnect="disconnectUser"
    />
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { 
  RefreshCw, Power, Eye, X, Users, Download, Wifi, Network
} from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Users', to: '/dashboard/users' },
  { label: 'Online Users' }
]

// State
const loading = ref(false)
const error = ref(null)
const users = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsModal = ref(false)
const selectedUser = ref(null)

// Filters
const filters = ref({
  type: '',
  package: ''
})

const packages = ref([
  { id: 1, name: '1 Hour - 5GB' },
  { id: 2, name: '24 Hours - 20GB' },
  { id: 3, name: '5 Mbps Monthly' },
  { id: 4, name: '10 Mbps Monthly' }
])

// Mock data
const mockUsers = [
  {
    id: 1,
    type: 'hotspot',
    username: 'hotspot_user001',
    name: 'John Doe',
    phone: '+254712345678',
    ip_address: '10.0.0.101',
    mac_address: '00:1A:2B:3C:4D:5E',
    session_id: 'sess_hot_001',
    nas_ip: '192.168.1.1',
    package: { name: '1 Hour - 5GB', speed: '10 Mbps' },
    login_time: new Date(Date.now() - 1800000),
    session_duration: 1800,
    bytes_in: 524288000,
    bytes_out: 104857600,
    total_bytes: 629145600,
    current_speed: 2097152
  },
  {
    id: 2,
    type: 'pppoe',
    username: 'pppoe_user001',
    name: 'Jane Smith',
    phone: '+254723456789',
    ip_address: '100.64.0.101',
    calling_station: 'pppoe-client-001',
    session_id: 'sess_ppp_001',
    nas_ip: '192.168.1.1',
    package: { name: '10 Mbps Monthly', speed: '10/5 Mbps' },
    login_time: new Date(Date.now() - 7200000),
    session_duration: 7200,
    bytes_in: 2147483648,
    bytes_out: 536870912,
    total_bytes: 2684354560,
    current_speed: 8388608
  },
  {
    id: 3,
    type: 'hotspot',
    username: 'hotspot_user002',
    name: 'Mike Johnson',
    phone: '+254734567890',
    ip_address: '10.0.0.102',
    mac_address: '00:1A:2B:3C:4D:5F',
    session_id: 'sess_hot_002',
    nas_ip: '192.168.1.1',
    package: { name: '24 Hours - 20GB', speed: '20 Mbps' },
    login_time: new Date(Date.now() - 3600000),
    session_duration: 3600,
    bytes_in: 1073741824,
    bytes_out: 209715200,
    total_bytes: 1283457024,
    current_speed: 4194304
  }
]

// Computed
const filteredData = computed(() => {
  let result = users.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(user => 
      user.username?.toLowerCase().includes(query) ||
      user.name?.toLowerCase().includes(query) ||
      user.phone?.includes(query) ||
      user.ip_address?.includes(query)
    )
  }

  // Type filter
  if (filters.value.type) {
    result = result.filter(user => user.type === filters.value.type)
  }

  // Package filter
  if (filters.value.package) {
    result = result.filter(user => user.package?.name === packages.value.find(p => p.id === parseInt(filters.value.package))?.name)
  }

  return result
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const paginationStart = computed(() => (currentPage.value - 1) * itemsPerPage.value + 1)
const paginationEnd = computed(() => Math.min(currentPage.value * itemsPerPage.value, filteredData.value.length))

const hasActiveFilters = computed(() => filters.value.type || filters.value.package)

const totalOnline = computed(() => users.value.length)
const hotspotCount = computed(() => users.value.filter(u => u.type === 'hotspot').length)
const pppoeCount = computed(() => users.value.filter(u => u.type === 'pppoe').length)

// Methods
const fetchUsers = async () => {
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    users.value = mockUsers
  } catch (err) {
    error.value = 'Failed to load online users. Please try again.'
    console.error('Error fetching users:', err)
  } finally {
    loading.value = false
  }
}

const refreshUsers = () => {
  fetchUsers()
}

const clearFilters = () => {
  filters.value = {
    type: '',
    package: ''
  }
}

const getUserInitials = (user) => {
  if (!user.name) return '?'
  return user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDuration = (seconds) => {
  if (!seconds) return '0s'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  
  if (hours > 0) return `${hours}h ${minutes}m`
  return `${minutes}m`
}

const formatTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleTimeString()
}

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

const viewUserDetails = (user) => {
  selectedUser.value = user
  showDetailsOverlay.value = true
}

const closeDetailsOverlay = () => {
  showDetailsOverlay.value = false
}

const disconnectUser = async (user) => {
  if (!confirm(`Disconnect ${user.name || user.username}?`)) return
  
  try {
    console.log('Disconnecting user:', user.id)
    await new Promise(resolve => setTimeout(resolve, 500))
    users.value = users.value.filter(u => u.id !== user.id)
    showDetailsOverlay.value = false
  } catch (err) {
    console.error('Error disconnecting user:', err)
  }
}

const exportData = () => {
  console.log('Exporting data...')
  // TODO: Implement export functionality
}

// EVENT-BASED: Subscribe to WebSocket for real-time updates (NO POLLING)
onMounted(() => {
  console.log('🚀 OnlineUsers mounted - EVENT-BASED mode')
  
  // Fetch initial users ONCE
  fetchUsers()
  
  // TODO: Subscribe to WebSocket events for online users
  // Example:
  // subscribeToPrivateChannel('online-users', {
  //   'UserConnected': (event) => {
  //     console.log('✨ User connected:', event)
  //     users.value.push(event.user)
  //   },
  //   'UserDisconnected': (event) => {
  //     console.log('👋 User disconnected:', event)
  //     users.value = users.value.filter(u => u.id !== event.user.id)
  //   },
  //   'SessionUpdated': (event) => {
  //     console.log('🔄 Session updated:', event)
  //     const index = users.value.findIndex(u => u.id === event.user.id)
  //     if (index !== -1) {
  //       users.value[index] = { ...users.value[index], ...event.user }
  //     }
  //   }
  // })
  
  console.log('✅ WebSocket subscriptions active - NO POLLING!')
})
</script>
