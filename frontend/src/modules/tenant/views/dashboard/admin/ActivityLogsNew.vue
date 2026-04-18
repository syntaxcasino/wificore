<template>
  <DataViewContainer
    title="Activity Logs"
    subtitle="Monitor system activity and user actions"
    color-theme="purple"
    v-model:search-model="searchQuery"
    search-placeholder="Search logs..."
    :loading="loading"
    @refresh="fetchLogs"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="exportLogs" variant="outline" size="sm">
        <Download class="w-4 h-4 mr-1.5" /> Export
      </BaseButton>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.action" placeholder="All Actions" class="w-36">
        <option value="">All Actions</option>
        <option value="create">Create</option>
        <option value="update">Update</option>
        <option value="delete">Delete</option>
        <option value="login">Login</option>
      </BaseSelect>
      <BaseSelect v-model="filters.user" placeholder="All Users" class="w-36">
        <option value="">All Users</option>
        <option value="admin">Admin</option>
        <option value="support">Support</option>
        <option value="user">User</option>
      </BaseSelect>
      <BaseSelect v-model="filters.timeRange" placeholder="Time Range" class="w-36">
        <option value="">All Time</option>
        <option value="today">Today</option>
        <option value="week">Last 7 Days</option>
        <option value="month">Last 30 Days</option>
      </BaseSelect>
    </template>

    <!-- Data Content -->
    <div v-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Desktop Timeline -->
      <div class="hidden md:flex bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-y-auto flex-1 min-h-0">
          <div class="relative">
            <div class="absolute left-8 top-0 bottom-0 w-px bg-slate-200"></div>
            <div v-for="(log, index) in paginatedData" :key="log.id" class="relative pl-8 pr-6 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
              <div class="absolute left-6 top-5 w-5 h-5 rounded-full bg-white border-2 z-10" :class="getActionBorder(log.action)">
                <div class="w-full h-full rounded-full flex items-center justify-center">
                  <component :is="getActionIcon(log.action)" class="w-3 h-3" :class="getActionColor(log.action)" />
                </div>
              </div>
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ log.description }}</p>
                  <div class="flex items-center gap-2 mt-1">
                    <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-600">{{ log.action }}</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">by {{ log.user }}</span>
                    <span v-if="log.ip_address" class="text-xs text-slate-400 font-mono">{{ log.ip_address }}</span>
                  </div>
                </div>
                <span class="text-xs text-slate-500 whitespace-nowrap">{{ formatDateTime(log.timestamp) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <div v-for="log in paginatedData" :key="log.id" class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 shadow-sm p-4">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="getActionBg(log.action)">
              <component :is="getActionIcon(log.action)" class="w-5 h-5" :class="getActionColor(log.action)" />
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ log.description }}</p>
              <div class="flex items-center gap-2 mt-1 flex-wrap">
                <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-600">{{ log.action }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">by {{ log.user }}</span>
              </div>
              <div class="flex items-center justify-between mt-2">
                <span v-if="log.ip_address" class="text-xs text-slate-400 font-mono">{{ log.ip_address }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ formatDateTime(log.timestamp) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="logs" class="mt-auto" />
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery || hasActiveFilters ? 'No Logs Found' : 'No Activity Logs'"
      :description="searchQuery || hasActiveFilters ? 'No activity logs match your search criteria.' : 'System activity logs will appear here.'"
      icon="history"
      color-theme="purple"
      :show-clear="!!searchQuery || hasActiveFilters"
      :has-filters="hasActiveFilters"
      @clear="clearFilters"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { History, Plus, Edit2, Trash2, LogIn, Download } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import { useToast } from '@/modules/common/composables/useToast.js'

const { info: showInfo } = useToast()

// State
const loading = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)

const filters = ref({ action: '', user: '', timeRange: '' })

// Mock data for now - in production, fetch from API
const logs = ref([
  { id: 1, action: 'create', description: 'Created new user John Doe', user: 'admin', ip_address: '192.168.1.10', timestamp: new Date().toISOString() },
  { id: 2, action: 'update', description: 'Updated package Premium', user: 'support', ip_address: '192.168.1.15', timestamp: new Date(Date.now() - 3600000).toISOString() },
  { id: 3, action: 'delete', description: 'Deleted invoice #1234', user: 'admin', ip_address: '192.168.1.10', timestamp: new Date(Date.now() - 7200000).toISOString() },
  { id: 4, action: 'login', description: 'User logged in', user: 'support', ip_address: '192.168.1.20', timestamp: new Date(Date.now() - 86400000).toISOString() },
  { id: 5, action: 'create', description: 'Added new router MikroTik', user: 'admin', ip_address: '192.168.1.10', timestamp: new Date(Date.now() - 9000000).toISOString() }
])

// Computed
const filteredData = computed(() => {
  let data = logs.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(l => l.description.toLowerCase().includes(query))
  }
  if (filters.value.action) data = data.filter(l => l.action === filters.value.action)
  if (filters.value.user) data = data.filter(l => l.user === filters.value.user)
  if (filters.value.timeRange) {
    const now = new Date()
    data = data.filter(l => {
      const logDate = new Date(l.timestamp)
      if (filters.value.timeRange === 'today') return logDate.toDateString() === now.toDateString()
      if (filters.value.timeRange === 'week') return now - logDate <= 7 * 24 * 60 * 60 * 1000
      if (filters.value.timeRange === 'month') return now - logDate <= 30 * 24 * 60 * 60 * 1000
      return true
    })
  }
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.action || filters.value.user || filters.value.timeRange)

// Helpers
const getActionBg = (action) => ({ create: 'bg-emerald-100', update: 'bg-blue-100', delete: 'bg-red-100', login: 'bg-purple-100' }[action] || 'bg-slate-100')
const getActionColor = (action) => ({ create: 'text-emerald-600', update: 'text-blue-600', delete: 'text-red-600', login: 'text-purple-600' }[action] || 'text-slate-600')
const getActionIcon = (action) => ({ create: Plus, update: Edit2, delete: Trash2, login: LogIn }[action] || Plus)
const getActionBorder = (action) => ({ create: 'border-emerald-500', update: 'border-blue-500', delete: 'border-red-500', login: 'border-purple-500' }[action] || 'border-slate-400')

const formatDateTime = (date) => {
  if (!date) return ''
  const d = new Date(date)
  return d.toLocaleDateString('en-GB') + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

const clearFilters = () => {
  searchQuery.value = ''
  filters.value = { action: '', user: '', timeRange: '' }
}

const fetchLogs = async () => {
  loading.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  loading.value = false
}

const exportLogs = () => showInfo('Export feature coming soon!')

onMounted(fetchLogs)
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
