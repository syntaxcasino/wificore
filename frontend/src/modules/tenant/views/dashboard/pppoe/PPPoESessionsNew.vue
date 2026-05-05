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
    :showAdd="false"
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
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
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
              <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[20%]">User</th>
              <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">IP & MAC</th>
              <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Router</th>
              <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Bandwidth</th>
              <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Duration</th>
              <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[11%]">Actions</th>
            </tr>
          </thead>
        </table>
      </div>
      <!-- Scrollable Body -->
      <div class="overflow-y-auto flex-1 min-h-0">
        <table class="w-full">
          <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            <tr v-for="session in paginatedData" :key="session.id" class="hover:bg-purple-50/50 transition-colors">
              <td class="px-6 py-4 w-[20%]">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">{{ getUserInitials(session) }}</div>
                  <div>
                    <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.username }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ session.user?.phone || 'No phone' }}</div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 w-[18%]">
                <div class="text-sm text-slate-900">{{ session.ip_address || session.framed_ip }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ session.mac_address || session.calling_station_id }}</div>
              </td>
              <td class="px-6 py-4 w-[15%]">
                <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.router_name || 'N/A' }}</div>
              </td>
              <td class="px-6 py-4 w-[18%]">
                <div class="space-y-1">
                  <div class="text-xs"><span class="text-green-600 font-medium">↓ {{ formatBytes(session.download_rate || session.download_speed) }}/s</span></div>
                  <div class="text-xs"><span class="text-blue-600 font-medium">↑ {{ formatBytes(session.upload_rate || session.upload_speed) }}/s</span></div>
                  <div v-if="session.acct_staleness_seconds > 600" class="text-xs text-amber-600 font-medium flex items-center gap-1">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    Acct stale {{ Math.floor(session.acct_staleness_seconds / 60) }}m ago
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 w-[18%]">
                <div class="text-sm text-slate-900">{{ formatDuration(session.uptime || session.duration) }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatDateTime(session.connected_at || session.start_time) }}</div>
              </td>
              <td class="px-6 py-4 text-right w-[11%]">
                <div class="flex items-center justify-end gap-1">
                  <button @click="viewSessionDetails(session)" class="px-2 py-1 text-xs font-medium text-purple-700 bg-purple-50 rounded hover:bg-purple-100 transition-colors">View</button>
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
      :showActions="false"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Filters"
      @clear="clearFilters"
    />

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
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { X, Network } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'
import { usePppoeSessions } from '@/modules/tenant/composables/usePppoeSessions'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

const {
  sessions,
  loading,
  error,
  routers,
  totalSessions,
  totalUsers,
  totalBandwidth,
  fetchSessions,
  refreshSessions,
  disconnectSession,
  filterSessions,
  setupWebSocketListeners,
  cleanupWebSocketListeners,
  formatBytes,
  formatDuration,
  formatDateTime,
  getUserInitials
} = usePppoeSessions()

// State
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedSession = ref(null)
const filters = ref({ router: '', duration: '' })

// Computed
const filteredData = computed(() => filterSessions(searchQuery.value, filters.value))

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.router || filters.value.duration)

// Reset page on search/filter change
watch([searchQuery, itemsPerPage, () => filters.value.router, () => filters.value.duration], () => {
  currentPage.value = 1
})

const clearFilters = () => {
  filters.value = { router: '', duration: '' }
  searchQuery.value = ''
}

const viewSessionDetails = (session) => {
  selectedSession.value = { ...session, type: 'pppoe' }
  showDetailsOverlay.value = true
}

const closeDetailsOverlay = () => {
  showDetailsOverlay.value = false
  selectedSession.value = null
}

const handleDisconnect = async (session) => {
  const confirmed = await confirmStore.open({
    title: 'Confirm Disconnect',
    message: `Disconnect ${session.username}?`,
    confirmText: 'Disconnect',
    cancelText: 'Cancel',
    variant: 'danger',
  })
  if (!confirmed) return
  
  const success = await disconnectSession(session)
  if (success) {
    showDetailsOverlay.value = false
  }
}

// Lifecycle — WebSocket event-driven only, no polling
onMounted(async () => {
  await fetchSessions()
  setupWebSocketListeners()
})

onUnmounted(() => {
  cleanupWebSocketListeners()
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
