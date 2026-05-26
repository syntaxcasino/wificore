<template>
  <DataViewContainer
    title="Payment Reports"
    subtitle="Analyze payment trends and revenue"
    color-theme="emerald"
    :breadcrumbs="breadcrumbs"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </template>

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

    <template #stats>
      <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
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
    </template>

    <template #filters>
      <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
        <div class="flex items-center gap-3 flex-wrap">
          <BaseSelect v-model="filters.period" class="w-36 sm:w-40">
            <option value="today">Today</option>
            <option value="week">This Week</option>
            <option value="month">This Month</option>
            <option value="year">This Year</option>
          </BaseSelect>
          <BaseSelect v-model="filters.method" placeholder="All Methods" class="w-36">
            <option value="">All Methods</option>
            <option value="mpesa">M-Pesa</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
          </BaseSelect>
        </div>
      </div>
    </template>

    <!-- Content -->
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
                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ method.name }}</span>
                <span class="text-sm font-bold text-slate-900 dark:text-slate-100">KES {{ formatMoney(method.amount) }}</span>
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
              <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
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
  </DataViewContainer>
</template>

<script setup>
import { onMounted } from 'vue'
import { scheduleAfterPaint } from '@/modules/common/composables/performance/useViewCache'
import { DollarSign, RefreshCw, Download, CreditCard, TrendingUp, Smartphone } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import { usePaymentReports } from '@/modules/tenant/composables/usePaymentReports.js'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Reports', to: '/dashboard/reports' },
  { label: 'Payment Reports' }
]

const {
  loading, refreshing, payments, filters,
  stats, paymentMethods, dailyRevenue,
  formatMoney, formatDate,
  fetchPayments, refreshData, exportReport
} = usePaymentReports()

const handleExport = () => exportReport(dailyRevenue.value)

onMounted(() => {
  scheduleAfterPaint(fetchPayments)
})
</script>
