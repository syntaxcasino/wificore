<template>
  <DataViewContainer
    title="System Updates"
    subtitle="Manage infrastructure, servers, routers, and access point updates"
    color-theme="blue"
    :loading="loading"
    @refresh="fetchUpdates"
  >
    <template #icon>
      <Server class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <template #actions>
      <BaseButton @click="checkForUpdates" variant="primary" size="sm">
        <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': checking }" />
        Check for Updates
      </BaseButton>
    </template>

    <!-- Stats Grid -->
    <template #stats>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 px-4 md:px-6 py-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Servers</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.servers }}</div>
            </div>
            <Server class="w-6 h-6 text-blue-600" />
          </div>
          <div class="mt-2 text-xs text-blue-700">
            <span v-if="stats.serverUpdates > 0" class="font-medium">{{ stats.serverUpdates }} updates</span>
            <span v-else>Up to date</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-lg p-4 border border-emerald-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-emerald-600 font-medium mb-1">Routers</div>
              <div class="text-2xl font-bold text-emerald-900">{{ stats.routers }}</div>
            </div>
            <Router class="w-6 h-6 text-emerald-600" />
          </div>
          <div class="mt-2 text-xs text-emerald-700">
            <span v-if="stats.routerUpdates > 0" class="font-medium">{{ stats.routerUpdates }} updates</span>
            <span v-else>Up to date</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-violet-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Access Points</div>
              <div class="text-2xl font-bold text-purple-900">{{ stats.accessPoints }}</div>
            </div>
            <Wifi class="w-6 h-6 text-purple-600" />
          </div>
          <div class="mt-2 text-xs text-purple-700">
            <span v-if="stats.apUpdates > 0" class="font-medium">{{ stats.apUpdates }} updates</span>
            <span v-else>Up to date</span>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Last Check</div>
              <div class="text-lg font-bold text-amber-900">{{ lastCheckText }}</div>
            </div>
            <Clock class="w-6 h-6 text-amber-600" />
          </div>
          <div class="mt-2 text-xs text-amber-700">
            {{ lastCheckTime }}
          </div>
        </div>
      </div>
    </template>

    <!-- Content -->
    <div class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Update Types Tabs -->
      <div class="flex items-center gap-2 mb-4 border-b border-slate-200">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="activeTab === tab.id ? 'border-blue-500 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
          class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Servers Tab -->
      <div v-if="activeTab === 'servers'" class="space-y-4">
        <BaseCard v-for="server in servers" :key="server.id" class="hover:shadow-md transition-shadow">
          <div class="p-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <Server class="w-6 h-6 text-blue-600" />
              </div>
              <div>
                <h4 class="font-semibold text-slate-900">{{ server.name }}</h4>
                <p class="text-sm text-slate-500">{{ server.type }} • {{ server.ip }}</p>
                <div class="flex items-center gap-2 mt-1">
                  <span :class="server.status === 'online' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'" class="px-2 py-0.5 rounded-full text-xs font-medium">
                    {{ server.status }}
                  </span>
                  <span class="text-xs text-slate-400">v{{ server.current_version }}</span>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div v-if="server.update_available" class="text-right">
                <div class="text-sm font-medium text-amber-600">v{{ server.latest_version }} available</div>
                <div class="text-xs text-slate-500">{{ server.update_description }}</div>
              </div>
              <button
                v-if="server.update_available"
                @click="updateServer(server)"
                :disabled="server.updating"
                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
              >
                <span v-if="server.updating">Updating...</span>
                <span v-else>Update</span>
              </button>
              <span v-else class="text-sm text-emerald-600 font-medium">Up to date</span>
            </div>
          </div>
        </BaseCard>
      </div>

      <!-- Routers Tab -->
      <div v-if="activeTab === 'routers'" class="space-y-4">
        <BaseCard v-for="router in routers" :key="router.id" class="hover:shadow-md transition-shadow">
          <div class="p-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 bg-emerald-100 rounded-lg flex items-center justify-center">
                <Router class="w-6 h-6 text-emerald-600" />
              </div>
              <div>
                <h4 class="font-semibold text-slate-900">{{ router.name }}</h4>
                <p class="text-sm text-slate-500">{{ router.model }} • {{ router.ip_address }}</p>
                <div class="flex items-center gap-2 mt-1">
                  <EntityStatusBadge :status="router.status" size="sm" />
                  <span class="text-xs text-slate-400">RouterOS v{{ router.current_ros_version }}</span>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div v-if="router.update_available" class="text-right">
                <div class="text-sm font-medium text-amber-600">v{{ router.latest_ros_version }} available</div>
                <div class="text-xs text-slate-500">{{ router.update_changelog }}</div>
              </div>
              <button
                v-if="router.update_available"
                @click="updateRouter(router)"
                :disabled="router.updating"
                class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors"
              >
                <span v-if="router.updating">Updating...</span>
                <span v-else>Update</span>
              </button>
              <button
                @click="viewRouterDetails(router)"
                class="px-3 py-2 text-sm font-medium text-slate-700 bg-slate-100 rounded-lg hover:bg-slate-200 transition-colors"
              >
                Details
              </button>
            </div>
          </div>
        </BaseCard>
      </div>

      <!-- Access Points Tab -->
      <div v-if="activeTab === 'access-points'" class="space-y-4">
        <BaseCard v-for="ap in accessPoints" :key="ap.id" class="hover:shadow-md transition-shadow">
          <div class="p-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <Wifi class="w-6 h-6 text-purple-600" />
              </div>
              <div>
                <h4 class="font-semibold text-slate-900">{{ ap.name }}</h4>
                <p class="text-sm text-slate-500">{{ ap.model }} • {{ ap.mac_address }}</p>
                <div class="flex items-center gap-2 mt-1">
                  <EntityStatusBadge :status="ap.status" size="sm" />
                  <span class="text-xs text-slate-400">Firmware v{{ ap.current_firmware }}</span>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div v-if="ap.update_available" class="text-right">
                <div class="text-sm font-medium text-amber-600">v{{ ap.latest_firmware }} available</div>
              </div>
              <button
                v-if="ap.update_available"
                @click="updateAccessPoint(ap)"
                :disabled="ap.updating"
                class="px-4 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors"
              >
                <span v-if="ap.updating">Updating...</span>
                <span v-else>Update</span>
              </button>
              <span v-else class="text-sm text-emerald-600 font-medium">Up to date</span>
            </div>
          </div>
        </BaseCard>
      </div>

      <!-- Update History Tab -->
      <div v-if="activeTab === 'history'" class="space-y-4">
        <BaseCard>
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Date</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Component</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Name</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">From</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">To</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="update in updateHistory" :key="update.id" class="hover:bg-slate-50">
                  <td class="px-4 py-3 text-sm text-slate-900">{{ formatDate(update.created_at) }}</td>
                  <td class="px-4 py-3 text-sm text-slate-600 capitalize">{{ update.component_type }}</td>
                  <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ update.component_name }}</td>
                  <td class="px-4 py-3 text-sm text-slate-500">v{{ update.old_version }}</td>
                  <td class="px-4 py-3 text-sm text-slate-900">v{{ update.new_version }}</td>
                  <td class="px-4 py-3">
                    <span :class="getUpdateStatusClass(update.status)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                      {{ update.status }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </div>
  </DataViewContainer>

  <!-- Router Details Overlay -->
  <SlideOverlay v-model="showRouterDetails" title="Router Update Details" subtitle="View firmware changelog" icon="Router" width="480px">
    <div v-if="selectedRouter" class="space-y-4">
      <div class="bg-slate-50 rounded-lg p-4">
        <h4 class="font-semibold text-slate-900 mb-2">{{ selectedRouter.name }}</h4>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span class="text-slate-500">Current Version:</span>
            <span class="ml-2 font-medium">RouterOS v{{ selectedRouter.current_ros_version }}</span>
          </div>
          <div>
            <span class="text-slate-500">Latest Version:</span>
            <span class="ml-2 font-medium text-amber-600">v{{ selectedRouter.latest_ros_version }}</span>
          </div>
        </div>
      </div>
      <div>
        <h4 class="font-semibold text-slate-900 mb-2">Changelog</h4>
        <div class="bg-slate-50 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-line">{{ selectedRouter.changelog || 'No changelog available' }}</div>
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button @click="showRouterDetails = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-200 shadow-sm overflow-hidden flex-col50">Close</button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Server, Router, Wifi, RefreshCw, Clock } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const loading = ref(false)
const checking = ref(false)
const activeTab = ref('servers')
const showRouterDetails = ref(false)
const selectedRouter = ref(null)

const tabs = [
  { id: 'servers', label: 'Servers' },
  { id: 'routers', label: 'Routers' },
  { id: 'access-points', label: 'Access Points' },
  { id: 'history', label: 'Update History' }
]

const servers = ref([])
const routers = ref([])
const accessPoints = ref([])
const updateHistory = ref([])
const lastCheck = ref(null)

const stats = computed(() => ({
  servers: servers.value.length,
  routers: routers.value.length,
  accessPoints: accessPoints.value.length,
  serverUpdates: servers.value.filter(s => s.update_available).length,
  routerUpdates: routers.value.filter(r => r.update_available).length,
  apUpdates: accessPoints.value.filter(ap => ap.update_available).length
}))

const lastCheckText = computed(() => {
  if (!lastCheck.value) return 'Never'
  const diff = (new Date() - new Date(lastCheck.value)) / 1000 / 60
  if (diff < 1) return 'Just now'
  if (diff < 60) return `${Math.floor(diff)}m ago`
  return `${Math.floor(diff / 60)}h ago`
})

const lastCheckTime = computed(() => {
  if (!lastCheck.value) return ''
  return new Date(lastCheck.value).toLocaleString()
})

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const getUpdateStatusClass = (status) => {
  const classes = {
    success: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
    pending: 'bg-amber-100 text-amber-700',
    in_progress: 'bg-blue-100 text-blue-700'
  }
  return classes[status] || 'bg-slate-100 text-slate-700'
}

const fetchUpdates = async () => {
  loading.value = true
  try {
    const [serversRes, routersRes, apsRes, historyRes] = await Promise.all([
      axios.get('/system-updates/servers').catch(() => ({ data: { servers: [] } })),
      axios.get('/system-updates/routers').catch(() => ({ data: { routers: [] } })),
      axios.get('/system-updates/access-points').catch(() => ({ data: { access_points: [] } })),
      axios.get('/system-updates/history').catch(() => ({ data: { history: [] } }))
    ])
    servers.value = serversRes.data?.servers || []
    routers.value = routersRes.data?.routers || []
    accessPoints.value = apsRes.data?.access_points || []
    updateHistory.value = historyRes.data?.history || []
    lastCheck.value = new Date().toISOString()
  } catch (err) {
    console.error('fetchUpdates error:', err)
  } finally {
    loading.value = false
  }
}

const checkForUpdates = async () => {
  checking.value = true
  try {
    await axios.post('/system-updates/check')
    await fetchUpdates()
  } catch (err) {
    console.error('checkForUpdates error:', err)
  } finally {
    checking.value = false
  }
}

const updateServer = async (server) => {
  server.updating = true
  try {
    await axios.post(`/system-updates/servers/${server.id}/update`)
    await fetchUpdates()
  } catch (err) {
    console.error('updateServer error:', err)
    alert(err.response?.data?.message || 'Failed to update server')
  } finally {
    server.updating = false
  }
}

const updateRouter = async (router) => {
  router.updating = true
  try {
    await axios.post(`/system-updates/routers/${router.id}/update`)
    await fetchUpdates()
  } catch (err) {
    console.error('updateRouter error:', err)
    alert(err.response?.data?.message || 'Failed to update router')
  } finally {
    router.updating = false
  }
}

const updateAccessPoint = async (ap) => {
  ap.updating = true
  try {
    await axios.post(`/system-updates/access-points/${ap.id}/update`)
    await fetchUpdates()
  } catch (err) {
    console.error('updateAccessPoint error:', err)
    alert(err.response?.data?.message || 'Failed to update access point')
  } finally {
    ap.updating = false
  }
}

const viewRouterDetails = (router) => {
  selectedRouter.value = router
  showRouterDetails.value = true
}

onMounted(() => {
  fetchUpdates()
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
