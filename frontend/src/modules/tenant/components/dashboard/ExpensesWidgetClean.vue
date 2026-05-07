<template>
  <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 transition-colors">
    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">SMS Expenses</h3>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Track SMS credits and usage</p>
    
    <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <!-- SMS Balance Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-red-100 dark:bg-red-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">SMS Balance</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ expensesData.sms.remaining.toLocaleString() }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Remaining credits</p>
        </div>
      </div>

      <!-- Purchased Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-blue-100 dark:bg-blue-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">Purchased</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ expensesData.sms.totalPurchased.toLocaleString() }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Total SMS bought</p>
        </div>
      </div>

      <!-- Used Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-orange-100 dark:bg-orange-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">Used</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ expensesData.sms.used.toLocaleString() }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">SMS sent</p>
        </div>
      </div>

      <!-- Daily Usage Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-green-100 dark:bg-green-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">Daily Usage</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ expensesData.sms.dailyUsage.toLocaleString() }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Today's SMS</p>
        </div>
      </div>

      <!-- Weekly Usage Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-purple-100 dark:bg-purple-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">Weekly Usage</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ expensesData.sms.weeklyUsage.toLocaleString() }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Last 7 days</p>
        </div>
      </div>

      <!-- Monthly Usage Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-indigo-100 dark:bg-indigo-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">Monthly Usage</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ expensesData.sms.monthlyUsage.toLocaleString() }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">This month</p>
        </div>
      </div>

      <!-- Total Spent Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-amber-100 dark:bg-amber-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">Total Spent</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(expensesData.costs.totalSpent) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">All time</p>
        </div>
      </div>

      <!-- This Month Cost Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-teal-100 dark:bg-teal-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2">This Month</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(expensesData.costs.thisMonth) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Current month cost</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  expensesData: {
    type: Object,
    required: true
  }
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES',
    minimumFractionDigits: 0,
  }).format(value || 0)
}
</script>

<style scoped>
</style>
