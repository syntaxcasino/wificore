<template>
  <DataViewContainer
    title="Active Sessions"
    subtitle="Monitor real-time PPPoE connections"
    color-theme="purple"
    v-model:search-model="searchQuery"
    search-placeholder="Search sessions..."
    :stats="[
      { color: 'bg-purple-500', value: totalSessions },
      { color: 'bg-emerald-500', value: totalUsers },
      { color: 'bg-blue-500', value: formatBytes(totalBandwidth) }
    ]"
    :total="sessions.length"
    :loading="loading"
    @refresh="refreshSessions"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.router" placeholder="All Routers" class="w-44">
        <option value="">All Routers</option>
        <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
      </BaseSelect>
      <BaseSelect v-model="filters.duration" placeholder="All Durations" class="w-40">
        <option value="">All Durations</option>
        <option value="short">&lt; 1 hour</option>
        <option value="medium">1-6 hours</option>
        <option value="long">&gt; 6 hours</option>
      </BaseSelect>
      <button v-if="hasActiveFilters" @click="clearFilters" class="text-xs text-purple-600 hover:text-purple-700 font-medium">Clear filters</button>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <X class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchSessions" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="session in paginatedData"
          :key="session.id"
          :title="session.username"
          :subtitle="session.user?.phone || 'No phone'"
          :meta-lines="[
            { text: `${session.ip_address || session.framed_ip} | ${session.router_name || 'N/A'}` },
            { text: `↓ ${formatBytes(session.download_rate || session.download_speed)}/s | ↑ ${formatBytes(session.upload_rate || session.upload_speed)}/s` },
            { text: `${formatDuration(session.uptime || session.duration)} | ${formatDateTime(session.connected_at || session.start_time)}` }
          ]"
          :status="'online'"
          :actions="getSessionActions(session)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">IP & MAC</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Router</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Bandwidth</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="session in paginatedData" :key="session.id" class="hover:bg-purple-50/50 transition-colors cursor-pointer" @click="viewSessionDetails(session)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">{{ getUserInitials(session) }}</div>
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
                    <div class="text-xs"><span class="text-green-600 font-medium">↓ {{ formatBytes(session.download_rate || session.download_speed) }}/s</span></div>
                    <div class="text-xs"><span class="text-blue-600 font-medium">↑ {{ formatBytes(session.upload_rate || session.upload_speed) }}/s</span></div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-900">{{ formatDuration(session.uptime || session.duration) }}</div>
                  <div class="text-xs text-slate-500">{{ formatDateTime(session.connected_at || session.start_time) }}</div>
                </td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewSessionDetails(session)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors"><Eye class="w-3 h-3 mr-1" /> View</button>
                    <button @click="disconnectSession(session)" class="px-2 py-1 text-xs font-medium text-red-700 bg-red-50 rounded hover:bg-red-100 transition-colors"><Power class="w-3 h-3 mr-1" /> Disconnect</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="sessions" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery || hasActiveFilters ? 'No Matches Found' : 'No Active Sessions'"
      :description="searchQuery || hasActiveFilters ? 'No sessions match your criteria.' : 'No PPPoE users are currently connected.'"
      icon="zap"
      color-theme="purple"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Filters"
      @clear="clearFilters"
    />

    <!-- Stats Bar (only on desktop) -->
    <template #footer-left>
      <div class="hidden md:flex gap-4">
        <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-emerald-500"></div><span class="text-xs text-slate-600">{{ totalSessions }} sessions</span></div>
        <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-blue-500"></div><span class="text-xs text-slate-600">{{ totalUsers }} unique users</span></div>
      </div>
    </template>
  </DataViewContainer>

  <!-- Session Details Overlay -->
  <SessionDetailsOverlay
    v-model="showDetailsOverlay"
    :session="selectedSession"
    :icon="Network"
    @close="closeDetailsOverlay"
    @disconnect="disconnectSession"
  />
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { X, Eye, Power, Network } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()
const authStore = useAuthStore()
const toast = useToast()
const { subscribeToPrivateChannel, unsubscribeFromChannel } = useBroadcasting()

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

// Filters
const filters = ref({ router: '', duration: '' })

const routers = computed(() => {
  const byId = new Map()
  for (const s of sessions.value) {
    if (!s?.router_id) continue
    if (!byId.has(s.router_id)) {
      byId.set(s.router_id, { id: s.router_id, name: s.router_name || 'N/A' })
    }
  }
  return Array.from(byId.values())
})

const filteredData = computed(() => {
  let result = sessions.value
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
  if (filters.value.router) {
    result = result.filter(session => String(session.router_id ?? '') === String(filters.value.router))
  }
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
const hasActiveFilters = computed(() => filters.value.router || filters.value.duration)
const totalSessions = computed(() => sessions.value.length)
const totalUsers = computed(() => new Set(sessions.value.map(s => s.username)).size)
const totalBandwidth = computed(() => sessions.value.reduce((sum, s) => sum + (s.download_rate || s.download_speed || 0) + (s.upload_rate || s.upload_speed || 0), 0))

// Helpers
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

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

const getSessionActions = (session) => [
  { label: 'View', onClick: () => viewSessionDetails(session), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: 'Disconnect', onClick: () => disconnectSession(session), class: 'text-red-700 bg-red-50 hover:bg-red-100' }
]

const clearFilters = () => {
  filters.value = { router: '', duration: '' }
  searchQuery.value = ''
}

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
  try {
    const response = await axios.get('pppoe/sessions')
    const payload = response.data?.data ?? response.data
    sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    error.value = 'Failed to refresh sessions. Please try again.'
    console.error('Error refreshing sessions:', err)
  } finally {
    refreshing.value = false
  }
}

const viewSessionDetails = (session) => {
  selectedSession.value = { ...session, type: 'pppoe' }
  showDetailsOverlay.value = true
}

const closeDetailsOverlay = () => {
  showDetailsOverlay.value = false
  selectedSession.value = null
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

// WebSocket
let sessionChannel = null
let pollingInterval = null

const startPolling = () => {
  stopPolling()
  pollingInterval = setInterval(() => {
    axios.get('pppoe/sessions')
      .then(response => {
        const payload = response.data?.data ?? response.data
        sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      })
      .catch(err => console.error('Silent session refresh failed:', err))
  }, 10000)
}

const stopPolling = () => {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
}

onMounted(() => {
  fetchSessions().then(() => startPolling())
  const tenantId = authStore.tenantId
  if (tenantId) {
    sessionChannel = `tenant.${tenantId}.pppoe-sessions`
    subscribeToPrivateChannel(sessionChannel, {
      PppoeSessionStarted: () => refreshSessions(),
      PppoeSessionEnded: (event) => {
        if (event.session) {
          sessions.value = sessions.value.filter(s =>
            !(s.username === event.session.username && s.router_id === event.session.router_id)
          )
        }
      },
      PppoeSessionUpdated: () => refreshSessions()
    })
  }
})

onUnmounted(() => {
  stopPolling()
  if (sessionChannel) unsubscribeFromChannel(sessionChannel)
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
