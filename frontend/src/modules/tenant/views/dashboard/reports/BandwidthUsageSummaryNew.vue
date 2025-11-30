<template>
  <PageContainer>
    <PageHeader title="Bandwidth Usage Summary" subtitle="Analyze network bandwidth consumption" icon="Activity" :breadcrumbs="breadcrumbs">
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
    </div>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3">
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
  </PageContainer>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Activity, RefreshCw, Download, ArrowDown, ArrowUp, Users } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Bandwidth Usage' }
]

const refreshing = ref(false)
const filters = ref({ period: 'week', type: '' })

const stats = ref({
  totalUsage: 524288000000,
  download: 419430400000,
  upload: 104857600000,
  avgPerUser: 5242880000
})

const topUsers = ref(Array.from({ length: 10 }, (_, i) => ({
  id: i + 1,
  username: `user${i + 1}`,
  total: Math.floor(Math.random() * 10000000000) + 5000000000,
  download: Math.floor(Math.random() * 8000000000) + 4000000000,
  upload: Math.floor(Math.random() * 2000000000) + 1000000000,
  sessions: Math.floor(Math.random() * 50) + 10
})))

const dailyTrend = ref(Array.from({ length: 7 }, (_, i) => ({
  date: new Date(Date.now() - i * 86400000).toISOString(),
  download: Math.floor(Math.random() * 50000000000) + 30000000000,
  upload: Math.floor(Math.random() * 15000000000) + 10000000000,
  total: 0
})).map(d => ({ ...d, total: d.download + d.upload })))

const maxDaily = computed(() => Math.max(...dailyTrend.value.map(d => d.total)))

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDate = (date) => new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

const refreshData = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const exportReport = () => alert('Export feature coming soon!')
</script>
