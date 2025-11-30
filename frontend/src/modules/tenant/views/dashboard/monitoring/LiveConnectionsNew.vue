<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
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
    </PageHeader>

    <!-- Real-time Stats -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
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
    </div>

    <!-- Filters -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[300px] max-w-md">
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
    </div>

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
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { 
  Activity, RefreshCw, Download, X, Eye, Power,
  Wifi, Zap, ArrowDown, ArrowUp
} from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
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

const filters = ref({
  type: '',
  router: ''
})

const routers = ref([
  { id: 1, name: 'Router-01' },
  { id: 2, name: 'Router-02' },
  { id: 3, name: 'Router-03' }
])

const mockConnections = Array.from({ length: 25 }, (_, i) => ({
  id: i + 1,
  username: `user${i + 1}`,
  user_name: `User ${i + 1}`,
  ip_address: `192.168.1.${100 + i}`,
  mac_address: `00:11:22:33:44:${(i + 10).toString(16).padStart(2, '0')}`,
  type: i % 3 === 0 ? 'pppoe' : 'hotspot',
  router_name: `Router-0${(i % 3) + 1}`,
  download_rate: Math.floor(Math.random() * 5000000),
  upload_rate: Math.floor(Math.random() * 2000000),
  uptime: Math.floor(Math.random() * 7200),
  connected_at: new Date(Date.now() - Math.random() * 7200000).toISOString()
}))

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

const fetchConnections = async () => {
  loading.value = true
  error.value = null
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    connections.value = mockConnections
  } catch (err) {
    error.value = 'Failed to load connections.'
    console.error(err)
  } finally {
    loading.value = false
  }
}

const refreshConnections = async () => {
  refreshing.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    connections.value = mockConnections
  } finally {
    refreshing.value = false
  }
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
  console.log('View details:', conn)
}

const disconnectUser = async (conn) => {
  if (!confirm(`Disconnect ${conn.username}?`)) return
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    connections.value = connections.value.filter(c => c.id !== conn.id)
  } catch (err) {
    console.error(err)
  }
}

const exportData = () => {
  alert('Export feature coming soon!')
}

let refreshInterval

onMounted(() => {
  fetchConnections()
  refreshInterval = setInterval(refreshConnections, 10000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>
