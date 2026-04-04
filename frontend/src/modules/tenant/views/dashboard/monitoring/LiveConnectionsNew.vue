<template>
  <DataViewContainer
    title="Live Connections"
    subtitle="Real-time monitoring of active network connections"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search by IP, MAC, user..."
    :stats="[
      { color: 'bg-blue-500', value: stats.total, tooltip: 'Total Online Users' },
      { color: 'bg-orange-500', value: stats.hotspot, tooltip: 'Hotspot Users' },
      { color: 'bg-indigo-500', value: stats.pppoe, tooltip: 'PPPoE Users' },
      { color: 'bg-green-500', value: formatBytes(stats.download) + '/s ↓', tooltip: 'Download Speed' },
      { color: 'bg-purple-500', value: formatBytes(stats.upload) + '/s ↑', tooltip: 'Upload Speed' },
      { color: 'bg-amber-500', value: stats.peakToday, tooltip: 'Peak Concurrent Today' }
    ]"
    :total="connections?.length || 0"
    :loading="loading"
    @refresh="fetchConnections"
    @search-clear="clearFilters"
  >
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
      </svg>
    </template>

    <template #actions>
      <button
        @click="exportData"
        class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 transition-colors inline-flex items-center gap-1"
      >
        Export
      </button>
    </template>

    <!-- Connection Details Modal -->
    <SlideOverlay v-model="showDetailsOverlay" title="Connection Details" :subtitle="selectedConnection?.username" icon="activity" width="480px">
      <div v-if="selectedConnection" class="space-y-6 p-6">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500 mb-1">Username</div>
            <div class="text-sm font-medium text-slate-900">{{ selectedConnection.username }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Type</div>
            <EntityStatusBadge :status="selectedConnection.type === 'hotspot' ? 'active' : 'pending'" size="sm">
              {{ selectedConnection.type }}
            </EntityStatusBadge>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">IP Address</div>
            <div class="text-sm font-mono text-slate-900">{{ selectedConnection.ip_address }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">MAC Address</div>
            <div class="text-sm font-mono text-slate-900">{{ selectedConnection.mac_address }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Router</div>
            <div class="text-sm text-slate-900">{{ selectedConnection.router_name }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Connected At</div>
            <div class="text-sm text-slate-900">{{ formatDateTime(selectedConnection.connected_at) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Duration</div>
            <div class="text-sm text-slate-900">{{ formatDuration(selectedConnection.uptime) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Service</div>
            <div class="text-sm text-slate-900">{{ selectedConnection.service || selectedConnection.type }}</div>
          </div>
        </div>

        <div class="border-t border-slate-200 pt-4">
          <h4 class="text-sm font-semibold text-slate-700 mb-3">Bandwidth Usage</h4>
          <div class="grid grid-cols-2 gap-4">
            <div class="bg-green-50 rounded-lg p-3 border border-green-200">
              <div class="flex items-center gap-2 mb-1">
                <ArrowDown class="w-4 h-4 text-green-600" />
                <span class="text-xs text-green-600 font-medium">Download</span>
              </div>
              <div class="text-lg font-bold text-green-900">{{ formatBytes(selectedConnection.download_rate) }}/s</div>
            </div>
            <div class="bg-blue-50 rounded-lg p-3 border border-blue-200">
              <div class="flex items-center gap-2 mb-1">
                <ArrowUp class="w-4 h-4 text-blue-600" />
                <span class="text-xs text-blue-600 font-medium">Upload</span>
              </div>
              <div class="text-lg font-bold text-blue-900">{{ formatBytes(selectedConnection.upload_rate) }}/s</div>
            </div>
          </div>
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
            @click="handleDisconnect(selectedConnection); closeDetails()"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors"
          >
            Disconnect
          </button>
        </div>
      </template>
    </SlideOverlay>

    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchConnections" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <DataSkeleton v-else-if="loading" :count="5" />

    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="conn in paginatedData"
          :key="conn.id"
          :title="conn.username"
          :subtitle="conn.user_name || conn.ip_address"
          :meta-lines="getConnectionMetaLines(conn)"
          :status="conn.type"
          :status-variant="conn.type === 'hotspot' ? 'purple' : 'info'"
          :actions="getConnectionActions(conn)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 border-b border-slate-200">
          <table class="w-full">
            <thead>
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[20%]">User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Connection</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Type</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell w-[15%]">Router</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Bandwidth</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Duration</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="conn in paginatedData"
                :key="conn.id"
                class="hover:bg-blue-50/50 transition-colors"
              >
                <td class="px-6 py-4 w-[20%]">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                      {{ getUserInitials(conn) }}
                    </div>
                    <div class="min-w-0">
                      <div class="text-sm font-medium text-slate-900 truncate">{{ conn.username }}</div>
                      <div class="text-xs text-slate-500 truncate">{{ conn.user_name || 'N/A' }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 w-[18%]">
                  <div class="text-sm text-slate-900 font-mono truncate">{{ conn.ip_address }}</div>
                  <div class="text-xs text-slate-500 font-mono truncate">{{ conn.mac_address }}</div>
                </td>
                <td class="px-6 py-4 w-[10%]">
                  <EntityStatusBadge :status="conn.type === 'hotspot' ? 'active' : 'pending'" size="sm">
                    {{ conn.type }}
                  </EntityStatusBadge>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell w-[15%]">
                  <span class="text-sm text-slate-900 truncate block">{{ conn.router_name }}</span>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <div class="space-y-1">
                    <div class="flex items-center gap-2 text-xs">
                      <ArrowDown class="w-3 h-3 text-green-600 flex-shrink-0" />
                      <span class="text-green-600 font-medium">{{ formatBytes(conn.download_rate) }}/s</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                      <ArrowUp class="w-3 h-3 text-blue-600 flex-shrink-0" />
                      <span class="text-blue-600 font-medium">{{ formatBytes(conn.upload_rate) }}/s</span>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <div class="text-sm text-slate-900">{{ formatDuration(conn.uptime) }}</div>
                  <div class="text-xs text-slate-500">{{ formatDateTime(conn.connected_at) }}</div>
                </td>
                <td class="px-6 py-4 text-right w-[15%]">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      @click="viewDetails(conn)"
                      class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors"
                    >
                      View
                    </button>
                    <div class="relative">
                      <button data-menu-button @click="toggleMenu(conn.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
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
      <DataPagination
        v-model:current-page="currentPage"
        v-model:items-per-page="itemsPerPage"
        :total-pages="totalPages"
        :total-items="filteredData.length"
        item-name="connections"
        class="mt-auto"
      />

      <!-- Global Dropdown Menu Portal -->
      <Teleport to="body">
        <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white rounded-lg shadow-2xl border border-slate-200 py-1 z-[9999] overflow-hidden">
          <button @click="viewDetails(getConnectionById(activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
            <Eye class="w-4 h-4 mr-3" />
            View Details
          </button>
          <div class="border-t border-slate-200 my-1"></div>
          <button @click.stop="handleDisconnect(getConnectionById(activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
            <Power class="w-4 h-4 mr-3" />
            Disconnect
          </button>
        </div>
      </Teleport>
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Active Connections'"
      :description="searchQuery ? 'No connections match your search criteria. Try adjusting your filters.' : 'No connections are currently active on the network.'"
      icon="activity"
      color-theme="blue"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      :showAdd="false"
      clear-text="Clear Search"
      @clear="searchQuery = ''"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import {
  Eye, Power,
  ArrowDown, ArrowUp, MoreVertical
} from 'lucide-vue-next'
import { useLiveConnections } from '@/modules/tenant/composables/useLiveConnections'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

// Use composable (matching Todo pattern)
const {
  connections,
  filteredConnections,
  stats,
  loading,
  error,
  fetchConnections,
  disconnectUser,
  getConnectionById,
  searchConnections,
  clearFilters,
  formatBytes,
  setupSSEListeners,
  cleanupSSEListeners,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useLiveConnections()

// Local state (matching Todo pattern)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedConnection = ref(null)
const activeMenu = ref(null)
const menuPosition = ref({})

// Computed (matching Todo pattern)
const filteredData = computed(() => {
  if (!searchQuery.value) return filteredConnections.value
  return searchConnections(searchQuery.value)
})

const totalPages = computed(() => Math.ceil((filteredData.value?.length || 0) / itemsPerPage.value))

const paginatedData = computed(() => {
  if (!filteredData.value || !Array.isArray(filteredData.value)) return []
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

// Reset page on search change (matching Todo pattern)
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Menu toggle (matching Todo pattern)
const toggleMenu = (connId, event) => {
  event.stopPropagation()
  if (activeMenu.value === connId) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = connId
    const button = event.currentTarget
    const rect = button.getBoundingClientRect()
    const menuWidth = 192
    const menuHeight = 80
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

// Click outside handler (matching Todo pattern)
const handleClickOutside = (event) => {
  const menu = document.querySelector('[data-dropdown-menu]')
  const menuButton = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    closeMenu()
  }
}

// Keyboard handler (matching Todo pattern)
const handleKeydown = (event) => {
  if (event.key === 'Escape') closeMenu()
}

// View details (matching Todo pattern)
const viewDetails = (conn) => {
  if (!conn) return
  closeMenu()
  selectedConnection.value = conn
  showDetailsOverlay.value = true
}

const closeDetails = () => {
  showDetailsOverlay.value = false
  setTimeout(() => { selectedConnection.value = null }, 300)
}

// Disconnect handler
const handleDisconnect = async (conn) => {
  if (!conn) return
  if (!confirm(`Disconnect ${conn.username}?`)) return

  closeMenu()
  try {
    await disconnectUser(conn)
    if (showDetailsOverlay.value && selectedConnection.value?.id === conn.id) {
      closeDetails()
    }
  } catch (err) {
    console.error('Failed to disconnect:', err)
  }
}

// Export data
const exportData = () => {
  const csv = [
    ['Username', 'IP Address', 'MAC Address', 'Type', 'Router', 'Connected At'].join(','),
    ...filteredData.value.map(c => [
      c.username, c.ip_address, c.mac_address, c.type, c.router_name, c.connected_at
    ].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `live-connections-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

// Helpers
const formatDuration = (seconds) => {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  return h > 0 ? `${h}h ${m}m` : `${m}m ${s}s`
}

const formatDateTime = (date) => {
  return new Date(date).toLocaleString()
}

const getUserInitials = (conn) => {
  return conn.username.slice(0, 2).toUpperCase()
}

// Mobile card helpers
const getConnectionMetaLines = (conn) => [
  { icon: 'Router', text: conn.router_name },
  { icon: 'ArrowDown', text: `${formatBytes(conn.download_rate)}/s ↓` },
  { icon: 'ArrowUp', text: `${formatBytes(conn.upload_rate)}/s ↑` },
  { icon: 'Clock', text: formatDuration(conn.uptime) }
]

const getConnectionActions = (conn) => [
  { label: 'View', icon: 'Eye', onClick: () => viewDetails(conn) },
  { label: 'Disconnect', icon: 'Power', variant: 'danger', onClick: () => handleDisconnect(conn) }
]

// Lifecycle (matching Todo pattern with SSE/WebSocket)
onMounted(async () => {
  await fetchConnections()
  // Try SSE first, fallback to WebSocket events
  setupSSEListeners()
  setupWebSocketListeners()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  cleanupSSEListeners()
  cleanupWebSocketListeners()
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}
::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
