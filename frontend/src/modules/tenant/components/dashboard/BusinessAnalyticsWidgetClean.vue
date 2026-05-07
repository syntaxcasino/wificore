<template>
  <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 transition-colors">
    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">Business Analytics</h3>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">User retention, trends, and revenue insights</p>
    
    <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <!-- User Retention Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-green-100 dark:bg-green-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex justify-between items-center mb-1 sm:mb-2">
            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide truncate">User Retention</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 flex-shrink-0 ml-1">{{ analyticsData.retention.rate }}%</span>
          </div>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ analyticsData.retention.retainedUsers }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">of {{ analyticsData.retention.lastMonthUsers }} users retained</p>
        </div>
      </div>

      <!-- Revenue Average Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-purple-100 dark:bg-purple-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">Avg Revenue</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(analyticsData.revenueAverage) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Daily average</p>
        </div>
      </div>

      <!-- Revenue Peak Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-amber-100 dark:bg-amber-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">Peak Revenue</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ formatCurrency(analyticsData.revenuePeak) }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Highest day</p>
        </div>
      </div>

      <!-- Revenue Growth Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0" :class="analyticsData.revenueGrowth >= 0 ? 'bg-green-100 dark:bg-green-900/40' : 'bg-red-100 dark:bg-red-900/40'">
          <svg class="w-5 h-5 sm:w-6 sm:h-6" :class="analyticsData.revenueGrowth >= 0 ? 'text-green-600' : 'text-red-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">Revenue Growth</span>
          <p class="text-xl sm:text-2xl font-extrabold mb-1 truncate" :class="analyticsData.revenueGrowth >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
            {{ analyticsData.revenueGrowth >= 0 ? '+' : '' }}{{ analyticsData.revenueGrowth }}%
          </p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">vs last period</p>
        </div>
      </div>

      <!-- User Average Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-blue-100 dark:bg-blue-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">Avg Active Users</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ analyticsData.userAverage }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Daily average</p>
        </div>
      </div>

      <!-- User Peak Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-indigo-100 dark:bg-indigo-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">Peak Users</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ analyticsData.userPeak }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Highest day</p>
        </div>
      </div>

      <!-- User Growth Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0" :class="analyticsData.userGrowth >= 0 ? 'bg-green-100 dark:bg-green-900/40' : 'bg-red-100 dark:bg-red-900/40'">
          <svg class="w-5 h-5 sm:w-6 sm:h-6" :class="analyticsData.userGrowth >= 0 ? 'text-green-600' : 'text-red-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">User Growth</span>
          <p class="text-xl sm:text-2xl font-extrabold mb-1 truncate" :class="analyticsData.userGrowth >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
            {{ analyticsData.userGrowth >= 0 ? '+' : '' }}{{ analyticsData.userGrowth }}%
          </p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">vs last period</p>
        </div>
      </div>

      <!-- Access Points Summary Card -->
      <div class="flex items-start gap-3 sm:gap-4 p-3 sm:p-4 bg-white dark:bg-slate-700/50 border-2 border-slate-200 dark:border-slate-600 rounded-xl hover:border-slate-400 dark:hover:border-slate-500 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-cyan-100 dark:bg-cyan-900/40">
          <svg class="w-5 h-5 sm:w-6 sm:h-6 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-15.857 21.213 0" />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <span class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 sm:mb-2 truncate">Access Points</span>
          <p class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-1 truncate">{{ analyticsData.accessPoints.length }}</p>
          <p class="text-sm text-slate-500 dark:text-slate-400 truncate">Active locations</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  analyticsData: {
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
