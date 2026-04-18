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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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
              <thead class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
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
                  <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.username }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="user.type === 'hotspot' ? 'purple' : 'info'" size="sm">{{ user.type }}</BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ user.package_name || '-' }}</td>
                  <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ formatDateTime(user.expired_at) }}</td>
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
      <div class="text-sm text-slate-600 dark:text-slate-400">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }}
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { UserX, RefreshCw, Wifi, Network } from 'lucide-vue-next'
import DataViewContainer from "@/modules/common/components/base/DataViewContainer.vue"
import BaseButton from "@/modules/common/components/base/BaseButton.vue"
import BaseSelect from "@/modules/common/components/base/BaseSelect.vue"
import BaseLoading from "@/modules/common/components/base/BaseLoading.vue"
import EntityStatusBadge from "@/modules/common/components/base/EntityStatusBadge.vue"
import { useSessionReports } from "@/modules/tenant/composables/useSessionReports.js"

const breadcrumbs = [
  { label: "Dashboard", to: "/dashboard" },
  { label: "Reports", to: "/dashboard/reports" },
  { label: "Expired Accounts" }
]

const { loading, refreshing, users, formatDateTime, fetchExpired, refreshExpired } = useSessionReports()
const filters = ref({ type: "" })

const filteredUsers = computed(() => {
  let data = users.value
  if (filters.value.type) data = data.filter(u => u._type === filters.value.type)
  return data
})

const stats = computed(() => ({
  total: users.value.length,
  hotspot: users.value.filter(u => u._type === "hotspot").length,
  pppoe: users.value.filter(u => u._type === "pppoe").length
}))

const refreshData = () => refreshExpired()

onMounted(fetchExpired)
</script>
