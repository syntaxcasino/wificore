<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="PPPoE Sessions"
      subtitle="Monitor and manage active PPPoE connections in real-time"
      icon="Network"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshSessions" variant="ghost" size="sm" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="disconnectAll" variant="danger" :disabled="!filteredData.length">
          <Power class="w-4 h-4 mr-1" />
          Disconnect All
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Search and Filters Bar -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search sessions by username, IP..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.router" placeholder="All Routers" class="w-40">
            <option value="">All Routers</option>
            <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.duration" placeholder="Session Duration" class="w-44">
            <option value="">All Durations</option>
            <option value="short">< 1 hour</option>
            <option value="medium">1-6 hours</option>
            <option value="long">> 6 hours</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Stats Badges -->
        <div class="ml-auto flex items-center gap-2">
          <BaseBadge variant="success" dot pulse>{{ totalSessions }} Active</BaseBadge>
          <BaseBadge variant="info">{{ formatBytes(totalBandwidth) }}/s</BaseBadge>
          <BaseBadge variant="warning">{{ totalUsers }} Users</BaseBadge>
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
            <BaseButton @click="refreshSessions" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No sessions found' : 'No active sessions'"
          :description="searchQuery ? 'No sessions match your search criteria.' : 'There are currently no active PPPoE sessions.'"
          icon="Network"
          :actionText="searchQuery ? 'Clear Search' : 'Refresh'"
          actionIcon="RefreshCw"
          @action="searchQuery ? (searchQuery = '') : refreshSessions()"
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
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Connection</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Router</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Bandwidth</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="session in paginatedData"
                  :key="session.id"
                  class="border-b border-slate-100 hover:bg-purple-50/50 transition-colors"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(session) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ session.username }}</div>
                        <div class="text-xs text-slate-500">{{ session.user?.phone || 'No phone' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ session.ip_address || session.framed_ip }}</div>
                    <div class="text-xs text-slate-500">{{ session.mac_address || session.calling_station_id }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ session.router_name || 'N/A' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="space-y-1">
                      <div class="text-xs">
                        <span class="text-green-600 font-medium">↓ {{ formatBytes(session.download_rate || session.download_speed) }}/s</span>
                      </div>
                      <div class="text-xs">
                        <span class="text-blue-600 font-medium">↑ {{ formatBytes(session.upload_rate || session.upload_speed) }}/s</span>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDuration(session.uptime || session.duration) }}</div>
                    <div class="text-xs text-slate-500">{{ formatDateTime(session.connected_at || session.start_time) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex items-center justify-end gap-2">
                      <BaseButton @click="viewSessionDetails(session)" variant="ghost" size="sm">
                        <Eye class="w-4 h-4" />
                      </BaseButton>
                      <BaseButton @click="disconnectSession(session)" variant="danger" size="sm">
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
            Showing {{ paginationStart }} to {{ paginationEnd }} of {{ filteredData.length }} sessions
          </div>
          <BasePagination
            v-model="currentPage"
            :total-pages="totalPages"
            :total-items="filteredData.length"
          />
        </div>
      </div>
    </PageContent>

    <!-- Session Details Overlay -->
    <SessionDetailsOverlay
      :show="showDetailsOverlay"
      :session="selectedSession"
      :icon="Network"
      @close="closeDetailsOverlay"
      @disconnect="disconnectSession"
    />
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { 
  RefreshCw, Power, Eye, X, Network
} from 'lucide-vue-next'
import axios from 'axios'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'
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
import { useConfirmStore } from '@/stores/confirm'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'PPPoE', to: '/dashboard/pppoe' },
  { label: 'Active Sessions' }
]

// State
const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const sessions = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedSession = ref(null)

const confirmStore = useConfirmStore()
const authStore = useAuthStore()
const toast = useToast()
const { subscribeToPrivateChannel, unsubscribeFromChannel } = useBroadcasting()

// Filters
const filters = ref({
  router: '',
  duration: ''
})

const routers = computed(() => {
  const byId = new Map()

  for (const s of sessions.value) {
    const id = s?.router_id
    const name = s?.router_name

    if (!id) continue

    if (!byId.has(id)) {
      byId.set(id, {
        id,
        name: name || 'N/A',
      })
    }
  }

  return Array.from(byId.values())
})

// Computed
const filteredData = computed(() => {
  let result = sessions.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(session => 
      session.username?.toLowerCase().includes(query) ||
      session.ip_address?.includes(query) ||
      session.framed_ip?.includes(query) ||
      session.mac_address?.toLowerCase().includes(query) ||
      session.calling_station_id?.toLowerCase().includes(query)
    )
  }

  // Router filter
  if (filters.value.router) {
    result = result.filter(session => String(session.router_id ?? '') === String(filters.value.router))
  }

  // Duration filter
  if (filters.value.duration) {
    result = result.filter(session => {
      const hours = session.duration / 3600
      if (filters.value.duration === 'short') return hours < 1
      if (filters.value.duration === 'medium') return hours >= 1 && hours <= 6
      if (filters.value.duration === 'long') return hours > 6
      return true
    })
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

const hasActiveFilters = computed(() => filters.value.router || filters.value.duration)

const totalSessions = computed(() => sessions.value.length)
const totalUsers = computed(() => new Set(sessions.value.map(s => s.username)).size)
const totalBandwidth = computed(() => sessions.value.reduce((sum, s) => sum + (s.download_rate || s.download_speed || 0) + (s.upload_rate || s.upload_speed || 0), 0))

// Methods
const fetchSessions = async () => {
  loading.value = true
  error.value = null
  
  try {
    const response = await axios.get('pppoe/sessions')
    const payload = response.data?.data ?? response.data
    sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    error.value = 'Failed to load active sessions. Please try again.'
    console.error('Error fetching sessions:', err)
  } finally {
    loading.value = false
  }
}

const refreshSessions = async () => {
  refreshing.value = true
  error.value = null
  
  try{
    const response = await axios.get('pppoe/sessions')
    const payload = response.data?.data ?? response.data
    sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    error.value = 'Failed to load active sessions. Please try again.'
    console.error('Error fetching sessions:', err)
  } finally {
    refreshing.value = false
  }
}

const clearFilters = () => {
  filters.value = {
    router: '',
    duration: ''
  }
}

const getUserInitials = (session) => {
  if (!session.username) return '?'
  return session.username.slice(0, 2).toUpperCase()
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

const getSpeedPercentage = (current, max) => {
  if (!max) return 0
  return Math.min((current / max) * 100, 100)
}

const viewSessionDetails = (session) => {
  selectedSession.value = { ...session, type: 'pppoe' }
  showDetailsOverlay.value = true
}

const closeDetailsOverlay = () => {
  showDetailsOverlay.value = false
}

const disconnectSession = async (session) => {
  const confirmed = await confirmStore.open({
    title: 'Confirm Disconnect',
    message: `Disconnect ${session.username}?`,
    confirmText: 'Disconnect',
    cancelText: 'Cancel',
    variant: 'danger',
  })

  if (!confirmed) return
  
  try {
    const response = await axios.post('pppoe/sessions/disconnect', { username: session.username })
    if (response.data?.success) {
      toast.success(`Successfully disconnected ${session.username}`)
      await refreshSessions()
      showDetailsOverlay.value = false
    } else {
      toast.error(response.data?.message || 'Failed to disconnect session')
    }
  } catch (err) {
    console.error('Error disconnecting session:', err)
    toast.error(err.response?.data?.message || 'Failed to disconnect session')
  }
}

const disconnectAll = async () => {
  const confirmed = await confirmStore.open({
    title: 'Confirm Disconnect All',
    message: `Disconnect all ${totalSessions.value} active sessions?`,
    confirmText: 'Disconnect All',
    cancelText: 'Cancel',
    variant: 'danger',
  })

  if (!confirmed) return
  
  try {
    const usernames = sessions.value.map(s => s.username).filter(Boolean)
    const response = await axios.post('pppoe/sessions/disconnect-all', { usernames })
    if (response.data?.success) {
      toast.success(`Disconnected ${response.data.disconnected} sessions`)
      await refreshSessions()
    } else {
      toast.error(response.data?.message || 'Failed to disconnect sessions')
    }
  } catch (err) {
    console.error('Error disconnecting all sessions:', err)
    toast.error(err.response?.data?.message || 'Failed to disconnect all sessions')
  }
}

// Channel name for cleanup
let sessionChannel = null

// EVENT-BASED: Subscribe to WebSocket for real-time updates (NO POLLING)
onMounted(() => {
  // Fetch initial sessions ONCE
  fetchSessions()
  
  // Subscribe to WebSocket events for PPPoE sessions
  const tenantId = authStore.tenantId
  if (tenantId) {
    sessionChannel = `tenant.${tenantId}.pppoe-sessions`
    
    subscribeToPrivateChannel(sessionChannel, {
      PppoeSessionStarted: (event) => {
        console.log('✨ Session started:', event)
        if (event.session) {
          // Add new session to the list
          const exists = sessions.value.some(s => 
            s.username === event.session.username && s.router_id === event.session.router_id
          )
          if (!exists) {
            sessions.value.unshift(event.session)
          }
        }
      },
      PppoeSessionEnded: (event) => {
        console.log('👋 Session ended:', event)
        if (event.session) {
          // Remove session from the list
          sessions.value = sessions.value.filter(s => 
            !(s.username === event.session.username && s.router_id === event.session.router_id)
          )
        }
      },
      PppoeSessionUpdated: (event) => {
        console.log('🔄 Session updated:', event)
        if (event.session) {
          // Update session in the list
          const index = sessions.value.findIndex(s => 
            s.username === event.session.username && s.router_id === event.session.router_id
          )
          if (index !== -1) {
            sessions.value[index] = { ...sessions.value[index], ...event.session }
          }
        }
      }
    })
  }
})

// Cleanup WebSocket subscription on unmount
onUnmounted(() => {
  if (sessionChannel) {
    unsubscribeFromChannel(sessionChannel)
  }
})
</script>
