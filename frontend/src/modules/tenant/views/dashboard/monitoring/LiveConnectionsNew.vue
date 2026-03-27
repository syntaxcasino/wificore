<template>
  <DataViewContainer
    title="Live Connections"
    subtitle="Real-time monitoring of active network connections"
    icon="Activity"
    :breadcrumbs="breadcrumbs"
  >
    <template #actions>
      <BaseButton @click="refreshConnections" variant="ghost" :loading="refreshing">
        <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
        Refresh
      </BaseButton>
      <BaseButton @click="exportData" variant="ghost">
        <Download class="w-4 h-4 mr-1" />
        Export
      </BaseButton>
    </template>

    <template #stats>
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Connections</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
              <Activity class="w-6 h-6 text-blue-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Active</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.active }}</div>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
              <Wifi class="w-6 h-6 text-green-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Bandwidth</div>
              <div class="text-2xl font-bold text-purple-900">{{ formatBytes(stats.bandwidth) }}/s</div>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
              <Zap class="w-6 h-6 text-purple-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Download</div>
              <div class="text-2xl font-bold text-amber-900">{{ formatBytes(stats.download) }}/s</div>
            </div>
            <div class="p-3 bg-amber-100 rounded-lg">
              <ArrowDown class="w-6 h-6 text-amber-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-cyan-600 font-medium mb-1">Upload</div>
              <div class="text-2xl font-bold text-cyan-900">{{ formatBytes(stats.upload) }}/s</div>
            </div>
            <div class="p-3 bg-cyan-100 rounded-lg">
              <ArrowUp class="w-6 h-6 text-cyan-600" />
            </div>
          </div>
        </div>
      </div>
    </template>

    <template #filters>
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search by IP, MAC, user..." />
        </div>
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.type" placeholder="All Types" class="w-36">
            <option value="">All Types</option>
            <option value="hotspot">Hotspot</option>
            <option value="pppoe">PPPoE</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.router" placeholder="All Routers" class="w-40">
            <option value="">All Routers</option>
            <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} connections</BaseBadge>
        </div>
      </div>
    </template>

    <!-- Content -->
    <PageContent :padding="false">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="8" />
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="p-6">
        <BaseAlert variant="danger" :title="error" dismissible>
          <div class="mt-2">
            <BaseButton @click="fetchConnections" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          title="No active connections"
          description="No connections are currently active on the network."
          icon="Activity"
        />
      </div>

      <!-- Connections Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Connection</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Type</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Router</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Bandwidth</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="conn in paginatedData"
                  :key="conn.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(conn) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ conn.username }}</div>
                        <div class="text-xs text-slate-500">{{ conn.user_name || 'N/A' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900 font-mono">{{ conn.ip_address }}</div>
                    <div class="text-xs text-slate-500 font-mono">{{ conn.mac_address }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="conn.type === 'hotspot' ? 'purple' : 'info'">
                      {{ conn.type }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-900">{{ conn.router_name }}</td>
                  <td class="px-6 py-4">
                    <div class="space-y-1">
                      <div class="flex items-center gap-2 text-xs">
                        <ArrowDown class="w-3 h-3 text-green-600" />
                        <span class="text-green-600 font-medium">{{ formatBytes(conn.download_rate) }}/s</span>
                      </div>
                      <div class="flex items-center gap-2 text-xs">
                        <ArrowUp class="w-3 h-3 text-blue-600" />
                        <span class="text-blue-600 font-medium">{{ formatBytes(conn.upload_rate) }}/s</span>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDuration(conn.uptime) }}</div>
                    <div class="text-xs text-slate-500">{{ formatDateTime(conn.connected_at) }}</div>
                  </td>
                  <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewDetails(conn)" variant="ghost" size="sm" title="View Details">
                        <Eye class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="disconnectUser(conn)" variant="danger" size="sm">
                        <Power class="w-3 h-3" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Footer -->
    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} connections
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :total-items="filteredData.length"
      />
    </PageFooter>

    <!-- Connection Details Overlay -->
    <SlideOverlay v-model="showDetailsOverlay" title="Connection Details" :subtitle="selectedConnection?.username" icon="Activity" width="480px">
      <div v-if="selectedConnection" class="space-y-6 p-6">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500 mb-1">Username</div>
            <div class="text-sm font-medium text-slate-900">{{ selectedConnection.username }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Type</div>
            <BaseBadge :variant="selectedConnection.type === 'hotspot' ? 'purple' : 'info'">{{ selectedConnection.type }}</BaseBadge>
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
            @click="disconnectUser(selectedConnection); closeDetails()"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors"
          >
            Disconnect
          </button>
        </div>
      </template>
    </SlideOverlay>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { 
  Activity, RefreshCw, Download, X, Eye, Power,
  Wifi, Zap, ArrowDown, ArrowUp
} from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import PageFooter from '@/modules/common/components/layout/templates/PageFooter.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Monitoring', to: '/dashboard/monitoring' },
  { label: 'Live Connections' }
]

const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const connections = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedConnection = ref(null)

const filters = ref({
  type: '',
  router: ''
})

const routers = ref([])

const stats = computed(() => {
  const total = connections.value.length
  const active = connections.value.filter(c => c.download_rate > 0 || c.upload_rate > 0).length
  const bandwidth = connections.value.reduce((sum, c) => sum + c.download_rate + c.upload_rate, 0)
  const download = connections.value.reduce((sum, c) => sum + c.download_rate, 0)
  const upload = connections.value.reduce((sum, c) => sum + c.upload_rate, 0)
  
  return { total, active, bandwidth, download, upload }
})

const filteredData = computed(() => {
  let data = connections.value

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(c =>
      c.username.toLowerCase().includes(query) ||
      c.ip_address.includes(query) ||
      c.mac_address.toLowerCase().includes(query)
    )
  }

  if (filters.value.type) {
    data = data.filter(c => c.type === filters.value.type)
  }

  if (filters.value.router) {
    data = data.filter(c => c.router_name === routers.value.find(r => r.id === filters.value.router)?.name)
  }

  return data
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const paginationInfo = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value + 1
  const end = Math.min(start + itemsPerPage.value - 1, filteredData.value.length)
  return { start, end, total: filteredData.value.length }
})

const hasActiveFilters = computed(() => filters.value.type || filters.value.router || searchQuery.value)

const fetchRouters = async () => {
  try {
    const response = await axios.get('/routers')
    const data = Array.isArray(response.data) ? response.data : (response.data?.data || [])
    routers.value = data.map(r => ({ id: r.id, name: r.name }))
  } catch (err) {
    console.warn('Failed to fetch routers for filter:', err.message)
  }
}

const fetchConnections = async () => {
  const isInitial = connections.value.length === 0
  if (isInitial) {
    loading.value = true
    error.value = null
  } else {
    refreshing.value = true
  }
  
  try {
    const [pppoeRes, hotspotRes] = await Promise.allSettled([
      axios.get('/pppoe/sessions/live'),
      axios.get('/hotspot/sessions')
    ])

    const merged = []

    if (pppoeRes.status === 'fulfilled') {
      const pppoeData = pppoeRes.value.data?.sessions || pppoeRes.value.data?.data || []
      pppoeData.forEach((s, i) => {
        merged.push({
          id: `pppoe-${s.id || i}`,
          username: s.username || s.name || 'Unknown',
          user_name: s.caller_id || s.name || '',
          ip_address: s.address || s.ip_address || '',
          mac_address: s.caller_id || s.mac_address || '',
          type: 'pppoe',
          router_name: s.router?.name || s.router_name || 'Unknown',
          router_id: s.router?.id || s.router_id || null,
          download_rate: s.tx_byte ? Number(s.tx_byte) : (s.download_rate || 0),
          upload_rate: s.rx_byte ? Number(s.rx_byte) : (s.upload_rate || 0),
          uptime: s.uptime_seconds || s.uptime || 0,
          connected_at: s.started_at || s.created_at || new Date().toISOString(),
          service: s.service || '',
          _raw: s
        })
      })
    }

    if (hotspotRes.status === 'fulfilled') {
      const hotspotData = hotspotRes.value.data?.sessions || hotspotRes.value.data?.data || []
      hotspotData.forEach((s, i) => {
        merged.push({
          id: `hotspot-${s.id || i}`,
          username: s.username || s.user || 'Unknown',
          user_name: s.name || s.user_name || '',
          ip_address: s.address || s.ip_address || '',
          mac_address: s.mac_address || '',
          type: 'hotspot',
          router_name: s.router?.name || s.router_name || 'Unknown',
          router_id: s.router?.id || s.router_id || null,
          download_rate: s.bytes_out ? Number(s.bytes_out) : (s.download_rate || 0),
          upload_rate: s.bytes_in ? Number(s.bytes_in) : (s.upload_rate || 0),
          uptime: s.uptime_seconds || s.uptime || 0,
          connected_at: s.started_at || s.created_at || new Date().toISOString(),
          _raw: s
        })
      })
    }

    connections.value = merged

    if (pppoeRes.status === 'rejected' && hotspotRes.status === 'rejected') {
      if (isInitial) {
        error.value = 'Failed to load live connections. Please check your network.'
      }
    }
  } catch (err) {
    if (isInitial) {
      error.value = err.response?.data?.message || 'Failed to load connections.'
    }
    console.error('fetchConnections error:', err)
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

const refreshConnections = async () => {
  await fetchConnections()
}

const clearFilters = () => {
  filters.value = { type: '', router: '' }
  searchQuery.value = ''
}

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

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

const viewDetails = (conn) => {
  selectedConnection.value = conn
  showDetailsOverlay.value = true
}

const closeDetails = () => {
  showDetailsOverlay.value = false
  selectedConnection.value = null
}

const disconnectUser = async (conn) => {
  if (!confirm(`Disconnect ${conn.username}?`)) return
  
  try {
    if (conn.type === 'pppoe') {
      await axios.post('/pppoe/sessions/disconnect', { session_id: conn._raw?.id, username: conn.username })
    } else {
      const userId = conn._raw?.user_id || conn._raw?.id
      if (userId) {
        await axios.post(`/hotspot/users/${userId}/disconnect`)
      }
    }
    connections.value = connections.value.filter(c => c.id !== conn.id)
  } catch (err) {
    console.error('Disconnect error:', err)
    alert(err.response?.data?.message || 'Failed to disconnect user')
  }
}

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

let refreshInterval

onMounted(() => {
  fetchRouters()
  fetchConnections()
  refreshInterval = setInterval(fetchConnections, 15000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>
