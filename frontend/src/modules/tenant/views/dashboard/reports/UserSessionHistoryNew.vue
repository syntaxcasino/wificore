<template>
  <PageContainer>
    <PageHeader title="User Session History" subtitle="View detailed user session records" icon="History" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshData" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportReport" variant="primary">
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
              <div class="text-xs text-blue-600 font-medium mb-1">Total Sessions</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <History class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Avg Duration</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.avgDuration }}h</div>
            </div>
            <Clock class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Total Data</div>
              <div class="text-2xl font-bold text-purple-900">{{ formatBytes(stats.totalData) }}</div>
            </div>
            <HardDrive class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Unique Users</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.uniqueUsers }}</div>
            </div>
            <Users class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search sessions..." />
        </div>
        <BaseSelect v-model="filters.period" class="w-40">
          <option value="today">Today</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
        </BaseSelect>
        <BaseSelect v-model="filters.type" class="w-36">
          <option value="">All Types</option>
          <option value="hotspot">Hotspot</option>
          <option value="pppoe">PPPoE</option>
        </BaseSelect>
      </div>
    </div>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="10" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Type</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Start Time</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Duration</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Data Used</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">IP Address</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="session in paginatedData" :key="session.id" class="border-b border-slate-100 hover:bg-blue-50/50">
                  <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ session.username }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="session.type === 'hotspot' ? 'purple' : 'info'" size="sm">{{ session.type }}</BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600">{{ formatDateTime(session.start_time) }}</td>
                  <td class="px-6 py-4 text-sm text-slate-900">{{ formatDuration(session.duration) }}</td>
                  <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ formatBytes(session.data_used) }}</td>
                  <td class="px-6 py-4 text-sm text-slate-600 font-mono">{{ session.ip_address }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} sessions
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed } from 'vue'
import { History, RefreshCw, Download, Clock, HardDrive, Users } from 'lucide-vue-next'
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
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Session History' }
]

const loading = ref(false)
const refreshing = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)

const filters = ref({ period: 'week', type: '' })

const stats = ref({
  total: 1247,
  avgDuration: 2.5,
  totalData: 524288000000,
  uniqueUsers: 456
})

const sessions = ref(Array.from({ length: 50 }, (_, i) => ({
  id: i + 1,
  username: `user${Math.floor(Math.random() * 100) + 1}`,
  type: Math.random() > 0.5 ? 'hotspot' : 'pppoe',
  start_time: new Date(Date.now() - Math.random() * 7 * 24 * 60 * 60 * 1000).toISOString(),
  duration: Math.floor(Math.random() * 7200) + 300,
  data_used: Math.floor(Math.random() * 5000000000) + 1000000000,
  ip_address: `192.168.1.${Math.floor(Math.random() * 255)}`
})))

const filteredData = computed(() => {
  let data = sessions.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(s => s.username.toLowerCase().includes(query) || s.ip_address.includes(query))
  }
  if (filters.value.type) data = data.filter(s => s.type === filters.value.type)
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

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDuration = (seconds) => {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

const formatDateTime = (date) => new Date(date).toLocaleString()

const refreshData = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const exportReport = () => alert('Export feature coming soon!')
</script>
