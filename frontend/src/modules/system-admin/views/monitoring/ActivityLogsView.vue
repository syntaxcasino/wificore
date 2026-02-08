<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Activity Logs</h1>
        <p class="text-sm text-gray-500 mt-1">Platform-wide activity and audit trail</p>
      </div>
      <button
        @click="fetchLogs"
        :disabled="loading"
        class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors disabled:opacity-50"
      >
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-gray-500">
        <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
        Loading activity logs...
      </div>
      <div v-else-if="error" class="p-8 text-center text-red-500">
        {{ error }}
        <button @click="fetchLogs" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
      </div>
      <template v-else>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Time</th>
              <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">User</th>
              <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Action</th>
              <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="log in logs" :key="log.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ formatDate(log.created_at) }}</td>
              <td class="px-4 py-3 text-sm text-gray-900">{{ log.user?.name || log.causer?.name || log.username || '-' }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="actionClass(log.action || log.event || log.description)"
                >
                  {{ log.action || log.event || log.description || '-' }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-500 max-w-xs truncate">
                {{ log.description || log.properties ? JSON.stringify(log.properties || log.details) : '-' }}
              </td>
            </tr>
            <tr v-if="logs.length === 0">
              <td colspan="4" class="px-4 py-8 text-center text-gray-400 text-sm">No activity logs found</td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="pagination.lastPage > 1" class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-gray-50">
          <div class="text-xs text-gray-500">
            Showing {{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}
          </div>
          <div class="flex gap-1">
            <button
              v-for="page in Math.min(pagination.lastPage, 10)"
              :key="page"
              @click="currentPage = page; fetchLogs()"
              class="px-3 py-1 text-xs rounded-md transition-colors"
              :class="page === currentPage ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50'"
            >
              {{ page }}
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw } from 'lucide-vue-next'

const logs = ref([])
const loading = ref(true)
const error = ref(null)
const currentPage = ref(1)
const pagination = ref({ total: 0, from: 0, to: 0, lastPage: 1 })

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
