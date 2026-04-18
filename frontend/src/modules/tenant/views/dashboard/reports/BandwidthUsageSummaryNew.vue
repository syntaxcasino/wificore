<template>
  <DataViewContainer
    title="Bandwidth Usage Summary"
    subtitle="Analyze network bandwidth consumption"
    color-theme="blue"
    :breadcrumbs="breadcrumbs"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
    </template>

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
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                  <tr v-for="user in topUsers" :key="user.id" class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.username }}</td>
                    <td class="px-4 py-3 text-sm font-bold text-blue-600">{{ formatBytes(user.total) }}</td>
                    <td class="px-4 py-3 text-sm text-green-600">{{ formatBytes(user.download) }}</td>
                    <td class="px-4 py-3 text-sm text-purple-600">{{ formatBytes(user.upload) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-400">{{ user.sessions }}</td>
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
                <div class="text-xs font-semibold text-slate-900 dark:text-slate-100">{{ formatBytes(day.total) }}</div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Activity, RefreshCw, Download } from 'lucide-vue-next'
import DataViewContainer from "@/modules/common/components/base/DataViewContainer.vue"
import BaseButton from "@/modules/common/components/base/BaseButton.vue"
import BaseSelect from "@/modules/common/components/base/BaseSelect.vue"
import BaseLoading from "@/modules/common/components/base/BaseLoading.vue"
import { useSessionReports } from "@/modules/tenant/composables/useSessionReports.js"

const breadcrumbs = [
  { label: "Dashboard", to: "/dashboard" },
  { label: "Reports", to: "/dashboard/reports" },
  { label: "Bandwidth Usage" }
]

const { loading, refreshing, sessions, formatBytes, formatDateTime, fetchSessions, refreshData } = useSessionReports()
const filters = ref({ period: "week", type: "" })

const filteredSessions = computed(() => {
  let data = sessions.value
  if (filters.value.type) data = data.filter(s => s._type === filters.value.type)
  if (filters.value.period) {
    const now = new Date()
    data = data.filter(s => {
      const d = new Date(s.created_at || s.start_time)
      switch (filters.value.period) {
        case "today": return d.toDateString() === now.toDateString()
        case "week": return d >= new Date(now - 7 * 86400000)
        case "month": return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear()
        default: return true
      }
    })
  }
  return data
})

const stats = computed(() => {
  const totalIn = filteredSessions.value.reduce((s, u) => s + Number(u.input_bytes || u.bytes_in || u.acct_input_octets || 0), 0)
  const totalOut = filteredSessions.value.reduce((s, u) => s + Number(u.output_bytes || u.bytes_out || u.acct_output_octets || 0), 0)
  return {
    totalIn, totalOut, total: totalIn + totalOut,
    sessions: filteredSessions.value.length
  }
})

onMounted(fetchSessions)
</script>
