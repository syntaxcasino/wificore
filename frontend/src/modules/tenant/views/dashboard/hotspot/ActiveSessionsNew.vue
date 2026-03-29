<template>
  <DataViewContainer
    title="Active Sessions"
    subtitle="Monitor and manage active hotspot user sessions"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search sessions..."
    :show-add="false"
    :stats="[
      { color: 'bg-blue-500', value: stats.total, tooltip: 'Total sessions' },
      { color: 'bg-emerald-500', value: stats.users, tooltip: 'Unique users' },
      { color: 'bg-cyan-500', value: formatBytesCompact(stats.bandwidth), tooltip: 'Current bandwidth' },
      { color: 'bg-amber-500', value: formatBytesCompact(stats.totalData), tooltip: 'Total data transferred' }
    ]"
    :total="sessions.length"
    :loading="loading"
    @refresh="fetchSessions"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <Activity class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.package" placeholder="All Packages" class="w-40">
        <option value="">All Packages</option>
        <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
      </BaseSelect>
      <BaseSelect v-model="filters.duration" placeholder="Duration" class="w-36">
        <option value="">All Duration</option>
        <option value="short">&lt; 5 min</option>
        <option value="medium">5-30 min</option>
        <option value="long">&gt; 30 min</option>
      </BaseSelect>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <AlertCircle class="w-10 h-10" />
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
          :title="session.user?.name || session.username"
          :subtitle="session.ip_address"
          :meta-lines="getSessionMetaLines(session)"
          :status="'active'"
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
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">IP / MAC</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Duration</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Data Usage</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[16%]">Bandwidth</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="session in paginatedData" :key="session.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4 w-[18%]">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-cyan-500 to-blue-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                      {{ getUserInitials(session.user) }}
                    </div>
                    <div>
                      <p class="text-sm font-medium text-slate-900">{{ session.user?.name || session.username }}</p>
                      <p class="text-xs text-slate-500">{{ session.user?.phone || 'No phone' }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <p class="text-sm text-slate-900">{{ session.ip_address }}</p>
                  <p class="text-xs text-slate-500 font-mono">{{ session.mac_address }}</p>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <p class="text-sm font-medium text-slate-900">{{ session.package?.name || 'N/A' }}</p>
                  <p class="text-xs text-slate-500">{{ session.package?.speed || 'N/A' }}</p>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <p class="text-sm text-slate-900">{{ formatDuration(session.duration) }}</p>
                  <p class="text-xs text-slate-500">Since {{ formatTime(session.start_time) }}</p>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <p class="text-sm text-slate-900">{{ formatBytes(session.bytes_in + session.bytes_out) }}</p>
                  <p class="text-xs text-slate-500">
                    <span class="text-emerald-600">↓ {{ formatBytes(session.bytes_in) }}</span>
                    <span class="mx-1">•</span>
                    <span class="text-blue-600">↑ {{ formatBytes(session.bytes_out) }}</span>
                  </p>
                </td>
                <td class="px-6 py-4 w-[16%]">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                      <div class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-300" :style="{ width: getBandwidthPercentage(session) + '%' }"></div>
                    </div>
                    <span class="text-xs text-slate-700 w-14 text-right">{{ formatBytes(session.current_bandwidth) }}/s</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-right w-[12%]">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewSessionDetails(session)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors">
                      View
                    </button>
                    <div class="relative">
                      <button data-menu-button @click="toggleMenu(session.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
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
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="sessions" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Sessions Found' : 'No Active Sessions'"
      :description="searchQuery ? 'No sessions match your search criteria.' : 'There are currently no active hotspot sessions.'"
      icon="activity"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      :show-add="false"
      @clear="searchQuery = ''"
    />

    <!-- Global Dropdown Menu Portal -->
    <Teleport to="body">
      <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white rounded-lg shadow-2xl border border-slate-200 py-1 z-[9999] overflow-hidden">
        <button @click="viewSessionDetails(sessions.find(s => s.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 hover:text-cyan-700 transition-colors">
          <Eye class="w-4 h-4 mr-3" />
          View Details
        </button>
        <button @click="disconnectSession(sessions.find(s => s.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
          <Power class="w-4 h-4 mr-3" />
          Disconnect
        </button>
      </div>
    </Teleport>
  </DataViewContainer>

  <!-- Session Details Overlay -->
  <SessionDetailsOverlay
    :show="showDetailsOverlay"
    :session="selectedSession"
    :icon="Activity"
    @close="closeDetailsOverlay"
    @disconnect="disconnectSession"
  />
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Activity, Power, Eye, AlertCircle, MoreVertical } from 'lucide-vue-next'
import axios from 'axios'
import { useHotspot } from '@/modules/tenant/composables/useHotspot'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

// Use hotspot composable for event-based sessions
const { sessions, loading, error, fetchSessions, subscribeToWebSocket, unsubscribeFromWebSocket } = useHotspot()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedSession = ref(null)

const filters = ref({ package: '', duration: '' })
const packages = ref([])

// Computed
const stats = computed(() => ({
  total: sessions.value.length,
  users: new Set(sessions.value.map(s => s.username)).size,
  bandwidth: sessions.value.reduce((sum, s) => sum + (s.current_bandwidth || 0), 0),
  totalData: sessions.value.reduce((sum, s) => sum + (s.bytes_in || 0) + (s.bytes_out || 0), 0)
}))

const filteredSessions = computed(() => {
  let result = [...sessions.value]
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(s =>
      s.username?.toLowerCase().includes(query) ||
      s.user?.name?.toLowerCase().includes(query) ||
      s.ip_address?.includes(query) ||
      s.mac_address?.toLowerCase().includes(query)
    )
  }
  if (filters.value.package) {
    result = result.filter(s => s.package?.id === parseInt(filters.value.package))
  }
  if (filters.value.duration) {
    result = result.filter(s => {
      const minutes = s.duration / 60
      if (filters.value.duration === 'short') return minutes < 5
      if (filters.value.duration === 'medium') return minutes >= 5 && minutes <= 30
      if (filters.value.duration === 'long') return minutes > 30
      return true
    })
  }
  return result
})

const filteredData = filteredSessions

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredSessions.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredSessions.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.package || filters.value.duration || searchQuery.value)

// Helpers
const formatBytes = (bytes) => {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let size = bytes
  while (size >= k && i < sizes.length - 1) {
    size /= k
    i++
  }
  return `${Math.round(size * 100) / 100} ${sizes[i]}`
}

const formatBytesCompact = (bytes) => {
  if (!bytes || bytes === 0) return '0'
  if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(1)}G`
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)}M`
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)}K`
  return `${bytes}B`
}

const formatDuration = (seconds) => {
  if (!seconds) return '0s'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = Math.floor(seconds % 60)
  if (hours > 0) return `${hours}h ${minutes}m`
  if (minutes > 0) return `${minutes}m ${secs}s`
  return `${secs}s`
}

const formatTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

const getUserInitials = (user) => {
  if (!user?.name) return '?'
  return user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getBandwidthPercentage = (session) => {
  const maxBandwidth = 10485760 // 10 MB/s
  return Math.min((session.current_bandwidth / maxBandwidth) * 100, 100)
}

// Menu state
const activeMenu = ref(null)
const menuPosition = ref({})

const toggleMenu = (sessionId, event) => {
  event.stopPropagation()
  if (activeMenu.value === sessionId) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = sessionId
    const button = event.currentTarget
    const rect = button.getBoundingClientRect()
    const menuWidth = 192
    const menuHeight = 120
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

const handleClickOutside = (event) => {
  const menu = document.querySelector('[data-dropdown-menu]')
  const menuButton = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    closeMenu()
  }
}

const handleKeydown = (event) => {
  if (event.key === 'Escape') closeMenu()
}

const getSessionMetaLines = (session) => {
  const lines = []
  if (session.package?.name) lines.push({ text: session.package.name })
  if (session.duration) lines.push({ text: formatDuration(session.duration) })
  if (session.bytes_in || session.bytes_out) {
    lines.push({ text: formatBytes(session.bytes_in + session.bytes_out) })
  }
  return lines
}

const getSessionActions = (session) => [
  { label: 'View', onClick: () => viewSessionDetails(session), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' },
  { label: 'Disconnect', onClick: () => disconnectSession(session), class: 'text-red-600 bg-red-50 hover:bg-red-100' }
]

// Actions
const fetchPackages = async () => {
  try {
    const response = await axios.get('/packages')
    packages.value = (response.data?.data || response.data || []).map(p => ({ id: p.id, name: p.name }))
  } catch (err) {
    console.warn('Failed to fetch packages:', err)
  }
}

const viewSessionDetails = (session) => {
  selectedSession.value = { ...session, type: 'hotspot' }
  showDetailsOverlay.value = true
}

const closeDetailsOverlay = () => {
  showDetailsOverlay.value = false
}

const disconnectSession = async (session) => {
  closeMenu()
  const confirmed = await confirmStore.confirm(`Disconnect ${session.user?.name || session.username}?`)
  if (!confirmed) return
  try {
    const userId = session._raw?.user_id || session._raw?.id || session.id
    await axios.post(`/hotspot/users/${userId}/disconnect`)
    // Session will be removed via WebSocket event
    showDetailsOverlay.value = false
  } catch (err) {
    console.error('Disconnect error:', err)
    alert(err.response?.data?.message || 'Failed to disconnect session')
  }
}

// Lifecycle
onMounted(() => {
  fetchPackages()
  fetchSessions()
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
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
