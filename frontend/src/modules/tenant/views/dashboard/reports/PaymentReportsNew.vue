<template>
  <PageContainer>
    <PageHeader
      title="Payment Reports"
      subtitle="Analyze payment trends and revenue"
      icon="DollarSign"
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
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Total Revenue</div>
              <div class="text-2xl font-bold text-green-900">KES {{ formatMoney(stats.totalRevenue) }}</div>
            </div>
            <DollarSign class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Payments</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.totalPayments }}</div>
            </div>
            <CreditCard class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Avg Payment</div>
              <div class="text-2xl font-bold text-purple-900">KES {{ formatMoney(stats.avgPayment) }}</div>
            </div>
            <TrendingUp class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">M-Pesa</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.mpesaPercentage }}%</div>
            </div>
            <Smartphone class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <BaseSelect v-model="filters.period" class="w-36 sm:w-40">
          <option value="today">Today</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
          <option value="year">This Year</option>
        </BaseSelect>
        
        <BaseSelect v-model="filters.method" class="w-36">
          <option value="">All Methods</option>
          <option value="mpesa">M-Pesa</option>
          <option value="cash">Cash</option>
          <option value="bank">Bank</option>
        </BaseSelect>
      </div>
    </div>

    <!-- Content -->
    <PageContent>
      <div v-if="loading">
        <BaseLoading type="table" :rows="10" />
      </div>

      <div v-else class="space-y-6">
        <!-- Payment Methods Breakdown -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Payment Methods</h3>
            <div class="space-y-4">
              <div v-for="method in paymentMethods" :key="method.name">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-slate-700">{{ method.name }}</span>
                  <span class="text-sm font-bold text-slate-900">KES {{ formatMoney(method.amount) }}</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-2">
                  <div 
                    class="h-2 rounded-full transition-all"
                    :class="method.color"
                    :style="{ width: method.percentage + '%' }"
                  ></div>
                </div>
              </div>
            </div>
          </div>
        </BaseCard>

        <!-- Daily Revenue -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Daily Revenue</h3>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead class="bg-slate-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Payments</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Revenue</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">M-Pesa</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700">Cash</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="day in dailyRevenue" :key="day.date" class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm text-slate-900">{{ formatDate(day.date) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-900">{{ day.count }}</td>
                    <td class="px-4 py-3 text-sm font-bold text-green-600">KES {{ formatMoney(day.total) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-900">KES {{ formatMoney(day.mpesa) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-900">KES {{ formatMoney(day.cash) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { DollarSign, RefreshCw, Download, CreditCard, TrendingUp, Smartphone } from 'lucide-vue-next'
import axios from 'axios'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Payment Reports' }
]

const loading = ref(false)
const refreshing = ref(false)
const payments = ref([])

const filters = ref({
  period: 'month',
  method: ''
})

const stats = computed(() => {
  const total = payments.value.reduce((sum, p) => sum + Number(p.amount || 0), 0)
  const count = payments.value.length
  const mpesaCount = payments.value.filter(p => (p.payment_method || '').toLowerCase().includes('mpesa') || (p.payment_method || '').toLowerCase().includes('m-pesa')).length
  return {
    totalRevenue: total,
    totalPayments: count,
    avgPayment: count > 0 ? Math.round(total / count) : 0,
    mpesaPercentage: count > 0 ? Math.round((mpesaCount / count) * 100) : 0
  }
})

const methodColors = { 'M-Pesa': 'bg-green-500', 'Cash': 'bg-amber-500', 'Bank Transfer': 'bg-blue-500' }

const paymentMethods = computed(() => {
  const grouped = {}
  payments.value.forEach(p => {
    const method = p.payment_method || 'Other'
    if (!grouped[method]) grouped[method] = 0
    grouped[method] += Number(p.amount || 0)
  })
  const total = Object.values(grouped).reduce((s, v) => s + v, 0)
  return Object.entries(grouped).map(([name, amount]) => ({
    name,
    amount,
    percentage: total > 0 ? Math.round((amount / total) * 100) : 0,
    color: methodColors[name] || 'bg-slate-500'
  })).sort((a, b) => b.amount - a.amount)
})

const dailyRevenue = computed(() => {
  const grouped = {}
  payments.value.forEach(p => {
    const date = (p.created_at || '').slice(0, 10)
    if (!date) return
    if (!grouped[date]) grouped[date] = { date, count: 0, total: 0, mpesa: 0, cash: 0 }
    grouped[date].count++
    grouped[date].total += Number(p.amount || 0)
    const method = (p.payment_method || '').toLowerCase()
    if (method.includes('mpesa') || method.includes('m-pesa')) grouped[date].mpesa += Number(p.amount || 0)
    if (method.includes('cash')) grouped[date].cash += Number(p.amount || 0)
  })
  return Object.values(grouped).sort((a, b) => b.date.localeCompare(a.date)).slice(0, 30)
})

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const fetchPayments = async () => {
  const isInitial = payments.value.length === 0
  if (isInitial) loading.value = true
  try {
    const params = {}
    if (filters.value.method) params.payment_method = filters.value.method
    if (filters.value.period) params.period = filters.value.period
    const response = await axios.get('/payments', { params })
    const data = response.data?.data || response.data?.payments || []
    payments.value = Array.isArray(data) ? data : []
  } catch (err) {
    console.error('fetchPayments error:', err)
  } finally {
    loading.value = false
  }
}

const refreshData = async () => {
  refreshing.value = true
  await fetchPayments()
  refreshing.value = false
}

const exportReport = () => {
  const csv = [
    ['Date', 'Payments', 'Total Revenue', 'M-Pesa', 'Cash'].join(','),
    ...dailyRevenue.value.map(d => [d.date, d.count, d.total, d.mpesa, d.cash].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `payment-report-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

watch(() => [filters.value.period, filters.value.method], () => fetchPayments())

onMounted(() => {
  fetchPayments()
})
</script>
