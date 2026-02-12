<template>
  <PageContainer>
    <PageHeader
      title="Daily Login Reports"
      subtitle="Track daily user login activities and patterns"
      icon="BarChart3"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshData" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportReport" variant="primary">
          <Download class="w-4 h-4 mr-1" />
          Export Report
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Logins</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.totalLogins }}</div>
            </div>
            <LogIn class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Unique Users</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.uniqueUsers }}</div>
            </div>
            <Users class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Avg Duration</div>
              <div class="text-2xl font-bold text-purple-900">{{ stats.avgDuration }}h</div>
            </div>
            <Clock class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Peak Hour</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.peakHour }}</div>
            </div>
            <TrendingUp class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <BaseSelect v-model="filters.period" class="w-36 sm:w-40">
          <option value="today">Today</option>
          <option value="yesterday">Yesterday</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
          <option value="custom">Custom Range</option>
        </BaseSelect>
        
        <BaseSelect v-model="filters.type" class="w-36">
          <option value="">All Types</option>
          <option value="hotspot">Hotspot</option>
          <option value="pppoe">PPPoE</option>
        </BaseSelect>
      </div>
    </div>

    <!-- Content -->
    <PageContent>
      <div v-if="loading">
        <BaseLoading type="table" :rows="10" />
      </div>

      <div v-else class="space-y-6">
        <!-- Daily Summary Table -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Daily Summary</h3>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-slate-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Logins</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Unique Users</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Avg Duration</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Peak Hour</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="day in dailyData" :key="day.date" class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm text-slate-900">{{ formatDate(day.date) }}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ day.logins }}</td>
                    <td class="px-4 py-3 text-sm text-slate-900">{{ day.uniqueUsers }}</td>
                    <td class="px-4 py-3 text-sm text-slate-900">{{ day.avgDuration }}h</td>
                    <td class="px-4 py-3 text-sm text-slate-900">{{ day.peakHour }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </BaseCard>

        <!-- Hourly Distribution -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Hourly Distribution</h3>
            <div class="grid grid-cols-12 gap-2">
              <div v-for="hour in 24" :key="hour" class="text-center">
                <div class="h-32 bg-slate-100 rounded flex items-end justify-center p-1">
                  <div 
                    class="w-full bg-blue-500 rounded transition-all"
                    :style="{ height: getHourHeight(hour) + '%' }"
                  ></div>
                </div>
                <div class="text-xs text-slate-600 mt-1">{{ hour - 1 }}h</div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { BarChart3, RefreshCw, Download, LogIn, Users, Clock, TrendingUp } from 'lucide-vue-next'
import axios from 'axios'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Daily Login Reports' }
]

const loading = ref(false)
const refreshing = ref(false)
const sessions = ref([])

const filters = ref({
  period: 'week',
  type: ''
})

const stats = computed(() => {
  const total = sessions.value.length
  const uniqueSet = new Set(sessions.value.map(s => s.username || s.user_id))
  const durations = sessions.value.map(s => Number(s.duration || s.session_time || 0)).filter(d => d > 0)
  const avgSec = durations.length > 0 ? durations.reduce((a, b) => a + b, 0) / durations.length : 0
  const hourCounts = {}
  sessions.value.forEach(s => {
    const h = new Date(s.start_time || s.created_at || '').getHours()
    hourCounts[h] = (hourCounts[h] || 0) + 1
  })
  const peakH = Object.entries(hourCounts).sort((a, b) => b[1] - a[1])[0]?.[0] || '0'
  return {
    totalLogins: total,
    uniqueUsers: uniqueSet.size,
    avgDuration: (avgSec / 3600).toFixed(1),
    peakHour: `${String(peakH).padStart(2, '0')}:00`
  }
})

const hourlyDistribution = computed(() => {
  const counts = Array(24).fill(0)
  sessions.value.forEach(s => {
    const h = new Date(s.start_time || s.created_at || '').getHours()
    if (h >= 0 && h < 24) counts[h]++
  })
  return counts
})

const maxHourly = computed(() => Math.max(...hourlyDistribution.value, 1))

const dailyData = computed(() => {
  const grouped = {}
  sessions.value.forEach(s => {
    const date = (s.start_time || s.created_at || '').slice(0, 10)
    if (!date) return
    if (!grouped[date]) grouped[date] = { date, logins: 0, users: new Set(), durations: [] }
    grouped[date].logins++
    grouped[date].users.add(s.username || s.user_id)
    const dur = Number(s.duration || s.session_time || 0)
    if (dur > 0) grouped[date].durations.push(dur)
  })
  return Object.values(grouped).map(d => {
    const avgSec = d.durations.length > 0 ? d.durations.reduce((a, b) => a + b, 0) / d.durations.length : 0
    return {
      date: d.date,
      logins: d.logins,
      uniqueUsers: d.users.size,
      avgDuration: (avgSec / 3600).toFixed(1),
      peakHour: '-'
    }
  }).sort((a, b) => b.date.localeCompare(a.date)).slice(0, 30)
})

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const getHourHeight = (hour) => {
  const count = hourlyDistribution.value[hour - 1] || 0
  return maxHourly.value > 0 ? Math.round((count / maxHourly.value) * 100) : 0
}

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
    ['Date', 'Logins', 'Unique Users', 'Avg Duration (h)'].join(','),
    ...dailyData.value.map(d => [d.date, d.logins, d.uniqueUsers, d.avgDuration].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `login-report-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

watch(() => [filters.value.period, filters.value.type], () => fetchSessions())

onMounted(() => {
  fetchSessions()
})
</script>
