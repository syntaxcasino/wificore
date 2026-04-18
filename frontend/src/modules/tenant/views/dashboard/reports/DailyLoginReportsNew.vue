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
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                  <tr v-for="day in dailyData" :key="day.date" class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm text-slate-900">{{ formatDate(day.date) }}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ day.logins }}</td>
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
import { ref, computed, onMounted } from 'vue'
import { BarChart2, RefreshCw, Download, Wifi, Network } from 'lucide-vue-next'
import DataViewContainer from "@/modules/common/components/base/DataViewContainer.vue"
import BaseButton from "@/modules/common/components/base/BaseButton.vue"
import BaseSelect from "@/modules/common/components/base/BaseSelect.vue"
import BaseLoading from "@/modules/common/components/base/BaseLoading.vue"
import { useSessionReports } from "@/modules/tenant/composables/useSessionReports.js"

const breadcrumbs = [
  { label: "Dashboard", to: "/dashboard" },
  { label: "Reports", to: "/dashboard/reports" },
  { label: "Daily Login Reports" }
]

const { loading, refreshing, sessions, formatDateTime, fetchSessions, refreshData } = useSessionReports()
const filters = ref({ period: "week", date: "", type: "" })

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

const dailyStats = computed(() => {
  const grouped = {}
  filteredSessions.value.forEach(s => {
    const date = (s.created_at || s.start_time || "").slice(0, 10)
    if (!date) return
    if (!grouped[date]) grouped[date] = { date, total: 0, hotspot: 0, pppoe: 0 }
    grouped[date].total++
    if (s._type === "hotspot") grouped[date].hotspot++
    else grouped[date].pppoe++
  })
  return Object.values(grouped).sort((a, b) => b.date.localeCompare(a.date))
})

onMounted(fetchSessions)
</script>
