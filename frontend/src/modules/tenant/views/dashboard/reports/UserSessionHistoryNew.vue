<template>
  <DataViewContainer
    title="User Session History"
    subtitle="View detailed user session records"
    color-theme="blue"
    :breadcrumbs="breadcrumbs"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
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
    </template>

    <template #filters>
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
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
    </template>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="10" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
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
                  <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.username }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="session.type === 'hotspot' ? 'purple' : 'info'" size="sm">{{ session.type }}</BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ formatDateTime(session.start_time) }}</td>
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
      <div class="text-sm text-slate-600 dark:text-slate-400">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} sessions
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Clock, RefreshCw, Download, Wifi, Network } from 'lucide-vue-next'
import DataViewContainer from "@/modules/common/components/base/DataViewContainer.vue"
import BaseButton from "@/modules/common/components/base/BaseButton.vue"
import BaseSelect from "@/modules/common/components/base/BaseSelect.vue"
import BaseLoading from "@/modules/common/components/base/BaseLoading.vue"
import EntityStatusBadge from "@/modules/common/components/base/EntityStatusBadge.vue"
import { useSessionReports } from "@/modules/tenant/composables/useSessionReports.js"

const breadcrumbs = [
  { label: "Dashboard", to: "/dashboard" },
  { label: "Reports", to: "/dashboard/reports" },
  { label: "Session History" }
]

const { loading, refreshing, sessions, stats, formatDateTime, formatDuration, formatBytes, fetchSessions, refreshData } = useSessionReports()
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

onMounted(fetchSessions)
</script>
