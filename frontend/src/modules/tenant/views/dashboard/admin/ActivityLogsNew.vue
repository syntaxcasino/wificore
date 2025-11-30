<template>
  <PageContainer>
    <PageHeader title="Activity Logs" subtitle="Monitor system activities" icon="Activity" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshLogs" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportLogs" variant="ghost">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
      </template>
    </PageHeader>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search logs..." />
        </div>
        
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.action" class="w-36">
            <option value="">All Actions</option>
            <option value="create">Create</option>
            <option value="update">Update</option>
            <option value="delete">Delete</option>
            <option value="login">Login</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.user" class="w-36">
            <option value="">All Users</option>
            <option value="admin">Admin</option>
            <option value="support">Support</option>
          </BaseSelect>
        </div>
      </div>
    </div>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="list" :rows="10" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="divide-y divide-slate-100">
            <div v-for="log in paginatedData" :key="log.id" class="p-4 hover:bg-slate-50">
              <div class="flex items-start gap-4">
                <div class="flex-shrink-0 mt-1">
                  <div class="p-2 rounded-lg" :class="getActionBg(log.action)">
                    <component :is="getActionIcon(log.action)" class="w-4 h-4" :class="getActionColor(log.action)" />
                  </div>
                </div>
                
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1">
                    <BaseBadge :variant="getActionVariant(log.action)" size="sm">{{ log.action }}</BaseBadge>
                    <span class="text-xs text-slate-500">{{ formatDateTime(log.timestamp) }}</span>
                  </div>
                  
                  <div class="text-sm font-medium text-slate-900 mb-1">{{ log.description }}</div>
                  
                  <div class="flex items-center gap-4 text-xs text-slate-500">
                    <span>User: {{ log.user }}</span>
                    <span>IP: {{ log.ip_address }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} logs
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Activity, RefreshCw, Download, Plus, Edit2, Trash2, LogIn } from 'lucide-vue-next'
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

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Admin', to: '/dashboard/admin' }, { label: 'Activity Logs' }]

const loading = ref(false)
const refreshing = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)

const filters = ref({ action: '', user: '' })

const logs = ref(Array.from({ length: 50 }, (_, i) => ({
  id: i + 1,
  action: ['create', 'update', 'delete', 'login'][Math.floor(Math.random() * 4)],
  description: ['Created new user', 'Updated package', 'Deleted invoice', 'User logged in'][Math.floor(Math.random() * 4)],
  user: ['admin', 'support'][Math.floor(Math.random() * 2)],
  ip_address: `192.168.1.${Math.floor(Math.random() * 255)}`,
  timestamp: new Date(Date.now() - Math.random() * 7 * 24 * 60 * 60 * 1000).toISOString()
})))

const filteredData = computed(() => {
  let data = logs.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(l => l.description.toLowerCase().includes(query))
  }
  if (filters.value.action) data = data.filter(l => l.action === filters.value.action)
  if (filters.value.user) data = data.filter(l => l.user === filters.value.user)
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

const getActionBg = (action) => ({ create: 'bg-green-100', update: 'bg-blue-100', delete: 'bg-red-100', login: 'bg-purple-100' }[action])
const getActionColor = (action) => ({ create: 'text-green-600', update: 'text-blue-600', delete: 'text-red-600', login: 'text-purple-600' }[action])
const getActionIcon = (action) => ({ create: Plus, update: Edit2, delete: Trash2, login: LogIn }[action])
const getActionVariant = (action) => ({ create: 'success', update: 'info', delete: 'danger', login: 'secondary' }[action])

const formatDateTime = (date) => new Date(date).toLocaleString()

const refreshLogs = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const exportLogs = () => alert('Export feature coming soon!')
</script>
