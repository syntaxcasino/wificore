<template>
  <PageContainer>
    <PageHeader title="Session Logs" subtitle="Track user session activities" icon="List" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshLogs" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportLogs" variant="ghost">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
      </template>
    </PageHeader>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Logs</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <List class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Login Events</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.logins }}</div>
            </div>
            <LogIn class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-red-50 to-rose-50 rounded-lg p-4 border border-red-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-red-600 font-medium mb-1">Logout Events</div>
              <div class="text-2xl font-bold text-red-900">{{ stats.logouts }}</div>
            </div>
            <LogOut class="w-6 h-6 text-red-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Today</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.today }}</div>
            </div>
            <Calendar class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search logs..." />
        </div>
        
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.event" class="w-36">
            <option value="">All Events</option>
            <option value="login">Login</option>
            <option value="logout">Logout</option>
            <option value="disconnect">Disconnect</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.type" class="w-36">
            <option value="">All Types</option>
            <option value="hotspot">Hotspot</option>
            <option value="pppoe">PPPoE</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} logs</BaseBadge>
        </div>
      </div>
    </div>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="list" :rows="10" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="divide-y divide-slate-100">
            <div v-for="log in paginatedData" :key="log.id" class="p-4 hover:bg-slate-50 transition-colors">
              <div class="flex items-start gap-4">
                <div class="flex-shrink-0 mt-1">
                  <div class="p-2 rounded-lg" :class="getEventBg(log.event)">
                    <component :is="getEventIcon(log.event)" class="w-4 h-4" :class="getEventColor(log.event)" />
                  </div>
                </div>
                
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <BaseBadge :variant="getEventVariant(log.event)" size="sm">{{ log.event }}</BaseBadge>
                    <BaseBadge :variant="log.type === 'hotspot' ? 'purple' : 'info'" size="sm">{{ log.type }}</BaseBadge>
                    <span class="text-xs text-slate-500">{{ formatDateTime(log.timestamp) }}</span>
                  </div>
                  
                  <div class="text-sm font-medium text-slate-900 mb-1">{{ log.message }}</div>
                  
                  <div class="flex items-center gap-4 text-xs text-slate-500">
                    <span>User: {{ log.username }}</span>
                    <span>IP: {{ log.ip_address }}</span>
                    <span v-if="log.duration">Duration: {{ formatDuration(log.duration) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} logs
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { List, RefreshCw, Download, X, LogIn, LogOut, Calendar } from 'lucide-vue-next'
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

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Monitoring', to: '/dashboard/monitoring' },
  { label: 'Session Logs' }
]

const loading = ref(false)
const refreshing = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)

const filters = ref({ event: '', type: '' })

const logs = ref(Array.from({ length: 50 }, (_, i) => ({
  id: i + 1,
  event: ['login', 'logout', 'disconnect'][Math.floor(Math.random() * 3)],
  type: Math.random() > 0.5 ? 'hotspot' : 'pppoe',
  username: `user${Math.floor(Math.random() * 50) + 1}`,
  message: ['User logged in successfully', 'User logged out', 'Session disconnected'][Math.floor(Math.random() * 3)],
  ip_address: `192.168.1.${Math.floor(Math.random() * 255)}`,
  duration: Math.random() > 0.5 ? Math.floor(Math.random() * 7200) : null,
  timestamp: new Date(Date.now() - Math.random() * 7 * 24 * 60 * 60 * 1000).toISOString()
})))

const stats = computed(() => ({
  total: logs.value.length,
  logins: logs.value.filter(l => l.event === 'login').length,
  logouts: logs.value.filter(l => l.event === 'logout').length,
  today: logs.value.filter(l => new Date(l.timestamp).toDateString() === new Date().toDateString()).length
}))

const filteredData = computed(() => {
  let data = logs.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(l => l.username.toLowerCase().includes(query) || l.ip_address.includes(query))
  }
  if (filters.value.event) data = data.filter(l => l.event === filters.value.event)
  if (filters.value.type) data = data.filter(l => l.type === filters.value.type)
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

const hasActiveFilters = computed(() => filters.value.event || filters.value.type || searchQuery.value)

const getEventBg = (event) => ({ login: 'bg-green-100', logout: 'bg-red-100', disconnect: 'bg-amber-100' }[event] || 'bg-slate-100')
const getEventColor = (event) => ({ login: 'text-green-600', logout: 'text-red-600', disconnect: 'text-amber-600' }[event] || 'text-slate-600')
const getEventIcon = (event) => ({ login: LogIn, logout: LogOut, disconnect: X }[event] || List)
const getEventVariant = (event) => ({ login: 'success', logout: 'danger', disconnect: 'warning' }[event] || 'default')

const formatDateTime = (date) => new Date(date).toLocaleString()
const formatDuration = (seconds) => {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

const clearFilters = () => {
  filters.value = { event: '', type: '' }
  searchQuery.value = ''
}

const refreshLogs = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const exportLogs = () => alert('Export feature coming soon!')

onMounted(() => { loading.value = false })
</script>
