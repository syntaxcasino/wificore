<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-slate-100">Activity Logs</h1>
        <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-1">Platform-wide activity and audit trail</p>
      </div>
      <button
        @click="fetchLogs"
        :disabled="loading"
        class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors disabled:opacity-50 self-start sm:self-auto"
      >
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden overflow-x-auto">
      <div v-if="loading" class="p-8 text-center text-gray-500 dark:text-slate-400">
        <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
        Loading activity logs...
      </div>
      <div v-else-if="error" class="p-8 text-center text-red-500">
        {{ error }}
        <button @click="fetchLogs" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
      </div>
      <template v-else>
        <table class="w-full min-w-[500px]">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Time</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">User</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Action</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase hidden sm:table-cell">Details</th>
              <th class="text-right px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">View</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr v-for="log in logs" :key="log.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors cursor-pointer" @click="openLogDetail(log)">
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">{{ formatDate(log.created_at) }}</td>
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-sm font-medium text-gray-900 dark:text-slate-100">{{ log.user?.name || log.causer?.name || log.username || '-' }}</td>
              <td class="px-3 sm:px-6 py-3 sm:py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="actionClass(log.action || log.event || log.description)"
                >
                  {{ log.action || log.event || log.description || '-' }}
                </span>
              </td>
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-xs text-gray-500 dark:text-slate-400 max-w-xs truncate hidden sm:table-cell">
                {{ log.description || (log.properties ? JSON.stringify(log.properties) : '-') }}
              </td>
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-right">
                <button @click.stop="openLogDetail(log)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View Details">
                  <Eye class="w-4 h-4" />
                </button>
              </td>
            </tr>
            <tr v-if="logs.length === 0">
              <td colspan="5" class="px-3 sm:px-6 py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No activity logs found</td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="pagination.lastPage > 1" class="flex items-center justify-between px-6 py-3 border-t border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
          <div class="text-xs text-gray-500 dark:text-slate-400">
            Showing {{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}
          </div>
          <div class="flex gap-1">
            <button
              v-for="page in Math.min(pagination.lastPage, 10)"
              :key="page"
              @click="currentPage = page; fetchLogs()"
              class="px-3 py-1 text-xs rounded-md transition-colors"
              :class="page === currentPage ? 'bg-blue-600 text-white' : 'bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-600'"
            >
              {{ page }}
            </button>
          </div>
        </div>
      </template>
    </div>

    <!-- Log Detail Overlay -->
    <SlideOverlay v-model="showLogOverlay" title="Activity Log Detail" subtitle="Full log entry information" icon="FileText" width="50%" @close="showLogOverlay = false">
      <div v-if="selectedLog" class="space-y-4">
        <div class="space-y-3">
          <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Time</span>
            <span class="text-sm text-gray-900 dark:text-slate-100">{{ formatDate(selectedLog.created_at) }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">User</span>
            <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ selectedLog.user?.name || selectedLog.causer?.name || selectedLog.username || '-' }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Action</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="actionClass(selectedLog.action || selectedLog.event || selectedLog.description)">
              {{ selectedLog.action || selectedLog.event || selectedLog.description || '-' }}
            </span>
          </div>
          <div v-if="selectedLog.description" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Description</span>
            <span class="text-sm text-gray-900 dark:text-slate-100 text-right max-w-[60%]">{{ selectedLog.description }}</span>
          </div>
          <div v-if="selectedLog.ip_address" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">IP Address</span>
            <span class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ selectedLog.ip_address }}</span>
          </div>
          <div v-if="selectedLog.user_agent" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">User Agent</span>
            <span class="text-xs text-gray-700 dark:text-slate-300 text-right max-w-[60%] break-all">{{ selectedLog.user_agent }}</span>
          </div>
        </div>
        <div v-if="selectedLog.properties || selectedLog.details">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Properties</h3>
          <div class="space-y-2">
            <div v-for="(val, prop) in (selectedLog.properties || selectedLog.details)" :key="prop" class="flex items-center justify-between p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
              <span class="text-sm font-medium text-gray-700 dark:text-slate-300 capitalize">{{ String(prop).replace(/_/g, ' ') }}</span>
              <span class="text-sm text-blue-700 text-right max-w-[60%] break-all">{{ typeof val === 'object' ? JSON.stringify(val) : val }}</span>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showLogOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const logs = ref([])
const loading = ref(true)
const error = ref(null)
const currentPage = ref(1)
const pagination = ref({ total: 0, from: 0, to: 0, lastPage: 1 })
const showLogOverlay = ref(false)
const selectedLog = ref(null)

const openLogDetail = (log) => {
  selectedLog.value = log
  showLogOverlay.value = true
}

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

const actionClass = (action) => {
  if (!action) return 'bg-gray-100 text-gray-700'
  const a = action.toLowerCase()
  if (a.includes('create') || a.includes('add')) return 'bg-green-100 text-green-700'
  if (a.includes('delete') || a.includes('remove') || a.includes('suspend')) return 'bg-red-100 text-red-700'
  if (a.includes('update') || a.includes('edit') || a.includes('change')) return 'bg-blue-100 text-blue-700'
  if (a.includes('login') || a.includes('auth')) return 'bg-purple-100 text-purple-700'
  return 'bg-gray-100 text-gray-700'
}

const fetchLogs = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/activity-logs', { params: { page: currentPage.value } })
    const data = res.data.data || res.data.logs || res.data
    if (Array.isArray(data)) {
      logs.value = data
    } else if (data.data) {
      logs.value = data.data
      pagination.value = {
        total: data.total || 0,
        from: data.from || 0,
        to: data.to || 0,
        lastPage: data.last_page || 1
      }
    } else {
      logs.value = []
    }
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load activity logs'
  } finally {
    loading.value = false
  }
}

onMounted(() => fetchLogs())
</script>
