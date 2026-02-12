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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
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

    <!-- View History Overlay -->
    <SlideOverlay v-model="showHistoryOverlay" title="Wallet History" :subtitle="selectedWallet?.username" icon="Wallet" width="lg">
      <div v-if="selectedWallet" class="p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-xs text-slate-500">Username</span><div class="text-sm font-semibold text-slate-900">{{ selectedWallet.username }}</div></div>
          <div><span class="text-xs text-slate-500">Email</span><div class="text-sm text-slate-900">{{ selectedWallet.email }}</div></div>
          <div><span class="text-xs text-slate-500">Current Balance</span><div class="text-lg font-bold" :class="getBalanceColor(selectedWallet.balance)">KES {{ formatMoney(selectedWallet.balance) }}</div></div>
          <div><span class="text-xs text-slate-500">Total Topups</span><div class="text-sm font-medium text-slate-900">KES {{ formatMoney(selectedWallet.total_topups) }}</div></div>
        </div>
        <div v-if="walletHistory.length" class="mt-4">
          <h4 class="text-sm font-semibold text-slate-700 mb-2">Recent Transactions</h4>
          <div class="space-y-2">
            <div v-for="tx in walletHistory" :key="tx.id" class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">{{ tx.description || tx.type }}</div>
                <div class="text-xs text-slate-500">{{ formatDateTime(tx.created_at) }}</div>
              </div>
              <div class="text-sm font-bold" :class="tx.amount > 0 ? 'text-green-600' : 'text-red-600'">{{ tx.amount > 0 ? '+' : '' }}KES {{ formatMoney(Math.abs(tx.amount)) }}</div>
            </div>
          </div>
        </div>
        <div v-else class="text-sm text-slate-500 text-center py-4">No transaction history available.</div>
      </div>
      <template #footer>
        <BaseButton @click="showHistoryOverlay = false" variant="ghost">Close</BaseButton>
      </template>
    </SlideOverlay>

    <!-- Add Balance Overlay -->
    <SlideOverlay v-model="showAddBalanceOverlay" title="Add Balance" :subtitle="balanceTarget?.username || 'Select a user'" icon="Plus" width="md">
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Amount (KES)</label>
          <input v-model.number="balanceAmount" type="number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <input v-model="balanceDescription" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Reason for adjustment" />
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton @click="submitBalance('credit')" variant="success" :loading="submitting">Add Balance</BaseButton>
          <BaseButton @click="showAddBalanceOverlay = false" variant="ghost">Cancel</BaseButton>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Wallet, RefreshCw, Plus, X, Users, TrendingUp, DollarSign, Eye, Minus } from 'lucide-vue-next'
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
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

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
const showHistoryOverlay = ref(false)
const showAddBalanceOverlay = ref(false)
const selectedWallet = ref(null)
const balanceTarget = ref(null)
const balanceAmount = ref(0)
const balanceDescription = ref('')
const balanceAction = ref('credit')
const submitting = ref(false)
const walletHistory = ref([])

const filters = ref({ status: '' })

const wallets = ref([])

const stats = computed(() => {
  const total = wallets.value.reduce((sum, w) => sum + (w.balance || 0), 0)
  return {
    totalBalance: total,
    activeWallets: wallets.value.filter(w => w.balance > 0).length,
    todayTopups: wallets.value.reduce((sum, w) => sum + (w.today_topups || 0), 0),
    avgBalance: wallets.value.length ? Math.floor(total / wallets.value.length) : 0
  }
})

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

const fetchWallets = async () => {
  const isInitial = wallets.value.length === 0
  if (isInitial) loading.value = true
  try {
    const response = await axios.get('/billing/wallets')
    const data = response.data?.wallets || response.data?.data || []
    wallets.value = data.map(w => ({
      id: w.id,
      username: w.username || w.user?.name || `User ${w.user_id || w.id}`,
      email: w.email || w.user?.email || '',
      balance: Number(w.balance || 0),
      last_topup: Number(w.last_topup_amount || w.last_topup || 0),
      last_topup_date: w.last_topup_date || w.last_topup_at || '',
      total_topups: Number(w.total_topups || w.total_credits || 0),
      today_topups: Number(w.today_topups || 0),
      user_id: w.user_id || w.id
    }))
  } catch (err) {
    console.error('fetchWallets error:', err)
  } finally {
    loading.value = false
  }
}

const refreshData = async () => {
  refreshing.value = true
  await fetchWallets()
  refreshing.value = false
}

const viewHistory = async (wallet) => {
  selectedWallet.value = wallet
  walletHistory.value = []
  showHistoryOverlay.value = true
  try {
    const response = await axios.get(`/billing/wallets/${wallet.id}/history`)
    walletHistory.value = response.data?.transactions || response.data?.data || []
  } catch (err) {
    console.error('fetchHistory error:', err)
  }
}

const openAddBalanceModal = () => {
  balanceTarget.value = null
  balanceAmount.value = 0
  balanceDescription.value = ''
  showAddBalanceOverlay.value = true
}

const addBalance = (wallet) => {
  balanceTarget.value = wallet
  balanceAction.value = 'credit'
  balanceAmount.value = 0
  balanceDescription.value = ''
  showAddBalanceOverlay.value = true
}

const deductBalance = (wallet) => {
  balanceTarget.value = wallet
  balanceAction.value = 'debit'
  balanceAmount.value = 0
  balanceDescription.value = ''
  showAddBalanceOverlay.value = true
}

const submitBalance = async (type) => {
  if (!balanceTarget.value || !balanceAmount.value) {
    alert('Please select a user and enter an amount.')
    return
  }
  submitting.value = true
  try {
    await axios.post(`/billing/wallets/${balanceTarget.value.id}/adjust`, {
      type: type || balanceAction.value,
      amount: balanceAmount.value,
      description: balanceDescription.value
    })
    showAddBalanceOverlay.value = false
    await fetchWallets()
  } catch (err) {
    console.error('submitBalance error:', err)
    alert(err.response?.data?.message || 'Failed to adjust balance')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchWallets()
})
</script>
