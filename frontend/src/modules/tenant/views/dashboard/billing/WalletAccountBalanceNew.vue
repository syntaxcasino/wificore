<template>
  <PageContainer>
    <PageHeader title="Wallet & Account Balance" subtitle="Manage user wallet balances and transactions" icon="Wallet" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshData" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="openAddBalanceModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Balance
        </BaseButton>
      </template>
    </PageHeader>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Total Balance</div>
              <div class="text-2xl font-bold text-green-900">KES {{ formatMoney(stats.totalBalance) }}</div>
            </div>
            <Wallet class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Active Wallets</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.activeWallets }}</div>
            </div>
            <Users class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Today's Topups</div>
              <div class="text-2xl font-bold text-purple-900">KES {{ formatMoney(stats.todayTopups) }}</div>
            </div>
            <TrendingUp class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Avg Balance</div>
              <div class="text-2xl font-bold text-amber-900">KES {{ formatMoney(stats.avgBalance) }}</div>
            </div>
            <DollarSign class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search by user..." />
        </div>
        
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" class="w-36">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="low">Low Balance</option>
            <option value="zero">Zero Balance</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} wallets</BaseBadge>
        </div>
      </div>
    </div>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="10" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Balance</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Last Topup</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Total Topups</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Status</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="wallet in paginatedData" :key="wallet.id" class="border-b border-slate-100 hover:bg-blue-50/50">
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ wallet.username.slice(0, 2).toUpperCase() }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ wallet.username }}</div>
                        <div class="text-xs text-slate-500">{{ wallet.email }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-lg font-bold" :class="getBalanceColor(wallet.balance)">
                      KES {{ formatMoney(wallet.balance) }}
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">KES {{ formatMoney(wallet.last_topup) }}</div>
                    <div class="text-xs text-slate-500">{{ formatDateTime(wallet.last_topup_date) }}</div>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-900">KES {{ formatMoney(wallet.total_topups) }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="getStatusVariant(wallet.balance)" size="sm">
                      {{ getStatusLabel(wallet.balance) }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewHistory(wallet)" variant="ghost" size="sm" title="View History">
                        <Eye class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="addBalance(wallet)" variant="success" size="sm">
                        <Plus class="w-3 h-3 mr-1" />
                        Add
                      </BaseButton>
                      <BaseButton @click="deductBalance(wallet)" variant="danger" size="sm">
                        <Minus class="w-3 h-3 mr-1" />
                        Deduct
                      </BaseButton>
                    </div>
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
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} wallets
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Wallet, RefreshCw, Plus, X, Users, TrendingUp, DollarSign, Eye, Minus } from 'lucide-vue-next'
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

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/dashboard/billing' },
  { label: 'Wallet Balance' }
]

const loading = ref(false)
const refreshing = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)

const filters = ref({ status: '' })

const wallets = ref(Array.from({ length: 30 }, (_, i) => ({
  id: i + 1,
  username: `user${i + 1}`,
  email: `user${i + 1}@example.com`,
  balance: Math.floor(Math.random() * 10000),
  last_topup: Math.floor(Math.random() * 5000) + 500,
  last_topup_date: new Date(Date.now() - Math.random() * 30 * 24 * 60 * 60 * 1000).toISOString(),
  total_topups: Math.floor(Math.random() * 50000) + 10000
})))

const stats = computed(() => ({
  totalBalance: wallets.value.reduce((sum, w) => sum + w.balance, 0),
  activeWallets: wallets.value.filter(w => w.balance > 0).length,
  todayTopups: Math.floor(Math.random() * 50000) + 20000,
  avgBalance: Math.floor(wallets.value.reduce((sum, w) => sum + w.balance, 0) / wallets.value.length)
}))

const filteredData = computed(() => {
  let data = wallets.value

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(w => w.username.toLowerCase().includes(query) || w.email.toLowerCase().includes(query))
  }

  if (filters.value.status === 'active') data = data.filter(w => w.balance > 1000)
  if (filters.value.status === 'low') data = data.filter(w => w.balance > 0 && w.balance <= 1000)
  if (filters.value.status === 'zero') data = data.filter(w => w.balance === 0)

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

const hasActiveFilters = computed(() => filters.value.status || searchQuery.value)

const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
const formatDateTime = (date) => new Date(date).toLocaleDateString()

const getBalanceColor = (balance) => {
  if (balance === 0) return 'text-red-600'
  if (balance < 1000) return 'text-amber-600'
  return 'text-green-600'
}

const getStatusVariant = (balance) => {
  if (balance === 0) return 'danger'
  if (balance < 1000) return 'warning'
  return 'success'
}

const getStatusLabel = (balance) => {
  if (balance === 0) return 'Zero'
  if (balance < 1000) return 'Low'
  return 'Active'
}

const clearFilters = () => {
  filters.value = { status: '' }
  searchQuery.value = ''
}

const refreshData = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const openAddBalanceModal = () => alert('Add balance modal coming soon!')
const viewHistory = (wallet) => console.log('View history:', wallet)
const addBalance = (wallet) => alert(`Add balance for ${wallet.username}`)
const deductBalance = (wallet) => alert(`Deduct balance for ${wallet.username}`)

onMounted(() => { loading.value = false })
</script>
