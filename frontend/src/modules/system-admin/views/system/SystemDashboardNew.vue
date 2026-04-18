<template>
  <div class="bg-slate-50 dark:bg-slate-900 -mx-2 -my-2 px-2 py-4 pb-10 sm:-mx-6 sm:-my-6 sm:px-6 sm:py-6 sm:pb-16 transition-colors duration-200">
    <!-- Header -->
    <div class="mb-5">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-gradient-to-br from-indigo-600 to-blue-600 rounded-xl flex items-center justify-center shadow flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <div>
            <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">System Administration</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400">Platform-wide monitoring &amp; management</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button @click="hardRefresh" :disabled="refreshing"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors shadow-sm disabled:opacity-50">
            <RefreshCw class="w-3.5 h-3.5" :class="refreshing ? 'animate-spin text-blue-500' : ''" />
            Refresh
          </button>
          <div v-if="lastUpdated" class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-sm">
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-xs text-slate-500 dark:text-slate-400">{{ formatTimeAgo(lastUpdated) }}</span>
          </div>
          <div class="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-semibold shadow">
            <span class="relative flex h-2 w-2">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-60"></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
            </span>
            Live
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-24">
      <div class="text-center">
        <div class="w-10 h-10 border-2 border-indigo-100 border-t-indigo-600 rounded-full animate-spin mx-auto mb-3"></div>
        <p class="text-sm text-slate-500 dark:text-slate-400">Loading platform statistics...</p>
      </div>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-5">

      <!-- Error Alert -->
      <div v-if="error" class="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-xl text-sm">
        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
        <span class="font-medium">{{ error }}</span>
      </div>

      <!-- ── ROW 1: 6 KPI CARDS ────────────────────────────────────── -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3" id="kpi-cards">

        <!-- Total Tenants -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openStatDetail('tenants')">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-900/60 transition-colors">
              <Building2 class="w-4 h-4 text-blue-600 dark:text-blue-400" />
            </div>
            <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded-full">ALL</span>
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.totalTenants || 0 }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Total Tenants</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
            {{ stats.tenants?.suspended ?? 0 }} suspended · {{ stats.tenants?.on_trial ?? 0 }} trial
          </div>
        </div>

        <!-- Active Tenants -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openStatDetail('active')">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-emerald-100 dark:bg-emerald-900/40 rounded-lg flex items-center justify-center group-hover:bg-emerald-200 dark:group-hover:bg-emerald-900/60 transition-colors">
              <CheckCircle class="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
            </div>
            <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded-full">
              {{ stats.totalTenants ? Math.round((stats.activeTenants / stats.totalTenants) * 100) : 0 }}%
            </span>
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.activeTenants || 0 }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Active Tenants</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
            {{ (stats.totalTenants || 0) - (stats.activeTenants || 0) }} inactive
          </div>
        </div>

        <!-- Platform Users -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openStatDetail('users')">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-violet-100 dark:bg-violet-900/40 rounded-lg flex items-center justify-center group-hover:bg-violet-200 dark:group-hover:bg-violet-900/60 transition-colors">
              <Users class="w-4 h-4 text-violet-600 dark:text-violet-400" />
            </div>
            <span class="text-[10px] font-bold text-violet-600 bg-violet-50 px-1.5 py-0.5 rounded-full">ALL</span>
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.totalUsers || 0 }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Platform Users</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
            {{ stats.users?.admin_users ?? 0 }} admin · {{ (stats.users?.hotspot_users ?? 0) + (stats.users?.pppoe_users ?? 0) }} service
          </div>
        </div>

        <!-- Network Routers -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openStatDetail('routers')">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 dark:group-hover:bg-indigo-900/60 transition-colors">
              <Wifi class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
            </div>
            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
              :class="routerOnlinePct >= 90 ? 'text-emerald-700 bg-emerald-50' : routerOnlinePct >= 60 ? 'text-amber-700 bg-amber-50' : 'text-red-700 bg-red-50'">
              {{ routerOnlinePct }}% up
            </span>
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.routers?.online ?? 0 }}<span class="text-sm font-normal text-slate-400">/{{ stats.totalRouters || 0 }}</span></div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Routers Online</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">{{ stats.routers?.offline ?? 0 }} offline</div>
        </div>

        <!-- Total Revenue -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openStatDetail('revenue')">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center group-hover:bg-green-200 dark:group-hover:bg-green-900/60 transition-colors">
              <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="text-[10px] font-bold text-green-700 bg-green-50 px-1.5 py-0.5 rounded-full">ALL TIME</span>
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(stats.totalRevenue) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Total Revenue</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">Platform-wide</div>
        </div>

        <!-- Monthly Revenue -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all cursor-pointer group" @click="openStatDetail('revenue')">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-teal-100 dark:bg-teal-900/40 rounded-lg flex items-center justify-center group-hover:bg-teal-200 dark:group-hover:bg-teal-900/60 transition-colors">
              <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <span class="text-[10px] font-bold text-teal-700 bg-teal-50 px-1.5 py-0.5 rounded-full">MTD</span>
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(stats.monthlyRevenue) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Monthly Revenue</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">Current month</div>
        </div>

      </div>

      <!-- ── ROW 2: SUBSCRIPTION HEALTH STRIP ──────────────────────── -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-5 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
          <div class="flex-1">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Subscription Health</span>
              <span class="text-xs text-slate-500 dark:text-slate-400">{{ stats.subscriptions?.active ?? 0 }} active of {{ stats.totalTenants || 0 }}</span>
            </div>
            <div class="flex h-2.5 rounded-full overflow-hidden bg-slate-100 gap-px">
              <div class="h-full bg-emerald-500 transition-all" :style="{ width: subPct('active') + '%' }" :title="`Active: ${stats.subscriptions?.active ?? 0}`" />
              <div class="h-full bg-amber-400 transition-all" :style="{ width: subPct('expiring') + '%' }" :title="`Expiring soon: ${stats.subscriptions?.expiring_soon ?? 0}`" />
              <div class="h-full bg-red-400 transition-all" :style="{ width: subPct('expired') + '%' }" :title="`Expired: ${stats.subscriptions?.expired ?? 0}`" />
            </div>
          </div>
          <div class="flex items-center gap-4 text-xs flex-shrink-0">
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
              <span class="text-slate-600 dark:text-slate-400">Active <strong class="text-slate-800 dark:text-slate-200">{{ stats.subscriptions?.active ?? 0 }}</strong></span>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-amber-400 flex-shrink-0"></span>
              <span class="text-slate-600 dark:text-slate-400">Expiring <strong class="text-slate-800 dark:text-slate-200">{{ stats.subscriptions?.expiring_soon ?? 0 }}</strong></span>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-red-400 flex-shrink-0"></span>
              <span class="text-slate-600 dark:text-slate-400">Expired <strong class="text-slate-800 dark:text-slate-200">{{ stats.subscriptions?.expired ?? 0 }}</strong></span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── ROW 3: SYSTEM MONITORING WIDGETS ──────────────────────── -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <SystemHealthWidget />
        <QueueStatsWidget />
        <PerformanceMetricsWidget />
      </div>

      <!-- ── ROW 4: USER BREAKDOWN + ROUTER HEALTH SIDE BY SIDE ───── -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <!-- User Breakdown -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
          <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-4">User Breakdown</h3>
          <div class="space-y-3">
            <div v-for="seg in userSegments" :key="seg.label" class="flex items-center gap-3">
              <div class="w-28 text-xs text-slate-500 dark:text-slate-400 flex-shrink-0">{{ seg.label }}</div>
              <div class="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-2 overflow-hidden">
                <div class="h-full rounded-full transition-all" :class="seg.color" :style="{ width: seg.pct + '%' }" />
              </div>
              <div class="w-12 text-xs font-semibold text-slate-700 dark:text-slate-300 text-right flex-shrink-0">{{ seg.value }}</div>
            </div>
          </div>
        </div>

        <!-- Router Health -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
          <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-4">Router Health</h3>
          <div class="flex items-center justify-center gap-8">
            <div class="relative w-28 h-28">
              <svg viewBox="0 0 36 36" class="w-28 h-28 -rotate-90">
                <circle cx="18" cy="18" r="15.9" fill="none" class="stroke-slate-100" stroke-width="3" />
                <circle cx="18" cy="18" r="15.9" fill="none"
                  :class="routerOnlinePct >= 90 ? 'stroke-emerald-500' : routerOnlinePct >= 60 ? 'stroke-amber-400' : 'stroke-red-500'"
                  stroke-width="3" stroke-linecap="round"
                  :stroke-dasharray="`${routerOnlinePct} ${100 - routerOnlinePct}`"
                  stroke-dashoffset="0" />
              </svg>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ routerOnlinePct }}%</span>
                <span class="text-[10px] text-slate-400 dark:text-slate-500">online</span>
              </div>
            </div>
            <div class="space-y-2 text-sm">
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                <span class="text-slate-600 dark:text-slate-400">Online</span>
                <span class="font-semibold text-slate-800 dark:text-slate-200 ml-auto">{{ stats.routers?.online ?? 0 }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-red-400 flex-shrink-0"></span>
                <span class="text-slate-600 dark:text-slate-400">Offline</span>
                <span class="font-semibold text-slate-800 dark:text-slate-200 ml-auto">{{ stats.routers?.offline ?? 0 }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-slate-300 dark:bg-slate-600 flex-shrink-0"></span>
                <span class="text-slate-600 dark:text-slate-400">Total</span>
                <span class="font-semibold text-slate-800 dark:text-slate-200 ml-auto">{{ stats.totalRouters || 0 }}</span>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- ── ROW 5: RECENT ACTIVITY TABLE ──────────────────────────── -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100 dark:border-slate-700">
          <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Recent Platform Activity</h2>
          <button @click="fetchActivities" :disabled="activitiesLoading" class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors">
            <RefreshCw class="w-3.5 h-3.5 text-slate-500 dark:text-slate-400" :class="activitiesLoading ? 'animate-spin' : ''" />
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[580px]">
            <thead class="bg-slate-50 dark:bg-slate-900/50 border-b border-slate-100 dark:border-slate-700">
              <tr>
                <th class="text-left px-5 py-2.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Time</th>
                <th class="text-left px-5 py-2.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">User</th>
                <th class="text-left px-5 py-2.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Action</th>
                <th class="text-left px-5 py-2.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide hidden sm:table-cell">Details</th>
                <th class="text-right px-5 py-2.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">View</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 dark:divide-slate-700/50">
              <tr v-for="log in recentActivities" :key="log.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors cursor-pointer" @click="openActivityDetail(log)">
                <td class="px-5 py-3 text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ formatActivityDate(log.created_at) }}</td>
                <td class="px-5 py-3 text-sm font-medium text-slate-800 dark:text-slate-200">{{ log.user?.name || log.causer?.name || log.username || '-' }}</td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" :class="activityActionClass(log.action || log.event || log.description)">
                    {{ log.action || log.event || log.description || '-' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-xs text-slate-400 dark:text-slate-500 max-w-xs truncate hidden sm:table-cell">{{ log.description || '-' }}</td>
                <td class="px-5 py-3 text-right">
                  <button @click.stop="openActivityDetail(log)" class="p-1 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-3.5 h-3.5" /></button>
                </td>
              </tr>
              <tr v-if="recentActivities.length === 0">
                <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400 dark:text-slate-500">{{ activitiesLoading ? 'Loading...' : 'No recent activity' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- Stat Detail Overlay -->
    <SlideOverlay v-model="showStatOverlay" :title="statOverlayTitle" :subtitle="statOverlaySubtitle" :icon="statOverlayIcon" width="50%" @close="showStatOverlay = false">
      <div class="space-y-4">
        <div class="flex items-center justify-between p-4 rounded-lg" :class="statOverlayBg">
          <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Current Count</span>
          <span class="text-3xl font-bold" :class="statOverlayColor">{{ statOverlayValue }}</span>
        </div>
        <div v-if="statOverlayDetails.length" class="space-y-2">
          <div v-for="(item, idx) in statOverlayDetails" :key="idx" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
            <span class="text-sm font-medium text-gray-600 dark:text-slate-400">{{ item.label }}</span>
            <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ item.value }}</span>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showStatOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Activity Detail Overlay -->
    <SlideOverlay v-model="showActivityOverlay" title="Activity Detail" subtitle="Full activity log entry" icon="FileText" width="50%" @close="showActivityOverlay = false">
      <div v-if="selectedActivity" class="space-y-3">
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Time</span>
          <span class="text-sm text-gray-900 dark:text-slate-200">{{ formatActivityDate(selectedActivity.created_at) }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">User</span>
          <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ selectedActivity.user?.name || selectedActivity.causer?.name || selectedActivity.username || '-' }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Action</span>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="activityActionClass(selectedActivity.action || selectedActivity.event || selectedActivity.description)">
            {{ selectedActivity.action || selectedActivity.event || selectedActivity.description || '-' }}
          </span>
        </div>
        <div v-if="selectedActivity.description" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Description</span>
          <span class="text-sm text-gray-900 dark:text-slate-200 text-right max-w-[60%]">{{ selectedActivity.description }}</span>
        </div>
        <div v-if="selectedActivity.ip_address" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">IP Address</span>
          <span class="text-sm font-mono text-gray-900 dark:text-slate-200">{{ selectedActivity.ip_address }}</span>
        </div>
        <div v-if="selectedActivity.properties || selectedActivity.details">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-2 mt-4">Properties</h3>
          <div class="space-y-2">
            <div v-for="(val, prop) in (selectedActivity.properties || selectedActivity.details)" :key="prop" class="flex items-center justify-between p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
              <span class="text-sm font-medium text-gray-700 dark:text-slate-300 capitalize">{{ String(prop).replace(/_/g, ' ') }}</span>
              <span class="text-sm text-blue-700 dark:text-blue-400 text-right max-w-[60%] break-all">{{ typeof val === 'object' ? JSON.stringify(val) : val }}</span>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showActivityOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import axios from 'axios'
import { Building2, CheckCircle, Users, Wifi, RefreshCw, Eye, TrendingUp } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import SystemHealthWidget from '@/modules/system-admin/components/dashboard/SystemHealthWidget.vue'
import QueueStatsWidget from '@/modules/system-admin/components/dashboard/QueueStatsWidget.vue'
import PerformanceMetricsWidget from '@/modules/system-admin/components/dashboard/PerformanceMetricsWidget.vue'

// Use global axios instance (has auth interceptor with Bearer token)
// Do NOT create a standalone instance — it won't have the Authorization header
const api = axios

const authStore = useAuthStore()
const user = computed(() => authStore.user)

const loading = ref(true)
const refreshing = ref(false)
const error = ref(null)
const emptyStats = () => ({
  totalTenants: 0,
  activeTenants: 0,
  totalUsers: 0,
  totalRouters: 0,
  totalRevenue: 0,
  monthlyRevenue: 0,
  avgResponseTime: '0.00',
  uptime: '0.0',
  tenants: { total: 0, active: 0, suspended: 0, on_trial: 0 },
  users: { total: 0, admin_users: 0, service_users: 0, active: 0, admins: 0, hotspot_users: 0, pppoe_users: 0 },
  routers: { total: 0, online: 0, offline: 0 },
  packages: { total: 0, active: 0 },
  revenue: { total: 0, monthly: 0 },
  subscriptions: { active: 0, expiring_soon: 0, expired: 0 },
  systemHealth: {},
})
const stats = ref(emptyStats())

const lastUpdated = ref(null)

// Activity logs (real data)
const recentActivities = ref([])
const activitiesLoading = ref(false)

// Stat detail overlay
const showStatOverlay = ref(false)
const statOverlayTitle = ref('')
const statOverlaySubtitle = ref('')
const statOverlayIcon = ref('BarChart3')
const statOverlayValue = ref(0)
const statOverlayBg = ref('bg-blue-50')
const statOverlayColor = ref('text-blue-700')
const statOverlayDetails = ref([])

// Activity detail overlay
const showActivityOverlay = ref(false)
const selectedActivity = ref(null)

// ── Computed helpers ───────────────────────────────────────
const routerOnlinePct = computed(() => {
  const total = stats.value.totalRouters || 0
  if (total === 0) return 0
  return Math.round(((stats.value.routers?.online ?? 0) / total) * 100)
})

const subPct = (type) => {
  const total = stats.value.totalTenants || 1
  const vals = {
    active: stats.value.subscriptions?.active ?? 0,
    expiring: stats.value.subscriptions?.expiring_soon ?? 0,
    expired: stats.value.subscriptions?.expired ?? 0,
  }
  return Math.min(Math.round((vals[type] / total) * 100), 100)
}

const userSegments = computed(() => {
  const total = stats.value.totalUsers || 1
  const adminU = stats.value.users?.admin_users ?? 0
  const hotspot = stats.value.users?.hotspot_users ?? 0
  const pppoe = stats.value.users?.pppoe_users ?? 0
  return [
    { label: 'Admin/System', value: adminU, color: 'bg-indigo-500', pct: Math.round((adminU / total) * 100) },
    { label: 'Hotspot Users', value: hotspot, color: 'bg-blue-400', pct: Math.round((hotspot / total) * 100) },
    { label: 'PPPoE Users', value: pppoe, color: 'bg-violet-400', pct: Math.round((pppoe / total) * 100) },
  ]
})

const formatCurrency = (val) =>
  new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(val || 0)

const formatTimeAgo = (date) => {
  if (!date) return ''
  const seconds = Math.floor((new Date() - new Date(date)) / 1000)
  if (seconds < 60) return `${seconds}s ago`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  return `${hours}h ago`
}

const formatActivityDate = (dateStr) => {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const activityActionClass = (action) => {
  if (!action) return 'bg-gray-100 text-gray-700'
  const a = action.toLowerCase()
  if (a.includes('create') || a.includes('add')) return 'bg-green-100 text-green-700'
  if (a.includes('delete') || a.includes('remove') || a.includes('suspend')) return 'bg-red-100 text-red-700'
  if (a.includes('update') || a.includes('edit') || a.includes('change')) return 'bg-blue-100 text-blue-700'
  if (a.includes('login') || a.includes('auth')) return 'bg-purple-100 text-purple-700'
  return 'bg-gray-100 text-gray-700'
}

const openStatDetail = (type) => {
  const configs = {
    tenants: {
      title: 'Registered Tenants',
      subtitle: 'Customer tenants (excludes system tenants)',
      icon: 'Building2',
      value: stats.value.totalTenants || 0,
      bg: 'bg-blue-50',
      color: 'text-blue-700',
      details: [
        { label: 'Total Customer Tenants', value: stats.value.totalTenants || 0 },
        { label: 'Active', value: stats.value.activeTenants || 0 },
        { label: 'Suspended', value: stats.value.tenants?.suspended ?? 0 },
        { label: 'On Trial', value: stats.value.tenants?.on_trial ?? 0 },
        { label: 'Inactive', value: (stats.value.totalTenants || 0) - (stats.value.activeTenants || 0) },
      ]
    },
    active: {
      title: 'Active Tenants',
      subtitle: 'Currently active customer accounts',
      icon: 'CheckCircle',
      value: stats.value.activeTenants || 0,
      bg: 'bg-green-50',
      color: 'text-green-700',
      details: [
        { label: 'Active', value: stats.value.activeTenants || 0 },
        { label: 'Total', value: stats.value.totalTenants || 0 },
        { label: 'Active Rate', value: stats.value.totalTenants ? Math.round((stats.value.activeTenants / stats.value.totalTenants) * 100) + '%' : 'N/A' },
        { label: 'Subscriptions Active', value: stats.value.subscriptions?.active ?? 0 },
        { label: 'Expiring Soon', value: stats.value.subscriptions?.expiring_soon ?? 0 },
        { label: 'Expired', value: stats.value.subscriptions?.expired ?? 0 },
      ]
    },
    users: {
      title: 'Platform Users',
      subtitle: 'Admin + service users across all tenants',
      icon: 'Users',
      value: stats.value.totalUsers || 0,
      bg: 'bg-purple-50',
      color: 'text-purple-700',
      details: [
        { label: 'Total Users', value: stats.value.totalUsers || 0 },
        { label: 'Admin/System Users', value: stats.value.users?.admin_users ?? 0 },
        { label: 'Service Users', value: stats.value.users?.service_users ?? 0 },
        { label: 'Hotspot Users', value: stats.value.users?.hotspot_users ?? 0 },
        { label: 'PPPoE Users', value: stats.value.users?.pppoe_users ?? 0 },
        { label: 'Avg per Tenant', value: stats.value.totalTenants ? Math.round((stats.value.totalUsers || 0) / stats.value.totalTenants) : 'N/A' },
      ]
    },
    routers: {
      title: 'Network Routers',
      subtitle: 'Total routers across all tenants',
      icon: 'Wifi',
      value: stats.value.totalRouters || 0,
      bg: 'bg-indigo-50',
      color: 'text-indigo-700',
      details: [
        { label: 'Total Routers', value: stats.value.totalRouters || 0 },
        { label: 'Online', value: stats.value.routers?.online ?? 0 },
        { label: 'Offline', value: stats.value.routers?.offline ?? 0 },
        { label: 'Health %', value: routerOnlinePct.value + '%' },
        { label: 'Avg per Tenant', value: stats.value.totalTenants ? Math.round((stats.value.totalRouters || 0) / stats.value.totalTenants) : 'N/A' },
      ]
    },
    revenue: {
      title: 'Platform Revenue',
      subtitle: 'Aggregated across all customer tenants',
      icon: 'TrendingUp',
      value: formatCurrency(stats.value.totalRevenue),
      bg: 'bg-green-50',
      color: 'text-green-700',
      details: [
        { label: 'All-time Revenue', value: formatCurrency(stats.value.totalRevenue) },
        { label: 'This Month (MTD)', value: formatCurrency(stats.value.monthlyRevenue) },
      ]
    }
  }
  const cfg = configs[type]
  if (!cfg) return
  statOverlayTitle.value = cfg.title
  statOverlaySubtitle.value = cfg.subtitle
  statOverlayIcon.value = cfg.icon
  statOverlayValue.value = cfg.value
  statOverlayBg.value = cfg.bg
  statOverlayColor.value = cfg.color
  statOverlayDetails.value = cfg.details
  showStatOverlay.value = true
}

const openActivityDetail = (log) => {
  selectedActivity.value = log
  showActivityOverlay.value = true
}

const fetchActivities = async () => {
  try {
    activitiesLoading.value = true
    const res = await api.get('/system/activity-logs', { params: { per_page: 10 } })
    const data = res.data.data || res.data.logs || res.data
    if (Array.isArray(data)) {
      recentActivities.value = data.slice(0, 10)
    } else if (data.data) {
      recentActivities.value = data.data.slice(0, 10)
    } else {
      recentActivities.value = []
    }
  } catch (err) {
    if (err.response?.status === 401) return
    console.error('Failed to fetch activities:', err)
  } finally {
    activitiesLoading.value = false
  }
}

const hardRefresh = async () => {
  try {
    refreshing.value = true
    error.value = null
    const response = await api.post('/system/dashboard/refresh')
    if (response.data.success) {
      stats.value = { ...emptyStats(), ...response.data.data }
      lastUpdated.value = new Date().toISOString()
    }
  } catch (err) {
    if (err.response?.status === 401) return
    console.error('Hard refresh failed:', err)
    error.value = err.response?.data?.message || 'Refresh failed'
  } finally {
    refreshing.value = false
  }
}

const fetchStats = async (isInitial = false) => {
  try {
    if (isInitial) loading.value = true
    else refreshing.value = true
    error.value = null

    const response = await api.get('/system/dashboard/stats')
    if (response.data.success) {
      const data = response.data.data
      // Merge so nested objects that may be absent get empty defaults
      stats.value = { ...emptyStats(), ...data }
      lastUpdated.value = new Date().toISOString()
    }
  } catch (err) {
    // 401 handled globally — do not duplicate logout
    if (err.response?.status === 401) return
    console.error('Failed to fetch system stats:', err)
    error.value = err.response?.data?.message || 'Failed to load dashboard statistics'
    if (isInitial) stats.value = emptyStats()
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

let refreshInterval = null

onMounted(() => {
  fetchStats(true)
  fetchActivities()
  // Refresh stats every 30 seconds (without showing loading spinner)
  refreshInterval = setInterval(() => fetchStats(false), 30000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
