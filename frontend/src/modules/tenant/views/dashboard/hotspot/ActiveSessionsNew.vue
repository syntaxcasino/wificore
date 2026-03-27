<template>
  <DataViewContainer
    title="Active Sessions"
    subtitle="Monitor and manage active hotspot user sessions"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search sessions..."
    :stats="[
      { color: 'bg-blue-500', value: stats.total },
      { color: 'bg-emerald-500', value: stats.users },
      { color: 'bg-cyan-500', value: formatBytesCompact(stats.bandwidth) },
      { color: 'bg-amber-500', value: formatBytesCompact(stats.totalData) }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchSessions"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <Activity class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton v-if="filteredData.length > 0" @click="disconnectAll" variant="danger" size="sm" class="shrink-0">
        <Power class="w-4 h-4 mr-1" /> Disconnect All
      </BaseButton>
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
        <div
          v-for="session in paginatedData"
          :key="session.id"
          class="bg-white rounded-lg border border-slate-200 shadow-sm p-4"
        >
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-gradient-to-br from-cyan-500 to-blue-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                {{ getUserInitials(session.user) }}
              </div>
              <div>
                <p class="text-sm font-medium text-slate-900">{{ session.user?.name || session.username }}</p>
                <p class="text-xs text-slate-500">{{ session.ip_address }}</p>
              </div>
            </div>
            <div class="flex items-center gap-1">
              <button @click="viewSessionDetails(session)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
                <Eye class="w-4 h-4" />
              </button>
              <button @click="disconnectSession(session)" class="p-2 text-red-600 hover:bg-red-50 rounded-md transition-colors">
                <Power class="w-4 h-4" />
              </button>
            </div>
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
            <div class="bg-slate-50 rounded p-2">
              <span class="text-slate-500">Package:</span>
              <span class="ml-1 text-slate-700">{{ session.package?.name || 'N/A' }}</span>
            </div>
            <div class="bg-slate-50 rounded p-2">
              <span class="text-slate-500">Duration:</span>
              <span class="ml-1 text-slate-700">{{ formatDuration(session.duration) }}</span>
            </div>
            <div class="bg-slate-50 rounded p-2">
              <span class="text-slate-500">Data:</span>
              <span class="ml-1 text-slate-700">{{ formatBytes(session.bytes_in + session.bytes_out) }}</span>
            </div>
            <div class="bg-slate-50 rounded p-2">
              <span class="text-slate-500">Speed:</span>
              <span class="ml-1 text-slate-700">{{ formatBytes(session.current_bandwidth) }}/s</span>
            </div>
          </div>
          <div class="mt-2">
            <div class="flex items-center gap-2">
              <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-blue-500" :style="{ width: getBandwidthPercentage(session) + '%' }"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">IP / MAC</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Usage</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Bandwidth</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="session in paginatedData" :key="session.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4">
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
                <td class="px-6 py-4">
                  <p class="text-sm text-slate-900">{{ session.ip_address }}</p>
                  <p class="text-xs text-slate-500 font-mono">{{ session.mac_address }}</p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-medium text-slate-900">{{ session.package?.name || 'N/A' }}</p>
                  <p class="text-xs text-slate-500">{{ session.package?.speed || 'N/A' }}</p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm text-slate-900">{{ formatDuration(session.duration) }}</p>
                  <p class="text-xs text-slate-500">Since {{ formatTime(session.start_time) }}</p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm text-slate-900">{{ formatBytes(session.bytes_in + session.bytes_out) }}</p>
                  <p class="text-xs text-slate-500">
                    <span class="text-emerald-600">↓ {{ formatBytes(session.bytes_in) }}</span>
                    <span class="mx-1">•</span>
                    <span class="text-blue-600">↑ {{ formatBytes(session.bytes_out) }}</span>
                  </p>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                      <div class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-300" :style="{ width: getBandwidthPercentage(session) + '%' }"></div>
                    </div>
                    <span class="text-xs text-slate-700 w-14 text-right">{{ formatBytes(session.current_bandwidth) }}/s</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewSessionDetails(session)" class="p-1.5 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
                      <Eye class="w-4 h-4" />
                    </button>
                    <button @click="disconnectSession(session)" class="p-1.5 text-red-600 hover:bg-red-50 rounded-md transition-colors">
                      <Power class="w-4 h-4" />
                    </button>
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
      @clear="searchQuery = ''"
    />
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
import { Activity, Power, Eye, AlertCircle } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

// State
const loading = ref(false)
const error = ref(null)
const sessions = ref([])
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

const filteredData = computed(() => {
  let result = sessions.value
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

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
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

// Actions
const fetchPackages = async () => {
  try {
    const response = await axios.get('/packages')
    packages.value = (response.data?.data || response.data || []).map(p => ({ id: p.id, name: p.name }))
  } catch (err) {
    console.warn('Failed to fetch packages:', err)
  }
}

const fetchSessions = async () => {
  const isInitial = sessions.value.length === 0
  if (isInitial) {
    loading.value = true
    error.value = null
  }
  try {
    const response = await axios.get('/hotspot/sessions')
    const data = response.data?.sessions || response.data?.data || []
    sessions.value = data.map(s => ({
      id: s.id,
      session_id: s.session_id || s.acct_session_id || `sess_${s.id}`,
      username: s.username || s.user?.name || 'Unknown',
      user: {
        name: s.user?.name || s.username || 'Unknown',
        phone: s.user?.phone_number || s.phone_number || ''
      },
      ip_address: s.framed_ip_address || s.ip_address || s.address || '',
      mac_address: s.calling_station_id || s.mac_address || '',
      nas_ip: s.nas_ip_address || s.nas_ip || '',
      package: s.package ? { id: s.package.id, name: s.package.name, speed: s.package.download_speed || '' } : { name: 'N/A', speed: '' },
      start_time: s.acct_start_time || s.started_at || s.created_at || new Date().toISOString(),
      duration: s.session_time || s.duration || s.uptime_seconds || 0,
      bytes_in: Number(s.acct_input_octets || s.bytes_in || 0),
      bytes_out: Number(s.acct_output_octets || s.bytes_out || 0),
      current_bandwidth: Number(s.current_bandwidth || 0),
      _raw: s
    }))
  } catch (err) {
    if (isInitial) error.value = err.response?.data?.message || 'Failed to load active sessions.'
    console.error('fetchSessions error:', err)
  } finally {
    loading.value = false
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
  const confirmed = await confirmStore.confirm(`Disconnect ${session.user?.name || session.username}?`)
  if (!confirmed) return
  try {
    const userId = session._raw?.user_id || session._raw?.id || session.id
    await axios.post(`/hotspot/users/${userId}/disconnect`)
    sessions.value = sessions.value.filter(s => s.id !== session.id)
    showDetailsOverlay.value = false
  } catch (err) {
    console.error('Disconnect error:', err)
    alert(err.response?.data?.message || 'Failed to disconnect session')
  }
}

const disconnectAll = async () => {
  const confirmed = await confirmStore.confirm(`Disconnect all ${sessions.value.length} active sessions?`)
  if (!confirmed) return
  try {
    const promises = sessions.value.map(s => {
      const userId = s._raw?.user_id || s._raw?.id || s.id
      return axios.post(`/hotspot/users/${userId}/disconnect`).catch(() => null)
    })
    await Promise.allSettled(promises)
    await fetchSessions()
  } catch (err) {
    console.error('Disconnect all error:', err)
    alert(err.response?.data?.message || 'Failed to disconnect all sessions')
  }
}

// Lifecycle
let refreshInterval

onMounted(() => {
  fetchPackages()
  fetchSessions()
  refreshInterval = setInterval(fetchSessions, 15000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
