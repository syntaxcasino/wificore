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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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
              <thead class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
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
                        <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ wallet.username }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ wallet.email }}</div>
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
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatDateTime(wallet.last_topup_date) }}</div>
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
      <div class="text-sm text-slate-600 dark:text-slate-400">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} wallets
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>

    <!-- View History Overlay -->
    <SlideOverlay v-model="showHistoryOverlay" title="Wallet History" :subtitle="selectedWallet?.username" icon="Wallet" width="60%">
      <div v-if="selectedWallet" class="p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-xs text-slate-500 dark:text-slate-400">Username</span><div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ selectedWallet.username }}</div></div>
          <div><span class="text-xs text-slate-500 dark:text-slate-400">Email</span><div class="text-sm text-slate-900">{{ selectedWallet.email }}</div></div>
          <div><span class="text-xs text-slate-500 dark:text-slate-400">Current Balance</span><div class="text-lg font-bold" :class="getBalanceColor(selectedWallet.balance)">KES {{ formatMoney(selectedWallet.balance) }}</div></div>
          <div><span class="text-xs text-slate-500 dark:text-slate-400">Total Topups</span><div class="text-sm font-medium text-slate-900 dark:text-slate-100">KES {{ formatMoney(selectedWallet.total_topups) }}</div></div>
        </div>
        <div v-if="walletHistory.length" class="mt-4">
          <h4 class="text-sm font-semibold text-slate-700 mb-2">Recent Transactions</h4>
          <div class="space-y-2">
            <div v-for="tx in walletHistory" :key="tx.id" class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ tx.description || tx.type }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatDateTime(tx.created_at) }}</div>
              </div>
              <div class="text-sm font-bold" :class="tx.amount > 0 ? 'text-green-600' : 'text-red-600'">{{ tx.amount > 0 ? '+' : '' }}KES {{ formatMoney(Math.abs(tx.amount)) }}</div>
            </div>
          </div>
        </div>
        <div v-else class="text-sm text-slate-500 text-center py-4">No transaction history available.</div>
      </div>
      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showHistoryOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Close
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Add Balance Overlay -->
    <SlideOverlay v-model="showAddBalanceOverlay" title="Add Balance" :subtitle="balanceTarget?.username || 'Select a user'" icon="Plus" width="60%">
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Amount (KES)</label>
          <input v-model.number="balanceAmount" type="number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
          <input v-model="balanceDescription" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Reason for adjustment" />
        </div>
      </div>
      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showAddBalanceOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Cancel
          </button>
          <button
            @click="submitBalance('credit')"
            :disabled="submitting"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ submitting ? 'Adding...' : 'Add Balance' }}
          </button>
        </div>
      </template>
    </SlideOverlay>
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
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useWalletBalance } from '@/modules/tenant/composables/useWalletBalance.js'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/dashboard/billing' },
  { label: 'Wallet Balance' }
]

const {
  loading, refreshing, submitting,
  wallets, walletHistory, selectedWallet,
  balanceTarget, balanceAmount, balanceDescription, balanceAction,
  showHistoryOverlay, showAddBalanceOverlay,
  stats,
  formatMoney, formatDateTime,
  getBalanceColor, getStatusVariant, getStatusLabel,
  fetchWallets, refreshData, viewHistory,
  openAddBalanceModal, addBalance, deductBalance, submitBalance
} = useWalletBalance()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(15)
const filters = ref({ status: '' })

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
const clearFilters = () => { filters.value = { status: '' }; searchQuery.value = '' }

onMounted(fetchWallets)
</script>
