<template>
  <PageContainer>
    <PageHeader title="Expired Accounts" subtitle="View users with expired subscriptions or sessions" icon="UserX" :breadcrumbs="breadcrumbs">
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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-3 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-red-50 to-rose-50 rounded-lg p-4 border border-red-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-red-600 font-medium mb-1">Expired Accounts</div>
              <div class="text-2xl font-bold text-red-900">{{ stats.expired }}</div>
            </div>
            <UserX class="w-6 h-6 text-red-600" />
          </div>
        </div>
        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Hotspot</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.hotspot }}</div>
            </div>
            <Wifi class="w-6 h-6 text-amber-600" />
          </div>
        </div>
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">PPPoE</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.pppoe }}</div>
            </div>
            <Network class="w-6 h-6 text-blue-600" />
          </div>
        </div>
      </div>
    </div>

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search expired accounts..." />
        </div>
        <BaseSelect v-model="filters.type" class="w-36">
          <option value="">All Types</option>
          <option value="hotspot">Hotspot</option>
          <option value="pppoe">PPPoE</option>
        </BaseSelect>
        <div class="ml-auto">
          <BaseBadge variant="danger">{{ filteredData.length }} expired</BaseBadge>
        </div>
      </div>
    </div>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="10" />
      </div>

      <div v-else-if="!filteredData.length" class="p-6">
        <BaseEmpty title="No expired accounts" description="All user accounts are currently active." icon="UserX" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Username</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Type</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Expired At</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="user in paginatedData" :key="user.id" class="border-b border-slate-100 hover:bg-red-50/50">
                  <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ user.username }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="user.type === 'hotspot' ? 'purple' : 'info'" size="sm">{{ user.type }}</BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600">{{ user.package_name || '-' }}</td>
                  <td class="px-6 py-4 text-sm text-slate-600">{{ formatDateTime(user.expired_at) }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge variant="danger" size="sm">Expired</BaseBadge>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }}
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { UserX, RefreshCw, Download, Wifi, Network } from 'lucide-vue-next'
import axios from 'axios'
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
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Expired Accounts' }
]

const loading = ref(false)
const refreshing = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)
const users = ref([])
const filters = ref({ type: '' })

const stats = computed(() => ({
  expired: users.value.length,
  hotspot: users.value.filter(u => u.type === 'hotspot').length,
  pppoe: users.value.filter(u => u.type === 'pppoe').length
}))

const filteredData = computed(() => {
  let data = users.value
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    data = data.filter(u => u.username.toLowerCase().includes(q) || (u.package_name || '').toLowerCase().includes(q))
  }
  if (filters.value.type) data = data.filter(u => u.type === filters.value.type)
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
  return { start: Math.min(start, filteredData.value.length), end, total: filteredData.value.length }
})

const formatDateTime = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleString()
}

const fetchExpired = async () => {
  const isInitial = users.value.length === 0
  if (isInitial) loading.value = true
  try {
    const results = []
    try {
      const res = await axios.get('/hotspot/users', { params: { status: 'expired' } })
      const data = res.data?.users || res.data?.data || []
      results.push(...data.map(u => ({
        id: `hs-${u.id}`,
        username: u.username || u.name || 'Unknown',
        type: 'hotspot',
        package_name: u.package?.name || u.package_name || '-',
        expired_at: u.expires_at || u.expired_at || u.updated_at || ''
      })))
    } catch (e) { /* hotspot endpoint may not support expired filter */ }
    try {
      const res = await axios.get('/pppoe/users', { params: { status: 'expired' } })
      const data = res.data?.users || res.data?.data || []
      results.push(...data.map(u => ({
        id: `pp-${u.id}`,
        username: u.username || u.name || 'Unknown',
        type: 'pppoe',
        package_name: u.package?.name || u.package_name || '-',
        expired_at: u.expires_at || u.expired_at || u.updated_at || ''
      })))
    } catch (e) { /* pppoe endpoint may not support expired filter */ }
    users.value = results
  } catch (err) {
    console.error('fetchExpired error:', err)
  } finally {
    loading.value = false
  }
}

const refreshData = async () => {
  refreshing.value = true
  await fetchExpired()
  refreshing.value = false
}

const exportReport = () => {
  const csv = [
    ['Username', 'Type', 'Package', 'Expired At'].join(','),
    ...filteredData.value.map(u => [u.username, u.type, u.package_name, u.expired_at].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `expired-accounts-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(() => {
  fetchExpired()
})
</script>
