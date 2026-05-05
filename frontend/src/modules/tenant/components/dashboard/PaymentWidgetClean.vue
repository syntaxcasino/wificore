<template>
  <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 transition-colors">
    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">Payment Analytics</h3>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Click any card to view detailed breakdown</p>
    
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <!-- Daily Card -->
      <button
        @click="openDetails('daily')"
        class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all cursor-pointer text-left w-full group min-w-0"
      >
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors bg-green-100 dark:bg-green-900/40 group-hover:bg-green-200 dark:group-hover:bg-green-900/60">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-1 sm:mb-2">
            <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Daily Income</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400">Today</span>
          </div>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(paymentData.daily.amount) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">{{ paymentData.daily.count }} payments</p>
        </div>
      </button>

      <!-- Weekly Card -->
      <button
        @click="openDetails('weekly')"
        class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all cursor-pointer text-left w-full group min-w-0"
      >
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors bg-blue-100 dark:bg-blue-900/40 group-hover:bg-blue-200 dark:group-hover:bg-blue-900/60">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-1 sm:mb-2">
            <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Weekly Income</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400">7 Days</span>
          </div>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(paymentData.weekly.amount) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">{{ paymentData.weekly.count }} payments</p>
        </div>
      </button>

      <!-- Monthly Card -->
      <button
        @click="openDetails('monthly')"
        class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all cursor-pointer text-left w-full group min-w-0"
      >
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors bg-purple-100 dark:bg-purple-900/40 group-hover:bg-purple-200 dark:group-hover:bg-purple-900/60">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-1 sm:mb-2">
            <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Monthly Income</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400">Month</span>
          </div>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(paymentData.monthly.amount) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">{{ paymentData.monthly.count }} payments</p>
        </div>
      </button>
    </div>

    <!-- Payment Details Overlay -->
    <SlideOverlay
      v-model="showDetails"
      :title="detailsTitle"
      :subtitle="detailsSubtitle"
      icon="CreditCard"
      width="50%"
      @close="closeDetails"
    >
      <!-- Daily Details -->
      <div v-if="activePanel === 'daily'" class="flex flex-col gap-6">
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Total Amount</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.daily.amount) }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Total Payments</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ paymentData.daily.count }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Date</div>
          <div class="text-lg font-extrabold text-slate-900 dark:text-slate-100">{{ paymentData.daily.date }}</div>
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-slate-100 mt-2">Payment Methods</div>
        <div class="flex flex-col gap-3">
          <div class="flex justify-between items-center p-4 bg-white dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl">
            <div class="flex flex-col gap-1"><span class="text-sm font-semibold text-slate-900 dark:text-slate-100">M-Pesa</span><span class="text-xs text-slate-500 dark:text-slate-400">45 transactions</span></div>
            <span class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.daily.amount * 0.6) }}</span>
          </div>
          <div class="flex justify-between items-center p-4 bg-white dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl">
            <div class="flex flex-col gap-1"><span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Cash</span><span class="text-xs text-slate-500 dark:text-slate-400">12 transactions</span></div>
            <span class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.daily.amount * 0.3) }}</span>
          </div>
          <div class="flex justify-between items-center p-4 bg-white dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl">
            <div class="flex flex-col gap-1"><span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Bank Transfer</span><span class="text-xs text-slate-500 dark:text-slate-400">5 transactions</span></div>
            <span class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.daily.amount * 0.1) }}</span>
          </div>
        </div>
      </div>

      <!-- Weekly Details -->
      <div v-if="activePanel === 'weekly'" class="flex flex-col gap-6">
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Total Amount</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.weekly.amount) }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Total Payments</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ paymentData.weekly.count }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Average per Day</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.weekly.amount / 7) }}</div>
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-slate-100 mt-2">Daily Breakdown</div>
        <div class="flex items-end justify-between gap-3 h-48 p-5 bg-slate-50 dark:bg-slate-700/50 rounded-xl">
          <div v-for="day in paymentData.weekly.dailyBreakdown" :key="day.date" class="flex-1 flex flex-col items-center gap-2">
            <div class="w-full flex items-end justify-center h-[120px]">
              <div class="w-4/5 bg-gradient-to-t from-blue-600 to-blue-400 dark:from-blue-500 dark:to-blue-300 rounded-t min-h-[8px] relative group/bar transition-all" :style="{ height: day.percentage + '%' }">
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 -translate-y-2 bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-[10px] font-semibold px-2 py-1 rounded whitespace-nowrap opacity-0 group-hover/bar:opacity-100 transition-opacity pointer-events-none">{{ formatCurrency(day.amount) }}</div>
              </div>
            </div>
            <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">{{ day.day }}</span>
            <span class="text-[10px] text-slate-400 dark:text-slate-500">{{ formatCurrency(day.amount) }}</span>
          </div>
        </div>
      </div>

      <!-- Monthly Details -->
      <div v-if="activePanel === 'monthly'" class="flex flex-col gap-6">
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Total Amount</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ formatCurrency(paymentData.monthly.amount) }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Total Payments</div>
          <div class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">{{ paymentData.monthly.count }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
          <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Period</div>
          <div class="text-lg font-extrabold text-slate-900 dark:text-slate-100">{{ paymentData.monthly.month }} {{ paymentData.monthly.year }}</div>
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-slate-100 mt-2">Weekly Breakdown</div>
        <div class="flex flex-col gap-4">
          <div v-for="week in paymentData.monthly.weeklyBreakdown" :key="week.week" class="p-4 bg-white dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-bold text-slate-900 dark:text-slate-100">Week {{ week.week }}</span>
              <span class="text-base font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(week.amount) }}</span>
            </div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mb-3">{{ week.startDate }} - {{ week.endDate }}</div>
            <div class="w-full h-2 bg-slate-200 dark:bg-slate-600 rounded-full overflow-hidden">
              <div class="h-full bg-gradient-to-r from-purple-500 to-violet-600 rounded-full transition-all duration-500" :style="{ width: week.percentage + '%' }"></div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="closeDetails"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 transition-colors"
          >
            Close
          </button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const props = defineProps({
  paymentData: {
    type: Object,
    required: true,
    default: () => ({
      daily: { amount: 0, date: '', count: 0 },
      weekly: { amount: 0, startDate: '', endDate: '', count: 0, dailyBreakdown: [] },
      monthly: { amount: 0, month: '', year: '', count: 0, weeklyBreakdown: [] }
    })
  }
})

const showDetails = ref(false)
const activePanel = ref('')

const detailsTitle = computed(() => {
  const titles = {
    daily: 'Daily Income Details',
    weekly: 'Weekly Income Details',
    monthly: 'Monthly Income Details'
  }
  return titles[activePanel.value] || ''
})

const detailsSubtitle = computed(() => {
  const subtitles = {
    daily: `Complete breakdown for ${props.paymentData.daily.date}`,
    weekly: `Analysis for ${props.paymentData.weekly.startDate} - ${props.paymentData.weekly.endDate}`,
    monthly: `Detailed view for ${props.paymentData.monthly.month} ${props.paymentData.monthly.year}`
  }
  return subtitles[activePanel.value] || ''
})

const openDetails = (panel) => {
  activePanel.value = panel
  showDetails.value = true
}

const closeDetails = () => {
  showDetails.value = false
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value || 0)
}
</script>

<style scoped>
</style>
