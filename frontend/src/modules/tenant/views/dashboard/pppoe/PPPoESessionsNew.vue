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
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search sessions by username, IP..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.profile" placeholder="All Profiles" class="w-40">
            <option value="">All Profiles</option>
            <option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
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
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Profile</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Usage</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Speed</th>
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
                    <div class="text-sm text-slate-900">{{ session.framed_ip }}</div>
                    <div class="text-xs text-slate-500">{{ session.calling_station_id }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ session.profile?.name || 'N/A' }}</div>
                    <div class="text-xs text-slate-500">{{ session.profile?.speed || 'N/A' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDuration(session.duration) }}</div>
                    <div class="text-xs text-slate-500">Since {{ formatTime(session.start_time) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatBytes(session.input_octets + session.output_octets) }}</div>
                    <div class="text-xs text-slate-500">
                      <span class="text-green-600">↓ {{ formatBytes(session.input_octets) }}</span>
                      <span class="mx-1">•</span>
                      <span class="text-blue-600">↑ {{ formatBytes(session.output_octets) }}</span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                      <div class="flex-1">
                        <div class="text-xs text-slate-500 mb-1">
                          ↓ {{ formatBytes(session.download_speed) }}/s
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-1.5">
                          <div 
                            class="bg-gradient-to-r from-green-500 to-emerald-500 h-1.5 rounded-full transition-all duration-300"
                            :style="{ width: getSpeedPercentage(session.download_speed, session.profile?.max_download) + '%' }"
                          ></div>
                        </div>
                        <div class="text-xs text-slate-500 mt-1">
                          ↑ {{ formatBytes(session.upload_speed) }}/s
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-1.5">
                          <div 
                            class="bg-gradient-to-r from-blue-500 to-cyan-500 h-1.5 rounded-full transition-all duration-300"
                            :style="{ width: getSpeedPercentage(session.upload_speed, session.profile?.max_upload) + '%' }"
                          ></div>
                        </div>
                      </div>
                    </div>
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

// Filters
const filters = ref({
  profile: '',
  duration: ''
})

const profiles = ref([
  { id: 1, name: '5 Mbps', speed: '5/2 Mbps', max_download: 5242880, max_upload: 2097152 },
  { id: 2, name: '10 Mbps', speed: '10/5 Mbps', max_download: 10485760, max_upload: 5242880 },
  { id: 3, name: '20 Mbps', speed: '20/10 Mbps', max_download: 20971520, max_upload: 10485760 }
])

// Mock data
const mockSessions = [
  {
    id: 1,
    acct_session_id: 'pppoe_1234567890',
    username: 'pppoe_user001',
    user: { phone: '+254712345678', package: '10 Mbps Monthly' },
    framed_ip: '100.64.0.101',
    calling_station_id: 'pppoe-client-001',
    nas_ip_address: '192.168.1.1',
    profile: { id: 2, name: '10 Mbps', speed: '10/5 Mbps', max_download: 10485760, max_upload: 5242880 },
    start_time: new Date(Date.now() - 7200000),
    duration: 7200,
    input_octets: 2147483648,
    output_octets: 536870912,
    download_speed: 8388608,
    upload_speed: 4194304
  },
  {
    id: 2,
    acct_session_id: 'pppoe_0987654321',
    username: 'pppoe_user002',
    user: { phone: '+254723456789', package: '20 Mbps Monthly' },
    framed_ip: '100.64.0.102',
    calling_station_id: 'pppoe-client-002',
    nas_ip_address: '192.168.1.1',
    profile: { id: 3, name: '20 Mbps', speed: '20/10 Mbps', max_download: 20971520, max_upload: 10485760 },
    start_time: new Date(Date.now() - 14400000),
    duration: 14400,
    input_octets: 4294967296,
    output_octets: 1073741824,
    download_speed: 16777216,
    upload_speed: 8388608
  }
]

// Computed
const filteredData = computed(() => {
  let result = sessions.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(session => 
      session.username?.toLowerCase().includes(query) ||
      session.framed_ip?.includes(query) ||
      session.calling_station_id?.toLowerCase().includes(query)
    )
  }

  // Profile filter
  if (filters.value.profile) {
    result = result.filter(session => session.profile?.id === parseInt(filters.value.profile))
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

const hasActiveFilters = computed(() => filters.value.profile || filters.value.duration)

const totalSessions = computed(() => sessions.value.length)
const totalUsers = computed(() => new Set(sessions.value.map(s => s.username)).size)
const totalBandwidth = computed(() => sessions.value.reduce((sum, s) => sum + (s.download_speed || 0) + (s.upload_speed || 0), 0))

// Methods
const fetchSessions = async () => {
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    sessions.value = mockSessions
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
    await new Promise(resolve => setTimeout(resolve, 500))
    sessions.value = mockSessions
  } catch (err) {
    error.value = 'Failed to load active sessions. Please try again.'
    console.error('Error fetching sessions:', err)
  } finally {
    refreshing.value = false
  }
}

const clearFilters = () => {
  filters.value = {
    profile: '',
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
  if (!confirm(`Disconnect ${session.username}?`)) return
  
  try {
    console.log('Disconnecting session:', session.id)
    await new Promise(resolve => setTimeout(resolve, 500))
    sessions.value = sessions.value.filter(s => s.id !== session.id)
    showDetailsOverlay.value = false
  } catch (err) {
    console.error('Error disconnecting session:', err)
  }
}

const disconnectAll = async () => {
  if (!confirm(`Disconnect all ${totalSessions.value} active sessions?`)) return
  
  try {
    console.log('Disconnecting all sessions')
    await new Promise(resolve => setTimeout(resolve, 500))
    sessions.value = []
  } catch (err) {
    console.error('Error disconnecting all sessions:', err)
  }
}

// Lifecycle
onMounted(() => {
  fetchSessions()
  
  const interval = setInterval(refreshSessions, 5000)
  onUnmounted(() => clearInterval(interval))
})
</script>
