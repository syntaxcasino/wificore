<template>
  <DataViewContainer
    title="Bandwidth Usage Summary"
    subtitle="Analyze network bandwidth consumption"
    icon="Activity"
    :breadcrumbs="breadcrumbs"
  >
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

    <template #stats>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Usage</div>
              <div class="text-2xl font-bold text-blue-900">{{ formatBytes(stats.totalUsage) }}</div>
            </div>
            <Activity class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Download</div>
              <div class="text-2xl font-bold text-green-900">{{ formatBytes(stats.download) }}</div>
            </div>
            <ArrowDown class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Upload</div>
              <div class="text-2xl font-bold text-purple-900">{{ formatBytes(stats.upload) }}</div>
            </div>
            <ArrowUp class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Avg/User</div>
              <div class="text-2xl font-bold text-amber-900">{{ formatBytes(stats.avgPerUser) }}</div>
            </div>
            <Users class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </template>

    <template #filters>
      <div class="flex items-center gap-3 flex-wrap">
        <BaseSelect v-model="filters.period" class="w-36 sm:w-40">
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
    </template>

    <PageContent>
      <div class="space-y-6">
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Top Users by Bandwidth</h3>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-slate-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">User</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Total</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Download</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Upload</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Sessions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="user in topUsers" :key="user.id" class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ user.username }}</td>
                    <td class="px-4 py-3 text-sm font-bold text-blue-600">{{ formatBytes(user.total) }}</td>
                    <td class="px-4 py-3 text-sm text-green-600">{{ formatBytes(user.download) }}</td>
                    <td class="px-4 py-3 text-sm text-purple-600">{{ formatBytes(user.upload) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ user.sessions }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Daily Bandwidth Trend</h3>
            <div class="grid grid-cols-7 gap-2">
              <div v-for="day in dailyTrend" :key="day.date" class="text-center">
                <div class="h-40 bg-slate-100 rounded flex flex-col items-center justify-end p-2 gap-1">
                  <div class="w-full bg-green-500 rounded" :style="{ height: (day.download / maxDaily * 100) + '%' }"></div>
                  <div class="w-full bg-purple-500 rounded" :style="{ height: (day.upload / maxDaily * 100) + '%' }"></div>
                </div>
                <div class="text-xs text-slate-600 mt-2">{{ formatDate(day.date) }}</div>
                <div class="text-xs font-semibold text-slate-900">{{ formatBytes(day.total) }}</div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { Activity, RefreshCw, Download, ArrowDown, ArrowUp, Users } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Bandwidth Usage' }
]

const loading = ref(false)
const refreshing = ref(false)
const sessions = ref([])
const filters = ref({ period: 'week', type: '' })

const stats = computed(() => {
  let totalDown = 0, totalUp = 0
  const userSet = new Set()
  sessions.value.forEach(s => {
    totalDown += Number(s.bytes_in || s.download || 0)
    totalUp += Number(s.bytes_out || s.upload || 0)
    userSet.add(s.username || s.user_id)
  })
  const total = totalDown + totalUp
  return {
    totalUsage: total,
    download: totalDown,
    upload: totalUp,
    avgPerUser: userSet.size > 0 ? Math.round(total / userSet.size) : 0
  }
})

const topUsers = computed(() => {
  const grouped = {}
  sessions.value.forEach(s => {
    const user = s.username || s.user_id || 'unknown'
    if (!grouped[user]) grouped[user] = { id: user, username: user, download: 0, upload: 0, sessions: 0 }
    grouped[user].download += Number(s.bytes_in || s.download || 0)
    grouped[user].upload += Number(s.bytes_out || s.upload || 0)
    grouped[user].sessions++
  })
  return Object.values(grouped)
    .map(u => ({ ...u, total: u.download + u.upload }))
    .sort((a, b) => b.total - a.total)
    .slice(0, 10)
})

const dailyTrend = computed(() => {
  const grouped = {}
  sessions.value.forEach(s => {
    const date = (s.start_time || s.created_at || '').slice(0, 10)
    if (!date) return
    if (!grouped[date]) grouped[date] = { date, download: 0, upload: 0 }
    grouped[date].download += Number(s.bytes_in || s.download || 0)
    grouped[date].upload += Number(s.bytes_out || s.upload || 0)
  })
  return Object.values(grouped)
    .map(d => ({ ...d, total: d.download + d.upload }))
    .sort((a, b) => a.date.localeCompare(b.date))
    .slice(-7)
})

const maxDaily = computed(() => Math.max(...dailyTrend.value.map(d => d.total), 1))

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDate = (date) => new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

const fetchSessions = async () => {
  const isInitial = sessions.value.length === 0
  if (isInitial) loading.value = true
  try {
    const results = []
    if (!filters.value.type || filters.value.type === 'hotspot') {
      const res = await axios.get('/hotspot/sessions')
      const data = res.data?.sessions || res.data?.data || []
      results.push(...data.map(s => ({ ...s, type: 'hotspot', start_time: s.start_time || s.created_at })))
    }
    if (!filters.value.type || filters.value.type === 'pppoe') {
      const res = await axios.get('/pppoe/sessions/live')
      const data = res.data?.sessions || res.data?.data || []
      results.push(...data.map(s => ({ ...s, type: 'pppoe', start_time: s.start_time || s.created_at })))
    }
    sessions.value = results
  } catch (err) {
    console.error('fetchSessions error:', err)
  } finally {
    loading.value = false
  }
}

const refreshData = async () => {
  refreshing.value = true
  await fetchSessions()
  refreshing.value = false
}

const exportReport = () => {
  const csv = [
    ['User', 'Total', 'Download', 'Upload', 'Sessions'].join(','),
    ...topUsers.value.map(u => [u.username, u.total, u.download, u.upload, u.sessions].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `bandwidth-report-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

watch(() => [filters.value.period, filters.value.type], () => fetchSessions())

onMounted(() => {
  fetchSessions()
})
</script>
