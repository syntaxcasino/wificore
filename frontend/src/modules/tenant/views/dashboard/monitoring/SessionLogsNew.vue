<template>
  <DataViewContainer
    title="Session Logs"
    subtitle="Track user session activities"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search logs..."
    :stats="[
      { color: 'bg-blue-500', value: stats.total },
      { color: 'bg-emerald-500', value: stats.logins },
      { color: 'bg-red-500', value: stats.logouts },
      { color: 'bg-amber-500', value: stats.errors }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchLogs"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <List class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="exportLogs" variant="secondary" size="sm" class="shrink-0">
        <Download class="w-4 h-4 mr-1" /> Export
      </BaseButton>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.event_type" placeholder="All Events" class="w-36">
        <option value="">All Events</option>
        <option value="login">Login</option>
        <option value="logout">Logout</option>
        <option value="timeout">Timeout</option>
        <option value="error">Error</option>
      </BaseSelect>
      <BaseSelect v-model="filters.timeRange" placeholder="All Time" class="w-36">
        <option value="">All Time</option>
        <option value="1h">Last Hour</option>
        <option value="24h">Last 24 Hours</option>
        <option value="7d">Last 7 Days</option>
        <option value="30d">Last 30 Days</option>
      </BaseSelect>
    </template>

    <!-- Loading Skeleton -->
    <DataSkeleton v-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="log in paginatedData"
          :key="log.id"
          :title="log.username"
          :subtitle="log.event_type"
          :meta-lines="[{ text: log.ip_address }, { text: formatDateTime(log.created_at) }]"
          :status="getLogStatus(log.event_type)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Time</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Username</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Event</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">IP Address</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">MAC Address</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Duration</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="log in paginatedData" :key="log.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDateTime(log.created_at) }}</td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <User class="w-4 h-4 text-slate-400" />
                    <span class="text-sm font-medium text-slate-900">{{ log.username }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <component :is="getEventIcon(log.event_type)" class="w-4 h-4" :class="getEventIconColor(log.event_type)" />
                    <span class="text-sm text-slate-700 capitalize">{{ log.event_type }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-sm font-mono text-slate-600">{{ log.ip_address || '—' }}</td>
                <td class="px-6 py-4 text-sm font-mono text-slate-600">{{ log.mac_address || '—' }}</td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="getLogStatus(log.event_type)" size="sm" />
                </td>
                <td class="px-6 py-4 text-sm text-slate-600 hidden lg:table-cell">{{ formatDuration(log.session_duration) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="logs" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Logs Found' : 'No Session Logs'"
      :description="searchQuery ? 'No logs match your search criteria.' : 'No session activity has been recorded yet.'"
      icon="list"
      color-theme="blue"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      @clear="searchQuery = ''"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { List, Download, User, LogIn, LogOut, Clock, AlertCircle } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

// State
const loading = ref(false)
const logs = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

const filters = ref({ event_type: '', timeRange: '' })

// Computed
const stats = computed(() => ({
  total: logs.value.length,
  logins: logs.value.filter(l => l.event_type === 'login').length,
  logouts: logs.value.filter(l => l.event_type === 'logout').length,
  errors: logs.value.filter(l => l.event_type === 'error').length
}))

const filteredData = computed(() => {
  let data = logs.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(l =>
      l.username.toLowerCase().includes(query) ||
      (l.ip_address && l.ip_address.includes(query)) ||
      (l.mac_address && l.mac_address.toLowerCase().includes(query))
    )
  }
  if (filters.value.event_type) {
    data = data.filter(l => l.event_type === filters.value.event_type)
  }
  if (filters.value.timeRange) {
    const now = new Date()
    const ranges = { '1h': 60 * 60 * 1000, '24h': 24 * 60 * 60 * 1000, '7d': 7 * 24 * 60 * 60 * 1000, '30d': 30 * 24 * 60 * 60 * 1000 }
    const ms = ranges[filters.value.timeRange]
    if (ms) {
      const cutoff = new Date(now - ms)
      data = data.filter(l => new Date(l.created_at) >= cutoff)
    }
  }
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.event_type || filters.value.timeRange || searchQuery.value)

// Helpers
const formatDateTime = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

const formatDuration = (seconds) => {
  if (!seconds) return '—'
  const hours = Math.floor(seconds / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  if (hours > 0) return `${hours}h ${mins}m`
  return `${mins}m ${seconds % 60}s`
}

const getEventIcon = (eventType) => {
  const icons = { login: LogIn, logout: LogOut, timeout: Clock, error: AlertCircle }
  return icons[eventType] || Clock
}

const getEventIconColor = (eventType) => {
  const colors = { login: 'text-emerald-600', logout: 'text-blue-600', timeout: 'text-amber-600', error: 'text-red-600' }
  return colors[eventType] || 'text-slate-600'
}

const getLogStatus = (eventType) => {
  const statuses = { login: 'success', logout: 'info', timeout: 'warning', error: 'error' }
  return statuses[eventType] || 'default'
}

// Actions
const fetchLogs = async () => {
  loading.value = true
  try {
    const response = await axios.get('/monitoring/session-logs', { params: { per_page: 500 } })
    const data = response.data?.logs?.data || response.data?.logs || response.data?.data || []
    logs.value = data.map(l => ({
      id: l.id,
      username: l.username || 'Unknown',
      event_type: l.event_type || 'unknown',
      ip_address: l.ip_address || null,
      mac_address: l.mac_address || null,
      session_duration: l.session_duration || null,
      created_at: l.created_at || new Date().toISOString()
    }))
  } catch (err) {
    console.error('fetchLogs error:', err)
  } finally {
    loading.value = false
  }
}

const exportLogs = () => {
  const csv = [
    ['Time', 'Username', 'Event', 'IP Address', 'MAC Address', 'Duration'].join(','),
    ...logs.value.map(l => [
      l.created_at,
      l.username,
      l.event_type,
      l.ip_address || '',
      l.mac_address || '',
      l.session_duration || ''
    ].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `session-logs-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(fetchLogs)
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
