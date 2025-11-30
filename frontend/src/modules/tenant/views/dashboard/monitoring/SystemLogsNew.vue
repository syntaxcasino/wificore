<template>
  <PageContainer>
    <PageHeader
      title="System Logs"
      subtitle="Monitor system events and activities"
      icon="FileText"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshLogs" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportLogs" variant="ghost">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
        <BaseButton @click="clearLogs" variant="danger">
          <Trash2 class="w-4 h-4 mr-1" />
          Clear Logs
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Logs</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <FileText class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Info</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.info }}</div>
            </div>
            <Info class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Warning</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.warning }}</div>
            </div>
            <AlertTriangle class="w-6 h-6 text-amber-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-red-50 to-rose-50 rounded-lg p-4 border border-red-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-red-600 font-medium mb-1">Error</div>
              <div class="text-2xl font-bold text-red-900">{{ stats.error }}</div>
            </div>
            <XCircle class="w-6 h-6 text-red-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Today</div>
              <div class="text-2xl font-bold text-purple-900">{{ stats.today }}</div>
            </div>
            <Calendar class="w-6 h-6 text-purple-600" />
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search logs..." />
        </div>
        
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.level" placeholder="All Levels" class="w-36">
            <option value="">All Levels</option>
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="error">Error</option>
            <option value="debug">Debug</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.category" placeholder="All Categories" class="w-40">
            <option value="">All Categories</option>
            <option value="auth">Authentication</option>
            <option value="system">System</option>
            <option value="network">Network</option>
            <option value="database">Database</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.period" placeholder="All Time" class="w-36">
            <option value="">All Time</option>
            <option value="today">Today</option>
            <option value="week">This Week</option>
            <option value="month">This Month</option>
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

    <!-- Content -->
    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="list" :rows="10" />
      </div>

      <div v-else-if="error" class="p-6">
        <BaseAlert variant="danger" :title="error" dismissible />
      </div>

      <div v-else-if="!filteredData.length" class="p-6">
        <BaseEmpty title="No logs found" description="No system logs match your criteria." icon="FileText" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="divide-y divide-slate-100">
            <div
              v-for="log in paginatedData"
              :key="log.id"
              class="p-4 hover:bg-slate-50 transition-colors cursor-pointer"
              @click="viewLog(log)"
            >
              <div class="flex items-start gap-4">
                <div class="flex-shrink-0 mt-1">
                  <div class="p-2 rounded-lg" :class="getLevelBg(log.level)">
                    <component :is="getLevelIcon(log.level)" class="w-4 h-4" :class="getLevelColor(log.level)" />
                  </div>
                </div>
                
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <BaseBadge :variant="getLevelVariant(log.level)" size="sm">
                      {{ log.level }}
                    </BaseBadge>
                    <BaseBadge variant="secondary" size="sm">
                      {{ log.category }}
                    </BaseBadge>
                    <span class="text-xs text-slate-500">{{ formatDateTime(log.timestamp) }}</span>
                  </div>
                  
                  <div class="text-sm font-medium text-slate-900 mb-1">{{ log.message }}</div>
                  
                  <div v-if="log.details" class="text-xs text-slate-600 font-mono bg-slate-50 p-2 rounded">
                    {{ log.details }}
                  </div>
                  
                  <div class="flex items-center gap-4 mt-2 text-xs text-slate-500">
                    <span>User: {{ log.user || 'System' }}</span>
                    <span>IP: {{ log.ip_address || 'N/A' }}</span>
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
  FileText, RefreshCw, Download, Trash2, X,
  Info, AlertTriangle, XCircle, Calendar
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
  { label: 'System Logs' }
]

const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const logs = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(20)

const filters = ref({
  level: '',
  category: '',
  period: ''
})

const mockLogs = Array.from({ length: 50 }, (_, i) => ({
  id: i + 1,
  level: ['info', 'warning', 'error', 'debug'][Math.floor(Math.random() * 4)],
  category: ['auth', 'system', 'network', 'database'][Math.floor(Math.random() * 4)],
  message: [
    'User login successful',
    'Database connection established',
    'Network timeout detected',
    'Configuration updated',
    'Payment processed',
    'Session expired',
    'Router connection failed',
    'Backup completed'
  ][Math.floor(Math.random() * 8)],
  details: Math.random() > 0.5 ? `Error code: ${Math.floor(Math.random() * 1000)}` : null,
  user: Math.random() > 0.3 ? `user${Math.floor(Math.random() * 10)}` : null,
  ip_address: Math.random() > 0.3 ? `192.168.1.${Math.floor(Math.random() * 255)}` : null,
  timestamp: new Date(Date.now() - Math.random() * 7 * 24 * 60 * 60 * 1000).toISOString()
}))

const stats = computed(() => {
  const total = logs.value.length
  const info = logs.value.filter(l => l.level === 'info').length
  const warning = logs.value.filter(l => l.level === 'warning').length
  const error = logs.value.filter(l => l.level === 'error').length
  const today = logs.value.filter(l => {
    const logDate = new Date(l.timestamp)
    const now = new Date()
    return logDate.toDateString() === now.toDateString()
  }).length
  
  return { total, info, warning, error, today }
})

const filteredData = computed(() => {
  let data = logs.value

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(l => l.message.toLowerCase().includes(query))
  }

  if (filters.value.level) {
    data = data.filter(l => l.level === filters.value.level)
  }

  if (filters.value.category) {
    data = data.filter(l => l.category === filters.value.category)
  }

  if (filters.value.period) {
    const now = new Date()
    data = data.filter(l => {
      const logDate = new Date(l.timestamp)
      switch (filters.value.period) {
        case 'today':
          return logDate.toDateString() === now.toDateString()
        case 'week':
          const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)
          return logDate >= weekAgo
        case 'month':
          return logDate.getMonth() === now.getMonth()
        default:
          return true
      }
    })
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

const hasActiveFilters = computed(() => filters.value.level || filters.value.category || filters.value.period || searchQuery.value)

const getLevelBg = (level) => {
  const bgs = {
    info: 'bg-green-100',
    warning: 'bg-amber-100',
    error: 'bg-red-100',
    debug: 'bg-slate-100'
  }
  return bgs[level] || bgs.info
}

const getLevelColor = (level) => {
  const colors = {
    info: 'text-green-600',
    warning: 'text-amber-600',
    error: 'text-red-600',
    debug: 'text-slate-600'
  }
  return colors[level] || colors.info
}

const getLevelIcon = (level) => {
  const icons = {
    info: Info,
    warning: AlertTriangle,
    error: XCircle,
    debug: FileText
  }
  return icons[level] || Info
}

const getLevelVariant = (level) => {
  const variants = {
    info: 'success',
    warning: 'warning',
    error: 'danger',
    debug: 'secondary'
  }
  return variants[level] || 'default'
}

const formatDateTime = (date) => {
  return new Date(date).toLocaleString()
}

const fetchLogs = async () => {
  loading.value = true
  error.value = null
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    logs.value = mockLogs
  } catch (err) {
    error.value = 'Failed to load logs.'
    console.error(err)
  } finally {
    loading.value = false
  }
}

const refreshLogs = async () => {
  refreshing.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    logs.value = mockLogs
  } finally {
    refreshing.value = false
  }
}

const clearFilters = () => {
  filters.value = { level: '', category: '', period: '' }
  searchQuery.value = ''
}

const viewLog = (log) => {
  console.log('View log:', log)
}

const exportLogs = () => {
  alert('Export feature coming soon!')
}

const clearLogs = () => {
  if (confirm('Clear all logs? This cannot be undone.')) {
    logs.value = []
  }
}

let refreshInterval

onMounted(() => {
  fetchLogs()
  refreshInterval = setInterval(refreshLogs, 30000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>
