<template>
  <PageContainer>
    <PageHeader title="Traffic Graphs" subtitle="Real-time network traffic visualization" icon="BarChart3" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshData" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportData" variant="ghost">
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
              <div class="text-xs text-blue-600 font-medium mb-1">Current Traffic</div>
              <div class="text-2xl font-bold text-blue-900">{{ formatBytes(stats.current) }}/s</div>
            </div>
            <Activity class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Download</div>
              <div class="text-2xl font-bold text-green-900">{{ formatBytes(stats.download) }}/s</div>
            </div>
            <ArrowDown class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Upload</div>
              <div class="text-2xl font-bold text-purple-900">{{ formatBytes(stats.upload) }}/s</div>
            </div>
            <ArrowUp class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Peak Today</div>
              <div class="text-2xl font-bold text-amber-900">{{ formatBytes(stats.peak) }}/s</div>
            </div>
            <TrendingUp class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3">
        <BaseSelect v-model="filters.timeRange" class="w-40">
          <option value="1h">Last Hour</option>
          <option value="6h">Last 6 Hours</option>
          <option value="24h">Last 24 Hours</option>
          <option value="7d">Last 7 Days</option>
        </BaseSelect>
        <BaseSelect v-model="filters.router" class="w-40">
          <option value="">All Routers</option>
          <option value="router1">Router 01</option>
          <option value="router2">Router 02</option>
        </BaseSelect>
      </div>
    </div>

    <PageContent>
      <div class="space-y-6">
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Real-time Traffic</h3>
            <div class="h-64 bg-slate-50 rounded-lg flex items-end justify-between p-4 gap-1">
              <div v-for="(point, i) in trafficData" :key="i" class="flex-1 flex flex-col items-center justify-end gap-1">
                <div class="w-full bg-green-500 rounded-t transition-all" :style="{ height: (point.download / maxTraffic * 100) + '%' }"></div>
                <div class="w-full bg-purple-500 rounded-t transition-all" :style="{ height: (point.upload / maxTraffic * 100) + '%' }"></div>
              </div>
            </div>
            <div class="flex items-center justify-center gap-6 mt-4">
              <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-green-500 rounded"></div>
                <span class="text-sm text-slate-600">Download</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-purple-500 rounded"></div>
                <span class="text-sm text-slate-600">Upload</span>
              </div>
            </div>
          </div>
        </BaseCard>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Top Consumers</h3>
              <div class="space-y-3">
                <div v-for="user in topConsumers" :key="user.id" class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-xs font-semibold">
                      {{ user.username.slice(0, 2).toUpperCase() }}
                    </div>
                    <span class="text-sm font-medium text-slate-900">{{ user.username }}</span>
                  </div>
                  <span class="text-sm font-bold text-blue-600">{{ formatBytes(user.bandwidth) }}/s</span>
                </div>
              </div>
            </div>
          </BaseCard>

          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Router Distribution</h3>
              <div class="space-y-4">
                <div v-for="router in routers" :key="router.id">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-700">{{ router.name }}</span>
                    <span class="text-sm font-bold text-slate-900">{{ formatBytes(router.traffic) }}/s</span>
                  </div>
                  <div class="w-full bg-slate-200 rounded-full h-2">
                    <div class="h-2 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-all" :style="{ width: router.percentage + '%' }"></div>
                  </div>
                </div>
              </div>
            </div>
          </BaseCard>
        </div>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { BarChart3, RefreshCw, Download, Activity, ArrowDown, ArrowUp, TrendingUp } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Monitoring', to: '/dashboard/monitoring' },
  { label: 'Traffic Graphs' }
]

const refreshing = ref(false)
const filters = ref({ timeRange: '1h', router: '' })

const stats = ref({
  current: 125000000,
  download: 95000000,
  upload: 30000000,
  peak: 180000000
})

const trafficData = ref(Array.from({ length: 60 }, () => ({
  download: Math.floor(Math.random() * 100000000) + 50000000,
  upload: Math.floor(Math.random() * 40000000) + 20000000
})))

const topConsumers = ref(Array.from({ length: 5 }, (_, i) => ({
  id: i + 1,
  username: `user${i + 1}`,
  bandwidth: Math.floor(Math.random() * 10000000) + 5000000
})))

const routers = ref([
  { id: 1, name: 'Router 01', traffic: 75000000, percentage: 60 },
  { id: 2, name: 'Router 02', traffic: 50000000, percentage: 40 }
])

const maxTraffic = computed(() => Math.max(...trafficData.value.map(d => d.download + d.upload)))

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const refreshData = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const exportData = () => alert('Export feature coming soon!')

let refreshInterval

onMounted(() => {
  refreshInterval = setInterval(() => {
    trafficData.value.shift()
    trafficData.value.push({
      download: Math.floor(Math.random() * 100000000) + 50000000,
      upload: Math.floor(Math.random() * 40000000) + 20000000
    })
  }, 2000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>
