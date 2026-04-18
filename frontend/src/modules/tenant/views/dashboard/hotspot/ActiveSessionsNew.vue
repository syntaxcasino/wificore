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
    :total="sessions?.length || 0"
    :loading="loading"
    @refresh="fetchSessions"
    @search-clear="clearFilters"
  >
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
      </svg>
    </template>

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

    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchSessions" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <DataSkeleton v-else-if="loading" :count="5" />

    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
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

      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <div class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
          <table class="w-full">
            <thead>
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
          </table>
        </div>
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="session in paginatedData" :key="session.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4 w-[18%]">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-cyan-500 to-blue-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                      {{ getUserInitials(session.user) }}
                    </div>
                    <div>
                      <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.user?.name || session.username }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">{{ session.user?.phone || 'No phone' }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <p class="text-sm text-slate-900">{{ session.ip_address }}</p>
                  <p class="text-xs text-slate-500 font-mono">{{ session.mac_address }}</p>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.package?.name || 'N/A' }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">{{ session.package?.speed || 'N/A' }}</p>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <p class="text-sm text-slate-900">{{ formatDuration(session.duration) }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">Since {{ formatTime(session.start_time) }}</p>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <p class="text-sm text-slate-900">{{ formatBytes(session.bytes_in + session.bytes_out) }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">
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
                    <button @click="viewSessionDetails(session)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors">View</button>
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

      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="sessions" class="mt-auto" />
    </div>

    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Sessions Found' : 'No Active Sessions'"
      :description="searchQuery ? 'No sessions match your search criteria.' : 'There are currently no active hotspot sessions.'"
      icon="activity"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="!!(searchQuery || filters.package || filters.duration)"
      :show-add="false"
      @clear="searchQuery = ''"
    />

    <Teleport to="body">
      <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white dark:bg-slate-800 rounded-lg shadow-2xl border border-slate-200 dark:border-slate-700 py-1 z-[9999] overflow-hidden">
        <button @click="viewSessionDetails(sessions.find(s => s.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 hover:text-cyan-700 transition-colors">
          <Eye class="w-4 h-4 mr-3" />View Details
        </button>
        <button @click="disconnectSession(sessions.find(s => s.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
          <Power class="w-4 h-4 mr-3" />Disconnect
        </button>
      </div>
    </Teleport>
  </DataViewContainer>

  <SessionDetailsOverlay :show="showDetailsOverlay" :session="selectedSession" @close="closeDetailsOverlay" @disconnect="disconnectSession" />
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { Eye, Power, MoreVertical } from 'lucide-vue-next'
import { useActiveSessions } from '@/modules/tenant/composables/useActiveSessions'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

const {
  sessions,
  filteredSessions,
  packages,
  filters,
  stats,
  loading,
  error,
  fetchSessions,
  fetchPackages,
  disconnectSession,
  getSessionById,
  searchSessions,
  clearFilters,
  formatBytes,
  formatBytesCompact,
  formatDuration,
  formatTime,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useActiveSessions()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedSession = ref(null)
const activeMenu = ref(null)
const menuPosition = ref({})

const filteredData = computed(() => {
  if (!searchQuery.value) return filteredSessions.value
  return searchSessions(searchQuery.value)
})

const paginatedData = computed(() => {
  if (!filteredData.value || !Array.isArray(filteredData.value)) return []
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil((filteredData.value?.length || 0) / itemsPerPage.value))

watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })
watch(() => filters.value.package, () => { currentPage.value = 1 })
watch(() => filters.value.duration, () => { currentPage.value = 1 })

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

const getUserInitials = (user) => {
  if (!user?.name) return '?'
  return user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getBandwidthPercentage = (session) => {
  const maxBandwidth = 10485760
  return Math.min((session.current_bandwidth / maxBandwidth) * 100, 100)
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
  { label: 'Disconnect', onClick: () => disconnectSessionHandler(session), class: 'text-red-600 bg-red-50 hover:bg-red-100' }
]

const viewSessionDetails = (session) => {
  closeMenu()
  selectedSession.value = { ...session, type: 'hotspot' }
  showDetailsOverlay.value = true
}

const closeDetailsOverlay = () => {
  showDetailsOverlay.value = false
}

const disconnectSessionHandler = async (session) => {
  closeMenu()
  const confirmed = await confirmStore.confirm(`Disconnect ${session.user?.name || session.username}?`)
  if (!confirmed) return
  try {
    await disconnectSession(session)
    showDetailsOverlay.value = false
  } catch (err) {
    console.error('Disconnect error:', err)
  }
}

onMounted(() => {
  fetchPackages()
  fetchSessions()
  setupWebSocketListeners()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  cleanupWebSocketListeners()
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
