<template>
  <div :class="['min-h-screen flex flex-col transition-colors duration-200', isDark ? 'bg-gray-950 text-white' : 'bg-slate-100 text-gray-900']">

    <!-- ── Top Bar ── -->
    <header :class="['sticky top-0 z-40 flex items-center justify-between px-4 sm:px-6 h-16 backdrop-blur-md border-b transition-colors duration-200', isDark ? 'bg-gray-950/90 border-white/10' : 'bg-white/95 border-gray-200']">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/30 flex-shrink-0">
          <i class="fas fa-wifi text-white text-lg"></i>
        </div>
        <div class="hidden sm:flex flex-col leading-tight">
          <span :class="['font-semibold text-sm leading-tight', isDark ? 'text-white' : 'text-gray-800']">{{ dashboardData?.user?.provider_name || 'My Account' }}</span>
          <span v-if="dashboardData?.user?.provider_name" :class="['text-[10px]', isDark ? 'text-white/40' : 'text-gray-400']">Customer Portal</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <div :class="['hidden sm:flex items-center gap-2 rounded-lg px-3 py-1.5 border', isDark ? 'bg-white/5 border-white/10' : 'bg-gray-50 border-gray-200']">
          <div class="w-5 h-5 rounded-full bg-indigo-500/15 text-indigo-500 flex items-center justify-center">
            <i class="fas fa-user text-[10px]"></i>
          </div>
          <span :class="['text-sm font-medium', isDark ? 'text-white/90' : 'text-gray-700']">{{ user?.full_name || user?.username || 'Account' }}</span>
        </div>
        <!-- Theme Toggle - More Visible -->
        <button @click="isDark = !isDark" :class="['w-10 h-10 flex items-center justify-center rounded-xl border-2 transition-all duration-200 shadow-sm', isDark ? 'bg-amber-500/20 border-amber-500/50 text-amber-400 hover:bg-amber-500/30 hover:shadow-amber-500/20' : 'bg-indigo-100 border-indigo-300 text-indigo-600 hover:bg-indigo-200 hover:shadow-indigo-500/20']" title="Toggle theme">
          <i :class="['fas text-base', isDark ? 'fa-sun' : 'fa-moon']"></i>
        </button>
        <button @click="handleLogout" :class="['flex items-center gap-1.5 text-xs font-semibold px-4 py-2.5 rounded-xl border transition-colors', isDark ? 'bg-white/5 border-white/10 text-white/70 hover:text-red-400 hover:bg-red-500/10 hover:border-red-500/30' : 'bg-white border-gray-200 text-gray-600 hover:text-red-500 hover:bg-red-50 hover:border-red-200']">
          <i class="fas fa-arrow-right-from-bracket"></i>
          <span class="hidden sm:inline">Logout</span>
        </button>
      </div>
    </header>

    <!-- ── Nav Tabs ── -->
    <nav :class="['flex items-center gap-1 px-4 sm:px-6 pt-2 pb-2 overflow-x-auto border-b transition-colors duration-200', isDark ? 'border-white/10' : 'border-gray-200']">
      <button :class="['px-3 py-1.5 text-xs font-semibold rounded-lg whitespace-nowrap transition-colors', isDark ? 'bg-indigo-500/20 text-indigo-400' : 'bg-indigo-50 text-indigo-600']">
        <i class="fas fa-gauge-high mr-1.5"></i>Overview
      </button>
      <button @click="openPaymentsOverlay" :class="['px-3 py-1.5 text-xs font-semibold rounded-lg whitespace-nowrap transition-colors', isDark ? 'text-white/50 hover:bg-white/10 hover:text-white' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800']">
        <i class="fas fa-credit-card mr-1.5"></i>Payments
      </button>
      <button @click="openHistoryOverlay" :class="['px-3 py-1.5 text-xs font-semibold rounded-lg whitespace-nowrap transition-colors', isDark ? 'text-white/50 hover:bg-white/10 hover:text-white' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800']">
        <i class="fas fa-chart-bar mr-1.5"></i>Usage
      </button>
    </nav>

    <main class="flex-1 px-4 sm:px-6 lg:px-8 py-5 pb-28 sm:pb-10 max-w-7xl mx-auto w-full">

      <!-- Loading -->
      <div v-if="isLoading && !dashboardData" class="flex items-center justify-center h-64">
        <div class="text-center">
          <div class="w-10 h-10 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
          <p :class="['text-sm', isDark ? 'text-white/50' : 'text-gray-500']">Loading your account…</p>
        </div>
      </div>

      <!-- Error -->
      <div v-else-if="loadError" :class="['rounded-2xl p-5 mb-5 border border-red-400/30', isDark ? 'bg-white/5' : 'bg-white']">
        <div class="flex items-start gap-3">
          <i class="fas fa-circle-exclamation text-red-400 mt-0.5 flex-shrink-0"></i>
          <div class="flex-1">
            <p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">Failed to load dashboard</p>
            <p :class="['text-xs mt-1', isDark ? 'text-white/50' : 'text-gray-500']">{{ loadError }}</p>
            <button @click="loadDashboard" class="mt-3 px-4 py-2 bg-red-500/15 hover:bg-red-500/25 text-red-500 rounded-lg text-xs font-semibold transition-colors">Retry</button>
          </div>
        </div>
      </div>

      <template v-else-if="dashboardData">

        <!-- ── Hero ── -->
        <div :class="['rounded-2xl p-4 sm:p-5 mb-5 flex items-center gap-4 border text-white', accountStatus.heroClass]">
          <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center flex-shrink-0">
            <i :class="['fas text-xl', accountStatus.icon]"></i>
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-bold text-base leading-tight">{{ accountStatus.title }}</p>
            <p class="text-sm text-white/70 mt-0.5 truncate">{{ accountStatus.message }}</p>
          </div>
          <button v-if="dashboardData.user?.status !== 'paused'" @click="openPaymentModal"
            class="flex-shrink-0 bg-white/20 hover:bg-white/30 text-white text-xs font-semibold px-3 py-2 rounded-lg border border-white/25 whitespace-nowrap transition-colors">
            <i class="fas fa-bolt mr-1"></i>Pay Now
          </button>
        </div>

        <!-- ── Stats ── -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
          <!-- Session -->
          <div :class="['rounded-2xl p-4 flex flex-col border transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
            <div :class="['w-8 h-8 rounded-lg flex items-center justify-center mb-2 flex-shrink-0', isDark ? 'bg-emerald-500/15 text-emerald-400' : 'bg-emerald-100 text-emerald-600']"><i class="fas fa-signal text-sm"></i></div>
            <p :class="['text-[10px] font-semibold uppercase tracking-wider', isDark ? 'text-white/40' : 'text-gray-400']">Session</p>
            <p :class="['font-bold text-base mt-0.5 leading-tight', isDark ? 'text-white' : 'text-gray-900']">{{ dashboardData.current_session ? dashboardData.current_session.duration_formatted : '--' }}</p>
            <span :class="['mt-1.5 self-start text-[10px] font-bold px-2 py-0.5 rounded-full', dashboardData.current_session ? (isDark ? 'bg-emerald-500/15 text-emerald-400' : 'bg-emerald-100 text-emerald-700') : (isDark ? 'bg-white/10 text-white/40' : 'bg-gray-100 text-gray-500')]">{{ dashboardData.current_session ? 'Online' : 'Offline' }}</span>
          </div>
          <!-- Balance -->
          <div :class="['rounded-2xl p-4 flex flex-col border transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
            <div :class="['w-8 h-8 rounded-lg flex items-center justify-center mb-2 flex-shrink-0', isDark ? 'bg-blue-500/15 text-blue-400' : 'bg-blue-100 text-blue-600']"><i class="fas fa-wallet text-sm"></i></div>
            <p :class="['text-[10px] font-semibold uppercase tracking-wider', isDark ? 'text-white/40' : 'text-gray-400']">Balance</p>
            <p :class="['font-bold text-base mt-0.5 leading-tight', isDark ? 'text-white' : 'text-gray-900']">KES {{ formatNumber(dashboardData.user?.balance || 0) }}</p>
            <p :class="['mt-1 text-xs truncate', isDark ? 'text-white/40' : 'text-gray-400']">Exp: {{ formatDate(dashboardData.user?.expiration_date) }}</p>
          </div>
          <!-- Usage -->
          <div :class="['rounded-2xl p-4 flex flex-col border transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
            <div :class="['w-8 h-8 rounded-lg flex items-center justify-center mb-2 flex-shrink-0', isDark ? 'bg-purple-500/15 text-purple-400' : 'bg-purple-100 text-purple-600']"><i class="fas fa-chart-line text-sm"></i></div>
            <p :class="['text-[10px] font-semibold uppercase tracking-wider', isDark ? 'text-white/40' : 'text-gray-400']">30-Day Usage</p>
            <p :class="['font-bold text-base mt-0.5 leading-tight', isDark ? 'text-white' : 'text-gray-900']">{{ dashboardData.usage_stats?.total_usage_formatted || '0 B' }}</p>
            <p :class="['mt-1 text-xs', isDark ? 'text-white/40' : 'text-gray-400']">{{ dashboardData.usage_stats?.total_sessions || 0 }} sessions</p>
          </div>
          <!-- Payment -->
          <div :class="['rounded-2xl p-4 flex flex-col border transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
            <div :class="['w-8 h-8 rounded-lg flex items-center justify-center mb-2 flex-shrink-0', isDark ? 'bg-amber-500/15 text-amber-400' : 'bg-amber-100 text-amber-600']"><i class="fas fa-receipt text-sm"></i></div>
            <p :class="['text-[10px] font-semibold uppercase tracking-wider', isDark ? 'text-white/40' : 'text-gray-400']">Payment</p>
            <p :class="['font-bold text-base mt-0.5 leading-tight', isDark ? 'text-white' : 'text-gray-900']">{{ paymentStatusLabel }}</p>
            <p :class="['mt-1 text-xs truncate', isDark ? 'text-white/40' : 'text-gray-400']">Due {{ formatDate(dashboardData.user?.next_payment_due || dashboardData.user?.expiration_date) }}</p>
          </div>
        </div>

        <!-- ── Main grid ── -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
          <!-- Account Details -->
          <div :class="['lg:col-span-2 rounded-2xl overflow-hidden border transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
            <div :class="['px-4 py-3 flex items-center gap-2 border-b', isDark ? 'border-white/10' : 'border-gray-100']">
              <i class="fas fa-user-circle text-indigo-500 text-sm"></i>
              <h3 :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">Account Details</h3>
            </div>
            <div class="p-4 grid grid-cols-2 sm:grid-cols-3 gap-4">
              <div v-for="(cell, i) in [
                {l:'Account No.', v: dashboardData.user?.account_number||'--'},
                {l:'Username',    v: dashboardData.user?.username||'--'},
                {l:'Full Name',   v: dashboardData.user?.full_name||'--'},
                {l:'Phone',       v: dashboardData.user?.phone||'--'},
                {l:'Expires',     v: formatDate(dashboardData.user?.expiration_date)},
                {l:'Plan',        v: dashboardData.user?.package?.name||'No Plan', tr:true},
                {l:'Plan Price',  v: 'KES '+formatNumber(planAmount)},
                {l:'Paid',        v: 'KES '+formatNumber(dashboardData.user?.amount_paid||0)},
              ]" :key="i" class="flex flex-col gap-0.5">
                <p :class="['text-[10px] font-semibold uppercase tracking-wider', isDark ? 'text-white/40' : 'text-gray-400']">{{ cell.l }}</p>
                <p :class="['text-xs font-semibold', cell.tr ? 'truncate' : '', isDark ? 'text-white' : 'text-gray-800']">{{ cell.v }}</p>
              </div>
              <div class="flex flex-col gap-0.5">
                <p :class="['text-[10px] font-semibold uppercase tracking-wider', isDark ? 'text-white/40' : 'text-gray-400']">Amount Due</p>
                <p :class="['text-xs font-bold', isDark ? 'text-amber-400' : 'text-amber-600']">KES {{ formatNumber(paymentAmount) }}</p>
              </div>
            </div>
          </div>

          <!-- Quick Actions -->
          <div :class="['rounded-2xl overflow-hidden border transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
            <div :class="['px-4 py-3 flex items-center gap-2 border-b', isDark ? 'border-white/10' : 'border-gray-100']">
              <i class="fas fa-bolt text-yellow-500 text-sm"></i>
              <h3 :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">Quick Actions</h3>
            </div>
            <div class="p-4 space-y-2">
              <button @click="openPaymentModal" :class="['w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold border transition-colors', isDark ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20 hover:bg-emerald-500/25' : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100']">
                <i class="fas fa-mobile-screen-button"></i>Pay via M-Pesa
              </button>
              <button @click="showVoucherModal = true" :class="['w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold border transition-colors', isDark ? 'bg-indigo-500/15 text-indigo-400 border-indigo-500/20 hover:bg-indigo-500/25' : 'bg-indigo-50 text-indigo-700 border-indigo-200 hover:bg-indigo-100']">
                <i class="fas fa-ticket"></i>Redeem Voucher
              </button>
              <button @click="openTimedVoucherOverlay" :class="['w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold border transition-colors', isDark ? 'bg-purple-500/15 text-purple-400 border-purple-500/20 hover:bg-purple-500/25' : 'bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100']">
                <i class="fas fa-hourglass-half"></i>Buy Timed Voucher
              </button>
              <div :class="['pt-2 space-y-2 border-t', isDark ? 'border-white/10' : 'border-gray-100']">
                <button @click="openPlanSwitchOverlay" :class="['w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold border transition-colors', isDark ? 'bg-white/5 text-white/60 border-white/10 hover:bg-white/10 hover:text-white' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100']">
                  <i class="fas fa-arrows-rotate"></i>Switch Plan
                  <span v-if="dashboardData.user?.pending_package_id" :class="['ml-auto text-[10px] px-1.5 py-0.5 rounded-full font-semibold', isDark ? 'bg-amber-500/20 text-amber-400' : 'bg-amber-100 text-amber-700']">Pending</span>
                </button>
                <button @click="openPauseOverlay" :class="['w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold border transition-colors',
                  dashboardData.user?.is_paused
                    ? (isDark ? 'bg-amber-500/15 text-amber-400 border-amber-500/20 hover:bg-amber-500/25' : 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100')
                    : (isDark ? 'bg-white/5 text-white/60 border-white/10 hover:bg-white/10 hover:text-white' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100')]">
                  <i :class="['fas', dashboardData.user?.is_paused ? 'fa-circle-play' : 'fa-circle-pause']"></i>
                  {{ dashboardData.user?.is_paused ? 'Resume Account' : 'Pause Account' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Live Session ── -->
        <div v-if="dashboardData.current_session" :class="['rounded-2xl overflow-hidden border mb-5 transition-colors', isDark ? 'bg-white/5 border-white/10' : 'bg-white border-gray-200']">
          <div :class="['px-4 py-3 flex items-center gap-2 border-b', isDark ? 'border-white/10' : 'border-gray-100']">
            <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse flex-shrink-0"></span>
            <h3 :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">Live Session</h3>
            <span :class="['ml-auto text-xs font-mono', isDark ? 'text-white/40' : 'text-gray-400']">{{ dashboardData.current_session.ip_address }}</span>
          </div>
          <div class="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div v-for="(s, i) in [
              {icon:'fa-arrow-down',   color:'text-emerald-500', label:'Download', val: dashboardData.current_session.download_formatted},
              {icon:'fa-arrow-up',     color:'text-blue-500',    label:'Upload',   val: dashboardData.current_session.upload_formatted},
              {icon:'fa-clock',        color:'text-purple-500',  label:'Duration', val: dashboardData.current_session.duration_formatted},
              {icon:'fa-network-wired',color:'text-amber-500',   label:'IP',       val: dashboardData.current_session.ip_address, mono:true},
            ]" :key="i" :class="['flex flex-col items-center justify-center p-3 rounded-xl text-center', isDark ? 'bg-white/5' : 'bg-slate-50']">
              <i :class="['fas mb-1', s.icon, s.color]"></i>
              <p :class="['text-xs mb-0.5', isDark ? 'text-white/40' : 'text-gray-400']">{{ s.label }}</p>
              <p :class="['font-semibold text-sm', s.mono ? 'font-mono text-xs' : '', isDark ? 'text-white' : 'text-gray-800']">{{ s.val }}</p>
            </div>
          </div>
        </div>

      </template>

      <!-- No data fallback -->
      <div v-else :class="['rounded-2xl p-5 border border-amber-400/30', isDark ? 'bg-white/5' : 'bg-white']">
        <div class="flex items-start gap-3">
          <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0"></i>
          <div>
            <p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">Dashboard temporarily unavailable</p>
            <p :class="['text-xs mt-1', isDark ? 'text-white/50' : 'text-gray-500']">Please retry or log in again.</p>
            <button @click="loadDashboard" class="mt-3 px-4 py-2 bg-amber-500/15 hover:bg-amber-500/25 text-amber-500 rounded-lg text-xs font-semibold transition-colors">Retry</button>
          </div>
        </div>
      </div>

    </main>

    <!-- ── Footer ── -->
    <footer :class="['hidden sm:block border-t px-6 py-4 transition-colors duration-200', isDark ? 'border-white/10 bg-gray-950' : 'border-gray-200 bg-white']">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <div class="w-5 h-5 bg-indigo-600 rounded flex items-center justify-center"><i class="fas fa-wifi text-white text-[9px]"></i></div>
          <span :class="['text-xs font-semibold', isDark ? 'text-white/50' : 'text-gray-400']">{{ dashboardData?.user?.provider_name || 'WifiCore Portal' }}</span>
        </div>
        <p :class="['text-xs', isDark ? 'text-white/30' : 'text-gray-400']">Need help? Contact <span v-if="dashboardData?.user?.provider_name" class="font-medium">{{ dashboardData.user.provider_name }}</span><span v-else>your ISP</span></p>
        <p :class="['text-xs', isDark ? 'text-white/25' : 'text-gray-400']">© {{ new Date().getFullYear() }}</p>
      </div>
    </footer>

    <!-- ── Mobile bottom nav ── -->
    <nav :class="['fixed bottom-0 left-0 right-0 sm:hidden flex items-center justify-around px-2 pt-2 pb-3 z-40 border-t backdrop-blur-md transition-colors', isDark ? 'bg-gray-950/95 border-white/10' : 'bg-white/97 border-gray-200']">
      <button :class="['flex flex-col items-center gap-1 flex-1 py-1 text-[10px] font-semibold rounded-lg', isDark ? 'text-indigo-400' : 'text-indigo-600']"><i class="fas fa-gauge-high text-lg"></i>Home</button>
      <button @click="openPaymentsOverlay" :class="['flex flex-col items-center gap-1 flex-1 py-1 text-[10px] font-semibold', isDark ? 'text-white/40 hover:text-white' : 'text-gray-400 hover:text-gray-700']"><i class="fas fa-credit-card text-lg"></i>Pay</button>
      <button @click="openPaymentModal" class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-indigo-700 rounded-full flex items-center justify-center text-white shadow-lg shadow-indigo-500/40 flex-shrink-0"><i class="fas fa-bolt text-xl"></i></button>
      <button @click="openHistoryOverlay" :class="['flex flex-col items-center gap-1 flex-1 py-1 text-[10px] font-semibold', isDark ? 'text-white/40 hover:text-white' : 'text-gray-400 hover:text-gray-700']"><i class="fas fa-chart-bar text-lg"></i>Usage</button>
      <button @click="handleLogout" :class="['flex flex-col items-center gap-1 flex-1 py-1 text-[10px] font-semibold', isDark ? 'text-white/40 hover:text-red-400' : 'text-gray-400 hover:text-red-500']"><i class="fas fa-arrow-right-from-bracket text-lg"></i>Logout</button>
    </nav>

    <!-- ── M-Pesa Payment Modal ── -->
    <Teleport to="body">
      <div v-if="showPaymentModal" :class="['fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4', isDark ? 'bg-black/80' : 'bg-black/50']">
        <div :class="['w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl overflow-hidden border shadow-2xl', isDark ? 'bg-gray-900 border-white/10' : 'bg-white border-gray-200']">
          <!-- Header -->
          <div :class="['flex items-center justify-between px-5 pt-5 pb-4 border-b', isDark ? 'border-white/10' : 'border-gray-100']">
            <div class="flex items-center gap-2">
              <div :class="['w-7 h-7 rounded-lg flex items-center justify-center', isDark ? 'bg-emerald-500/20' : 'bg-emerald-100']"><i :class="['fas fa-mobile-screen-button text-sm', isDark ? 'text-emerald-400' : 'text-emerald-600']"></i></div>
              <h3 :class="['font-bold text-base', isDark ? 'text-white' : 'text-gray-800']">M-Pesa Payment</h3>
            </div>
            <button @click="closePaymentModal" :disabled="paymentStep === 'processing'" :class="['transition-colors disabled:opacity-20', isDark ? 'text-white/40 hover:text-white' : 'text-gray-400 hover:text-gray-700']"><i class="fas fa-xmark text-lg"></i></button>
          </div>
          <!-- Steps -->
          <div :class="['flex items-center px-5 py-3 gap-2 border-b', isDark ? 'border-white/5' : 'border-gray-100']">
            <div :class="['w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0', paymentStep==='form' ? 'bg-indigo-500 text-white' : 'bg-emerald-500 text-white']">1</div>
            <div :class="['flex-1 h-px', isDark ? 'bg-white/10' : 'bg-gray-200']"></div>
            <div :class="['w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0', paymentStep==='processing' ? 'bg-amber-500 text-white' : (paymentStep==='success'||paymentStep==='failed') ? 'bg-emerald-500 text-white' : (isDark ? 'bg-white/10 text-white/30' : 'bg-gray-100 text-gray-400')]">2</div>
            <div :class="['flex-1 h-px', isDark ? 'bg-white/10' : 'bg-gray-200']"></div>
            <div :class="['w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0', paymentStep==='success' ? 'bg-emerald-500 text-white' : paymentStep==='failed' ? 'bg-red-500 text-white' : (isDark ? 'bg-white/10 text-white/30' : 'bg-gray-100 text-gray-400')]">3</div>
          </div>
          <!-- Body -->
          <div class="px-5 py-5 space-y-4">
            <template v-if="paymentStep === 'form'">
              <div>
                <label :class="['block text-xs font-semibold uppercase tracking-wider mb-2', isDark ? 'text-white/50' : 'text-gray-500']">M-Pesa Number</label>
                <div class="relative">
                  <span :class="['absolute left-3.5 top-1/2 -translate-y-1/2 font-medium text-sm', isDark ? 'text-white/40' : 'text-gray-400']">254</span>
                  <input v-model="paymentForm.phone" type="tel" placeholder="7XX XXX XXX" maxlength="9" required :class="['w-full pl-12 pr-4 py-3 rounded-xl text-sm border outline-none transition-colors', isDark ? 'bg-white/8 border-white/15 text-white placeholder-white/30 focus:border-indigo-500/60' : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400 focus:border-indigo-400']" />
                </div>
              </div>
              <div :class="['rounded-xl p-4 border', isDark ? 'bg-white/5 border-emerald-500/20' : 'bg-emerald-50 border-emerald-200']">
                <p :class="['text-xs', isDark ? 'text-white/50' : 'text-gray-500']">{{ dashboardData?.user?.package?.name || 'Renewal' }}</p>
                <p :class="['text-2xl font-bold mt-0.5', isDark ? 'text-white' : 'text-gray-900']">KES {{ formatNumber(paymentAmount) }}</p>
                <p :class="['text-xs mt-1', isDark ? 'text-white/40' : 'text-gray-400']">Account renewed upon confirmation</p>
              </div>
              <div v-if="paymentErrorMessage" class="rounded-xl bg-red-500/10 border border-red-500/25 px-4 py-3 text-sm text-red-500 flex items-start gap-2">
                <i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><p>{{ paymentErrorMessage }}</p>
              </div>
              <button @click="handleMpesaPayment" :disabled="paymentLoading || !paymentForm.phone" class="w-full py-3.5 bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2 disabled:opacity-40">
                <i v-if="paymentLoading" class="fas fa-spinner fa-spin"></i>
                <span>{{ paymentLoading ? 'Sending…' : 'Pay KES ' + formatNumber(paymentAmount) }}</span>
              </button>
            </template>
            <template v-else-if="paymentStep === 'processing'">
              <div class="flex flex-col items-center py-3 text-center">
                <div :class="['w-16 h-16 rounded-2xl flex items-center justify-center mb-4', isDark ? 'bg-amber-500/15' : 'bg-amber-100']"><i :class="['fas fa-mobile-screen-button text-2xl animate-bounce', isDark ? 'text-amber-400' : 'text-amber-600']"></i></div>
                <p :class="['font-bold mb-1', isDark ? 'text-white' : 'text-gray-800']">Check your phone</p>
                <p :class="['text-sm mb-4', isDark ? 'text-white/50' : 'text-gray-500']">STK sent to <strong :class="isDark ? 'text-white/80' : 'text-gray-700'">{{ maskedPhone }}</strong> — enter PIN to confirm.</p>
                <div :class="['w-full rounded-full h-1.5 mb-3 overflow-hidden', isDark ? 'bg-white/5' : 'bg-gray-100']"><div class="bg-amber-500 h-1.5 rounded-full animate-pulse" style="width:65%"></div></div>
                <p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">{{ paymentStatusMessage }}</p>
                <div class="mt-4 grid grid-cols-2 gap-3 w-full">
                  <div :class="['rounded-xl p-3', isDark ? 'bg-white/5' : 'bg-gray-50']"><p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">Amount</p><p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">KES {{ formatNumber(paymentAmount) }}</p></div>
                  <div :class="['rounded-xl p-3', isDark ? 'bg-white/5' : 'bg-gray-50']"><p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">Check</p><p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">{{ paymentPollAttempt }} / 18</p></div>
                </div>
              </div>
            </template>
            <template v-else-if="paymentStep === 'success'">
              <div class="flex flex-col items-center py-3 text-center">
                <div :class="['w-16 h-16 rounded-2xl flex items-center justify-center mb-4', isDark ? 'bg-emerald-500/15' : 'bg-emerald-100']"><i :class="['fas fa-circle-check text-3xl', isDark ? 'text-emerald-400' : 'text-emerald-600']"></i></div>
                <p :class="['font-bold mb-1', isDark ? 'text-white' : 'text-gray-800']">Payment Confirmed!</p>
                <p :class="['text-sm mb-4', isDark ? 'text-white/50' : 'text-gray-500']">Account renewed successfully.</p>
                <div :class="['w-full rounded-xl p-4 text-left space-y-2 text-sm border', isDark ? 'bg-white/5 border-emerald-500/20' : 'bg-emerald-50 border-emerald-200']">
                  <div class="flex justify-between"><span :class="isDark ? 'text-white/40' : 'text-gray-500'">Paid</span><span :class="['font-semibold', isDark ? 'text-white' : 'text-gray-800']">KES {{ formatNumber(paymentAmount) }}</span></div>
                  <div v-if="paymentConfirmation.receipt" class="flex justify-between"><span :class="isDark ? 'text-white/40' : 'text-gray-500'">Receipt</span><span :class="['font-mono text-xs', isDark ? 'text-white/80' : 'text-gray-700']">{{ paymentConfirmation.receipt }}</span></div>
                  <div v-if="paymentConfirmation.nextDue" class="flex justify-between"><span :class="isDark ? 'text-white/40' : 'text-gray-500'">Next Due</span><span class="font-semibold text-emerald-500">{{ formatDate(paymentConfirmation.nextDue) }}</span></div>
                </div>
              </div>
              <button @click="closePaymentModal" class="w-full py-3.5 bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl transition-colors">Done</button>
            </template>
            <template v-else-if="paymentStep === 'failed'">
              <div class="flex flex-col items-center py-3 text-center">
                <div :class="['w-16 h-16 rounded-2xl flex items-center justify-center mb-4', isDark ? 'bg-red-500/15' : 'bg-red-100']"><i :class="['fas fa-circle-xmark text-3xl', isDark ? 'text-red-400' : 'text-red-500']"></i></div>
                <p :class="['font-bold mb-1', isDark ? 'text-white' : 'text-gray-800']">Payment Not Completed</p>
                <p :class="['text-sm mb-4', isDark ? 'text-white/50' : 'text-gray-500']">{{ paymentErrorMessage || 'Cancelled or timed out.' }}</p>
              </div>
              <button @click="paymentStep = 'form'; paymentErrorMessage = ''" class="w-full py-3.5 bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl transition-colors">Try Again</button>
            </template>
            <button @click="closePaymentModal" :disabled="paymentStep === 'processing'" :class="['w-full py-3 rounded-xl font-semibold text-sm border transition-colors disabled:opacity-20', isDark ? 'border-white/10 text-white/40 hover:text-white/70 hover:border-white/20' : 'border-gray-200 text-gray-400 hover:text-gray-600 hover:border-gray-300']">
              {{ paymentStep === 'success' ? 'Done' : 'Close' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── Reusable drawer bg classes via inline binding ── -->

    <!-- Payments Slideout -->
    <Teleport to="body">
      <div v-if="showPaymentsOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/60" @click="showPaymentsOverlay = false"></div>
        <aside :class="['absolute right-0 top-0 h-full w-full lg:w-1/2 flex flex-col border-l shadow-2xl transition-colors', isDark ? 'bg-gray-900 border-white/10 text-white' : 'bg-white border-gray-200 text-gray-900']">
          <div :class="['flex items-center justify-between px-5 py-4 border-b flex-shrink-0', isDark ? 'border-white/10' : 'border-gray-100']">
            <div><p :class="['font-bold', isDark ? 'text-white' : 'text-gray-800']">Payments</p><p :class="['text-xs mt-0.5', isDark ? 'text-white/40' : 'text-gray-400']">Pay &amp; view history</p></div>
            <button @click="showPaymentsOverlay = false" :class="['w-8 h-8 flex items-center justify-center rounded-lg transition-colors', isDark ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700']"><i class="fas fa-xmark"></i></button>
          </div>
          <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <div :class="['rounded-xl p-4 border border-indigo-500/25', isDark ? 'bg-white/5' : 'bg-indigo-50']">
              <p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">Current Plan</p>
              <p :class="['font-bold text-base mt-0.5', isDark ? 'text-white' : 'text-gray-800']">{{ dashboardData?.user?.package?.name || 'No Plan' }}</p>
              <div class="mt-3 grid grid-cols-2 gap-3">
                <div><p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">Plan Amount</p><p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">KES {{ formatNumber(planAmount) }}</p></div>
                <div><p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">Amount Due</p><p :class="['font-bold text-sm', isDark ? 'text-amber-400' : 'text-amber-600']">KES {{ formatNumber(paymentAmount) }}</p></div>
              </div>
              <button @click="openPaymentModal" class="mt-4 w-full py-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl text-sm transition-colors"><i class="fas fa-mobile-screen-button mr-2"></i>Pay KES {{ formatNumber(paymentAmount) }}</button>
            </div>
            <div>
              <p :class="['text-xs font-semibold uppercase tracking-wider mb-3', isDark ? 'text-white/40' : 'text-gray-400']">Payment History</p>
              <!-- Duplicate Payment Warning -->
              <div v-if="hasRecentCompletedPayment" :class="['mb-3 rounded-lg px-3 py-2 text-xs border', isDark ? 'bg-amber-500/10 border-amber-500/20 text-amber-400' : 'bg-amber-50 border-amber-200 text-amber-700']">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                You have a recent completed payment. Duplicate payments may extend your expiry date but won't be refunded automatically.
              </div>
              <div class="space-y-2">
                <div v-for="payment in dashboardData?.recent_payments || []" :key="payment.id">
                  <!-- Payment Row (Clickable) -->
                  <div @click="togglePaymentDetails(payment.id)" :class="['rounded-xl p-3 flex items-center justify-between gap-3 border cursor-pointer transition-colors', isDark ? 'bg-white/5 border-white/8 hover:bg-white/8' : 'bg-gray-50 border-gray-100 hover:bg-gray-100', expandedPaymentId === payment.id ? (isDark ? 'bg-white/8' : 'bg-gray-100') : '']">
                    <div class="min-w-0 flex-1">
                      <p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">KES {{ formatNumber(payment.amount || 0) }}</p>
                      <p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">{{ formatDate(payment.created_at) }} · {{ formatPaymentMethod(payment.payment_method) }}</p>
                      <p v-if="payment.payment_reference" :class="['text-xs font-mono truncate', isDark ? 'text-white/30' : 'text-gray-300']">{{ payment.payment_reference }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                      <span :class="['flex-shrink-0 text-[10px] px-2 py-1 rounded-full font-bold', payment.status==='completed' ? (isDark ? 'bg-emerald-500/15 text-emerald-400' : 'bg-emerald-100 text-emerald-700') : payment.status==='pending' ? (isDark ? 'bg-amber-500/15 text-amber-400' : 'bg-amber-100 text-amber-700') : (isDark ? 'bg-red-500/15 text-red-400' : 'bg-red-100 text-red-600')]">{{ payment.status || 'unknown' }}</span>
                      <i :class="['fas text-xs transition-transform', expandedPaymentId === payment.id ? 'fa-chevron-up' : 'fa-chevron-down', isDark ? 'text-white/40' : 'text-gray-400']"></i>
                    </div>
                  </div>
                  <!-- Expanded Details -->
                  <div v-if="expandedPaymentId === payment.id" :class="['mx-2 px-3 py-3 rounded-b-xl border-t-0 border text-xs space-y-2', isDark ? 'bg-white/3 border-white/5 text-white/70' : 'bg-gray-50/50 border-gray-100 text-gray-600']">
                    <div class="grid grid-cols-2 gap-2">
                      <div>
                        <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Transaction ID</p>
                        <p :class="['font-mono', isDark ? 'text-white/80' : 'text-gray-700']">{{ payment.transaction_id || '--' }}</p>
                      </div>
                      <div>
                        <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Receipt Number</p>
                        <p :class="['font-mono', isDark ? 'text-white/80' : 'text-gray-700']">{{ payment.payment_reference || '--' }}</p>
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                      <div>
                        <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Phone Number</p>
                        <p :class="[isDark ? 'text-white/80' : 'text-gray-700']">{{ formatPhoneNumber(payment.metadata?.mpesa_phone_number || payment.phone_number) }}</p>
                      </div>
                      <div>
                        <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Payment Method</p>
                        <p :class="[isDark ? 'text-white/80' : 'text-gray-700']">{{ formatPaymentMethod(payment.payment_method) }}</p>
                      </div>
                    </div>
                    <div v-if="payment.period_start || payment.period_end" class="grid grid-cols-2 gap-2 pt-1 border-t" :class="isDark ? 'border-white/5' : 'border-gray-200'">
                      <div>
                        <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Period Start</p>
                        <p :class="[isDark ? 'text-white/80' : 'text-gray-700']">{{ payment.period_start ? formatDate(payment.period_start) : '--' }}</p>
                      </div>
                      <div>
                        <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Period End</p>
                        <p :class="[isDark ? 'text-white/80' : 'text-gray-700']">{{ payment.period_end ? formatDate(payment.period_end) : '--' }}</p>
                      </div>
                    </div>
                    <div v-if="payment.notes" class="pt-1 border-t" :class="isDark ? 'border-white/5' : 'border-gray-200'">
                      <p :class="['text-[10px] uppercase', isDark ? 'text-white/40' : 'text-gray-400']">Notes</p>
                      <p :class="[isDark ? 'text-white/80' : 'text-gray-700']">{{ payment.notes }}</p>
                    </div>
                  </div>
                </div>
                <div v-if="!dashboardData?.recent_payments?.length" class="py-10 text-center">
                  <i :class="['fas fa-receipt text-3xl mb-3', isDark ? 'text-white/20' : 'text-gray-200']"></i>
                  <p :class="['text-sm', isDark ? 'text-white/30' : 'text-gray-400']">No payment history</p>
                </div>
              </div>
            </div>
          </div>
          <div :class="['p-4 flex-shrink-0 border-t', isDark ? 'border-white/10' : 'border-gray-100']">
            <button @click="showPaymentsOverlay = false" :class="['w-full py-2.5 rounded-xl font-semibold text-sm border transition-colors', isDark ? 'border-white/10 text-white/50 hover:bg-white/5' : 'border-gray-200 text-gray-500 hover:bg-gray-50']">Close</button>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Usage History Slideout -->
    <Teleport to="body">
      <div v-if="showHistoryOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/60" @click="showHistoryOverlay = false"></div>
        <aside :class="['absolute right-0 top-0 h-full w-full lg:w-1/2 flex flex-col border-l shadow-2xl', isDark ? 'bg-gray-900 border-white/10 text-white' : 'bg-white border-gray-200 text-gray-900']">
          <div :class="['flex items-center justify-between px-5 py-4 border-b flex-shrink-0', isDark ? 'border-white/10' : 'border-gray-100']">
            <p :class="['font-bold', isDark ? 'text-white' : 'text-gray-800']">Usage History</p>
            <div class="flex items-center gap-3">
              <select v-model="historyDays" @change="fetchOverlayHistory" :class="['text-xs px-3 py-1.5 rounded-lg border outline-none', isDark ? 'bg-white/8 border-white/15 text-white' : 'bg-gray-50 border-gray-200 text-gray-700']">
                <option :value="7">7 days</option><option :value="30">30 days</option><option :value="60">60 days</option><option :value="90">90 days</option>
              </select>
              <button @click="showHistoryOverlay = false" :class="['w-8 h-8 flex items-center justify-center rounded-lg transition-colors', isDark ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-gray-100']"><i class="fas fa-xmark"></i></button>
            </div>
          </div>
          <div class="flex-1 overflow-y-auto p-4">
            <div v-if="historyLoading" class="flex h-48 items-center justify-center"><div class="w-8 h-8 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div></div>
            <template v-else>
              <div class="grid grid-cols-3 gap-3 mb-4">
                <div v-for="(s, i) in [{l:'Sessions',v:historyData?.total_sessions||0},{l:'Total Time',v:overlayTotalDuration},{l:'Total Data',v:overlayTotalData}]" :key="i" :class="['rounded-xl p-3 border', isDark ? 'bg-white/5 border-white/8' : 'bg-gray-50 border-gray-100']">
                  <p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">{{ s.l }}</p>
                  <p :class="['font-bold', isDark ? 'text-white' : 'text-gray-800']">{{ s.v }}</p>
                </div>
              </div>
              <div :class="['overflow-x-auto rounded-xl border', isDark ? 'border-white/8' : 'border-gray-100']">
                <table class="w-full text-sm">
                  <thead :class="['text-[10px] uppercase tracking-wider', isDark ? 'bg-white/5 text-white/30' : 'bg-gray-50 text-gray-400']">
                    <tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Duration</th><th class="px-4 py-3 text-left">Down</th><th class="px-4 py-3 text-left">Up</th><th class="px-4 py-3 text-left">Total</th><th class="px-4 py-3 text-left">IP</th><th class="px-4 py-3 text-left">Status</th></tr>
                  </thead>
                  <tbody :class="isDark ? 'text-white/60' : 'text-gray-600'">
                    <tr v-for="session in historyData?.sessions || []" :key="session.id" :class="['border-t', isDark ? 'border-white/5' : 'border-gray-50']">
                      <td class="px-4 py-3"><p :class="['text-xs font-medium', isDark ? 'text-white' : 'text-gray-800']">{{ formatDate(session.start_time) }}</p><p :class="['text-[10px]', isDark ? 'text-white/30' : 'text-gray-400']">{{ formatDateTime(session.start_time) }}</p></td>
                      <td class="px-4 py-3 text-xs">{{ session.duration_formatted }}</td>
                      <td :class="['px-4 py-3 text-xs', isDark ? 'text-emerald-400' : 'text-emerald-600']">{{ session.download_formatted }}</td>
                      <td :class="['px-4 py-3 text-xs', isDark ? 'text-blue-400' : 'text-blue-600']">{{ session.upload_formatted }}</td>
                      <td :class="['px-4 py-3 text-xs font-semibold', isDark ? 'text-white' : 'text-gray-800']">{{ session.total_formatted }}</td>
                      <td :class="['px-4 py-3 font-mono text-xs', isDark ? 'text-white/30' : 'text-gray-400']">{{ session.ip_address || '--' }}</td>
                      <td class="px-4 py-3"><span :class="['text-[10px] px-2 py-0.5 rounded-full font-bold', session.status==='active' ? (isDark ? 'bg-emerald-500/15 text-emerald-400' : 'bg-emerald-100 text-emerald-700') : (isDark ? 'bg-white/8 text-white/40' : 'bg-gray-100 text-gray-500')]">{{ session.status==='active' ? 'Active' : 'Done' }}</span></td>
                    </tr>
                    <tr v-if="!historyData?.sessions?.length"><td colspan="7" :class="['px-4 py-10 text-center text-sm', isDark ? 'text-white/30' : 'text-gray-400']">No history found</td></tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>
          <div :class="['p-4 flex-shrink-0 border-t', isDark ? 'border-white/10' : 'border-gray-100']">
            <button @click="showHistoryOverlay = false" :class="['w-full py-2.5 rounded-xl font-semibold text-sm border transition-colors', isDark ? 'border-white/10 text-white/50 hover:bg-white/5' : 'border-gray-200 text-gray-500 hover:bg-gray-50']">Close</button>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Timed Voucher Overlay -->
    <Teleport to="body">
      <div v-if="showTimedVoucherOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/60" @click="showTimedVoucherOverlay = false"></div>
        <aside :class="['absolute right-0 top-0 h-full w-full lg:w-1/2 flex flex-col border-l shadow-2xl', isDark ? 'bg-gray-900 border-white/10 text-white' : 'bg-white border-gray-200 text-gray-900']">
          <div :class="['flex items-center justify-between px-5 py-4 border-b flex-shrink-0', isDark ? 'border-white/10' : 'border-gray-100']">
            <div>
              <p :class="['font-bold flex items-center gap-2', isDark ? 'text-white' : 'text-gray-800']"><i class="fas fa-hourglass-half text-purple-500"></i>Timed Voucher</p>
              <p :class="['text-xs mt-0.5', isDark ? 'text-white/40' : 'text-gray-400']">Temporary M-Pesa internet access</p>
            </div>
            <button @click="showTimedVoucherOverlay = false" :class="['w-8 h-8 flex items-center justify-center rounded-lg transition-colors', isDark ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-gray-100']"><i class="fas fa-xmark"></i></button>
          </div>
          <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <div v-if="activeTimedVoucher" :class="['rounded-xl p-3 border border-purple-500/30', isDark ? 'bg-purple-500/10' : 'bg-purple-50']">
              <p :class="['font-semibold text-sm flex items-center gap-2', isDark ? 'text-purple-400' : 'text-purple-700']"><i class="fas fa-circle-check"></i>Active Voucher</p>
              <p :class="['text-xs mt-1', isDark ? 'text-white/40' : 'text-gray-500']">{{ activeTimedVoucher.duration_label }} — Exp: {{ formatDateTime(activeTimedVoucher.expires_at) }}</p>
            </div>
            <div>
              <p :class="['text-xs font-semibold uppercase tracking-wider mb-2', isDark ? 'text-white/40' : 'text-gray-400']">1 — Choose Package</p>
              <div v-if="tvPackagesLoading" class="flex justify-center py-4"><div class="w-6 h-6 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div></div>
              <div v-else class="space-y-2">
                <div v-for="pkg in tvPackages" :key="pkg.id" @click="selectedTvPackage = pkg; selectedTvDuration = null"
                  :class="['flex items-center justify-between rounded-xl p-3 cursor-pointer transition-all border', selectedTvPackage?.id === pkg.id ? 'border-purple-500/60 bg-purple-500/10' : (isDark ? 'bg-white/5 border-white/8 hover:border-purple-500/30' : 'bg-gray-50 border-gray-100 hover:border-purple-300')]">
                  <div><p :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-800']">{{ pkg.name }}</p><p :class="['text-xs', isDark ? 'text-white/40' : 'text-gray-400']">{{ pkg.download_speed }}↓ / {{ pkg.upload_speed }}↑</p></div>
                  <p :class="['text-xs font-semibold', isDark ? 'text-purple-400' : 'text-purple-700']">KES {{ formatNumber(pkg.monthly_price) }}/mo</p>
                </div>
                <p v-if="!tvPackages.length" :class="['text-center py-4 text-sm', isDark ? 'text-white/30' : 'text-gray-400']">No packages available.</p>
              </div>
            </div>
            <div v-if="selectedTvPackage">
              <p :class="['text-xs font-semibold uppercase tracking-wider mb-2', isDark ? 'text-white/40' : 'text-gray-400']">2 — Choose Duration</p>
              <div class="grid grid-cols-2 gap-2">
                <div v-for="dur in selectedTvPackage.durations" :key="dur.hours" @click="selectedTvDuration = dur"
                  :class="['rounded-xl p-3 cursor-pointer transition-all border', selectedTvDuration?.hours === dur.hours ? 'border-purple-500/60 bg-purple-500/10' : (isDark ? 'bg-white/5 border-white/8 hover:border-purple-500/30' : 'bg-gray-50 border-gray-100 hover:border-purple-300')]">
                  <p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">{{ dur.label }}</p>
                  <p :class="['font-bold text-sm mt-0.5', isDark ? 'text-purple-400' : 'text-purple-700']">KES {{ formatNumber(dur.price) }}</p>
                </div>
              </div>
            </div>
            <div v-if="selectedTvPackage && selectedTvDuration">
              <p :class="['text-xs font-semibold uppercase tracking-wider mb-2', isDark ? 'text-white/40' : 'text-gray-400']">3 — Confirm &amp; Pay</p>
              <div :class="['rounded-xl p-4 mb-3 space-y-2 text-sm border border-purple-500/20', isDark ? 'bg-white/5' : 'bg-purple-50']">
                <div class="flex justify-between"><span :class="isDark ? 'text-white/40' : 'text-gray-500'">Package</span><span :class="['font-semibold', isDark ? 'text-white' : 'text-gray-800']">{{ selectedTvPackage.name }}</span></div>
                <div class="flex justify-between"><span :class="isDark ? 'text-white/40' : 'text-gray-500'">Duration</span><span :class="['font-semibold', isDark ? 'text-white' : 'text-gray-800']">{{ selectedTvDuration.label }}</span></div>
                <div :class="['flex justify-between border-t pt-2', isDark ? 'border-white/10' : 'border-purple-200']"><span :class="isDark ? 'text-white/40' : 'text-gray-500'">Total</span><span :class="['font-bold text-base', isDark ? 'text-purple-400' : 'text-purple-700']">KES {{ formatNumber(selectedTvDuration.price) }}</span></div>
              </div>
              <label :class="['block text-xs font-semibold uppercase tracking-wider mb-2', isDark ? 'text-white/50' : 'text-gray-500']">M-Pesa Number</label>
              <div class="relative mb-3">
                <span :class="['absolute left-3.5 top-1/2 -translate-y-1/2 font-medium text-sm', isDark ? 'text-white/40' : 'text-gray-400']">254</span>
                <input v-model="timedVoucherPhone" type="tel" placeholder="7XX XXX XXX" maxlength="9" :class="['w-full pl-12 pr-4 py-3 rounded-xl text-sm border outline-none transition-colors', isDark ? 'bg-white/8 border-white/15 text-white placeholder-white/30 focus:border-purple-500/60' : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400 focus:border-purple-400']" />
              </div>
              <div v-if="timedVoucherMessage" :class="['text-sm rounded-xl px-4 py-3 mb-3', timedVoucherSuccess ? (isDark ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-emerald-50 text-emerald-700 border border-emerald-200') : (isDark ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-red-50 text-red-600 border border-red-200')]">{{ timedVoucherMessage }}</div>
              <button @click="handleBuyTimedVoucher" :disabled="timedVoucherLoading || !timedVoucherPhone" class="w-full py-3.5 bg-purple-500 hover:bg-purple-600 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2 disabled:opacity-40">
                <i v-if="timedVoucherLoading" class="fas fa-spinner fa-spin"></i>
                <span>{{ timedVoucherLoading ? 'Sending…' : 'Activate — KES ' + formatNumber(selectedTvDuration.price) }}</span>
              </button>
            </div>
          </div>
          <div :class="['p-4 flex-shrink-0 border-t', isDark ? 'border-white/10' : 'border-gray-100']">
            <button @click="showTimedVoucherOverlay = false" :class="['w-full py-2.5 rounded-xl font-semibold text-sm border transition-colors', isDark ? 'border-white/10 text-white/50 hover:bg-white/5' : 'border-gray-200 text-gray-500 hover:bg-gray-50']">Close</button>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Pause/Resume Overlay -->
    <Teleport to="body">
      <div v-if="showPauseOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/60" @click="showPauseOverlay = false"></div>
        <aside :class="['absolute right-0 top-0 h-full w-full lg:w-1/2 flex flex-col border-l shadow-2xl', isDark ? 'bg-gray-900 border-white/10 text-white' : 'bg-white border-gray-200 text-gray-900']">
          <div :class="['flex items-center justify-between px-5 py-4 border-b flex-shrink-0', isDark ? 'border-white/10' : 'border-gray-100']">
            <p :class="['font-bold flex items-center gap-2', isDark ? 'text-white' : 'text-gray-800']">
              <i :class="['fas', dashboardData?.user?.is_paused ? (isDark ? 'fa-circle-play text-amber-400' : 'fa-circle-play text-amber-500') : (isDark ? 'fa-circle-pause text-teal-400' : 'fa-circle-pause text-teal-500')]"></i>
              {{ dashboardData?.user?.is_paused ? 'Resume Account' : 'Pause Account' }}
            </p>
            <button @click="showPauseOverlay = false" :class="['w-8 h-8 flex items-center justify-center rounded-lg transition-colors', isDark ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-gray-100']"><i class="fas fa-xmark"></i></button>
          </div>
          <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <template v-if="dashboardData?.user?.is_paused">
              <div :class="['rounded-xl p-4 border border-amber-500/25', isDark ? 'bg-amber-500/10' : 'bg-amber-50']">
                <p :class="['font-semibold text-sm flex items-center gap-2', isDark ? 'text-amber-400' : 'text-amber-700']"><i class="fas fa-circle-pause"></i>Account is Paused</p>
                <p :class="['text-xs mt-2', isDark ? 'text-white/50' : 'text-gray-500']">Pause expires <strong :class="isDark ? 'text-white' : 'text-gray-700'">{{ formatDate(dashboardData.user.pause_ends_at) }}</strong>.</p>
                <p :class="['text-xs mt-1', isDark ? 'text-white/50' : 'text-gray-500']">Resuming early credits unused days back.</p>
              </div>
              <button @click="handleResumeAccount" :disabled="pauseLoading" class="w-full py-3.5 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2 disabled:opacity-50">
                <i v-if="pauseLoading" class="fas fa-spinner fa-spin"></i><span>{{ pauseLoading ? 'Resuming…' : 'Resume Now' }}</span>
              </button>
            </template>
            <template v-else>
              <div :class="['rounded-xl p-4 border border-teal-500/25 space-y-1.5', isDark ? 'bg-teal-500/10' : 'bg-teal-50']">
                <p :class="['font-semibold text-sm flex items-center gap-2 mb-2', isDark ? 'text-teal-400' : 'text-teal-700']"><i class="fas fa-circle-info"></i>About Pause</p>
                <p :class="['text-xs', isDark ? 'text-white/50' : 'text-gray-500']">Account must be fully paid before pausing.</p>
                <p :class="['text-xs', isDark ? 'text-white/50' : 'text-gray-500']">Maximum 30 days. Internet suspended during pause.</p>
                <p :class="['text-xs', isDark ? 'text-white/50' : 'text-gray-500']">Resuming early credits unused days back.</p>
              </div>
              <div v-if="dashboardData?.user?.payment_status !== 'paid'" class="rounded-xl bg-red-500/10 border border-red-500/20 p-3 text-xs text-red-500">
                <i class="fas fa-circle-exclamation mr-1"></i>Account must be fully paid to enable pause.
              </div>
              <button @click="handlePauseAccount" :disabled="pauseLoading || dashboardData?.user?.payment_status !== 'paid'" class="w-full py-3.5 bg-teal-500 hover:bg-teal-600 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2 disabled:opacity-40">
                <i v-if="pauseLoading" class="fas fa-spinner fa-spin"></i><span>{{ pauseLoading ? 'Pausing…' : 'Pause My Account' }}</span>
              </button>
            </template>
          </div>
          <div :class="['p-4 flex-shrink-0 border-t', isDark ? 'border-white/10' : 'border-gray-100']">
            <button @click="showPauseOverlay = false" :class="['w-full py-2.5 rounded-xl font-semibold text-sm border transition-colors', isDark ? 'border-white/10 text-white/50 hover:bg-white/5' : 'border-gray-200 text-gray-500 hover:bg-gray-50']">Close</button>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Plan Switch Overlay -->
    <Teleport to="body">
      <div v-if="showPlanSwitchOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/60" @click="showPlanSwitchOverlay = false"></div>
        <aside :class="['absolute right-0 top-0 h-full w-full lg:w-1/2 flex flex-col border-l shadow-2xl', isDark ? 'bg-gray-900 border-white/10 text-white' : 'bg-white border-gray-200 text-gray-900']">
          <div :class="['flex items-center justify-between px-5 py-4 border-b flex-shrink-0', isDark ? 'border-white/10' : 'border-gray-100']">
            <div>
              <p :class="['font-bold flex items-center gap-2', isDark ? 'text-white' : 'text-gray-800']"><i class="fas fa-arrows-rotate text-indigo-500"></i>Switch Plan</p>
              <p :class="['text-xs mt-0.5', isDark ? 'text-white/40' : 'text-gray-400']">Effective at next renewal</p>
            </div>
            <button @click="showPlanSwitchOverlay = false" :class="['w-8 h-8 flex items-center justify-center rounded-lg transition-colors', isDark ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-gray-100']"><i class="fas fa-xmark"></i></button>
          </div>
          <div class="flex-1 overflow-y-auto p-4 space-y-3">
            <div v-if="dashboardData.user?.pending_package_id" :class="['rounded-xl p-3 border border-amber-500/25 text-xs', isDark ? 'bg-amber-500/10 text-amber-400' : 'bg-amber-50 text-amber-700']">
              <i class="fas fa-circle-info mr-1"></i>Switch scheduled for <strong>{{ formatDate(dashboardData.user?.plan_switch_effective_date) }}</strong>.
            </div>
            <div v-if="plansLoading" class="flex justify-center py-8"><div class="w-6 h-6 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div></div>
            <div v-else class="space-y-2">
              <div v-for="plan in availablePlans" :key="plan.id"
                :class="['flex items-center justify-between rounded-xl border p-3 transition-all cursor-pointer',
                  dashboardData.user?.package_id === plan.id ? 'border-indigo-400/50 bg-indigo-500/10 cursor-default' :
                  selectedPlanId === plan.id ? 'border-indigo-500/60 bg-indigo-500/10' :
                  (isDark ? 'bg-white/5 border-white/8 hover:border-indigo-500/30' : 'bg-gray-50 border-gray-100 hover:border-indigo-300')]"
                @click="dashboardData.user?.package_id !== plan.id && (selectedPlanId = plan.id)">
                <div>
                  <div class="flex items-center gap-2">
                    <p :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-800']">{{ plan.name }}</p>
                    <span v-if="dashboardData.user?.package_id === plan.id" :class="['text-[10px] px-1.5 py-0.5 rounded-full font-semibold', isDark ? 'bg-indigo-500/20 text-indigo-400' : 'bg-indigo-100 text-indigo-700']">Current</span>
                  </div>
                  <p :class="['text-xs mt-0.5', isDark ? 'text-white/40' : 'text-gray-400']">{{ plan.download_speed }}↓ / {{ plan.upload_speed }}↑</p>
                </div>
                <p :class="['font-bold text-sm', isDark ? 'text-white' : 'text-gray-800']">KES {{ formatNumber(plan.price) }}<span :class="['text-xs font-normal', isDark ? 'text-white/30' : 'text-gray-400']">/mo</span></p>
              </div>
            </div>
            <p v-if="!plansLoading && !availablePlans.length" :class="['text-center py-8 text-sm', isDark ? 'text-white/30' : 'text-gray-400']">No plans available.</p>
            <button @click="handlePlanSwitch" :disabled="!selectedPlanId || planSwitchLoading" class="w-full py-3.5 bg-indigo-500 hover:bg-indigo-600 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2 disabled:opacity-40">
              <i v-if="planSwitchLoading" class="fas fa-spinner fa-spin"></i>
              <span>{{ planSwitchLoading ? 'Scheduling…' : 'Schedule Plan Switch' }}</span>
            </button>
          </div>
          <div :class="['p-4 flex-shrink-0 border-t', isDark ? 'border-white/10' : 'border-gray-100']">
            <button @click="showPlanSwitchOverlay = false" :class="['w-full py-2.5 rounded-xl font-semibold text-sm border transition-colors', isDark ? 'border-white/10 text-white/50 hover:bg-white/5' : 'border-gray-200 text-gray-500 hover:bg-gray-50']">Close</button>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Voucher Modal -->
    <Teleport to="body">
      <div v-if="showVoucherModal" :class="['fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4', isDark ? 'bg-black/80' : 'bg-black/50']">
        <div :class="['w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl overflow-hidden border shadow-2xl', isDark ? 'bg-gray-900 border-white/10' : 'bg-white border-gray-200']">
          <div :class="['flex items-center justify-between px-5 pt-5 pb-4 border-b', isDark ? 'border-white/10' : 'border-gray-100']">
            <div class="flex items-center gap-2">
              <div :class="['w-7 h-7 rounded-lg flex items-center justify-center', isDark ? 'bg-indigo-500/20' : 'bg-indigo-100']"><i :class="['fas fa-ticket text-sm', isDark ? 'text-indigo-400' : 'text-indigo-600']"></i></div>
              <h3 :class="['font-bold text-base', isDark ? 'text-white' : 'text-gray-800']">Redeem Voucher</h3>
            </div>
            <button @click="closeVoucherModal" :class="['w-8 h-8 flex items-center justify-center rounded-lg transition-colors', isDark ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-gray-100']"><i class="fas fa-xmark text-lg"></i></button>
          </div>
          <form @submit.prevent="handleVoucherRedeem" class="px-5 py-5 space-y-4">
            <div v-if="voucherStatusMessage" :class="['rounded-xl border px-4 py-3 text-sm flex items-start gap-2', isDark ? 'bg-blue-500/10 border-blue-500/20 text-blue-400' : 'bg-blue-50 border-blue-200 text-blue-700']">
              <i class="fas fa-circle-info mt-0.5 flex-shrink-0"></i><p>{{ voucherStatusMessage }}</p>
            </div>
            <div v-if="voucherErrorMessage" :class="['rounded-xl border px-4 py-3 text-sm flex items-start gap-2', isDark ? 'bg-red-500/10 border-red-500/20 text-red-400' : 'bg-red-50 border-red-200 text-red-600']">
              <i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><p>{{ voucherErrorMessage }}</p>
            </div>
            <div>
              <label :class="['block text-xs font-semibold uppercase tracking-wider mb-2', isDark ? 'text-white/50' : 'text-gray-500']">Voucher Code</label>
              <input v-model="voucherForm.code" type="text" placeholder="Enter code" required :class="['w-full px-4 py-3 rounded-xl text-sm border outline-none transition-colors uppercase tracking-widest', isDark ? 'bg-white/8 border-white/15 text-white placeholder-white/30 focus:border-indigo-500/60' : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400 focus:border-indigo-400']" />
            </div>
            <button type="submit" :disabled="voucherLoading" class="w-full py-3.5 bg-indigo-500 hover:bg-indigo-600 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2 disabled:opacity-50">
              <i v-if="voucherLoading" class="fas fa-spinner fa-spin"></i>
              <span>{{ voucherLoading ? 'Redeeming…' : 'Redeem Voucher' }}</span>
            </button>
            <button type="button" @click="closeVoucherModal" :class="['w-full py-3 rounded-xl font-semibold text-sm border transition-colors', isDark ? 'border-white/10 text-white/40 hover:bg-white/5 hover:text-white/70' : 'border-gray-200 text-gray-400 hover:bg-gray-50 hover:text-gray-600']">Close</button>
          </form>
        </div>
      </div>
    </Teleport>

    <!-- Success Toast -->
    <Teleport to="body">
      <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-2 opacity-0">
        <div v-if="successMessage" class="fixed bottom-6 right-4 z-[60] sm:bottom-4">
          <div class="bg-emerald-500 text-white px-5 py-3 rounded-xl shadow-2xl shadow-emerald-500/30 flex items-center gap-3 text-sm font-semibold">
            <i class="fas fa-circle-check"></i><span>{{ successMessage }}</span>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePppoePortal } from '../composables/usePppoePortal.js';

const { 
  user, 
  isLoading, 
  logout, 
  fetchDashboard, 
  fetchSessionHistory,
  initiateMpesaPayment,
  redeemVoucher,
  checkPaymentStatus,
  getDashboardSeed,
  pauseAccount,
  resumeAccount,
  fetchPlans,
  requestPlanSwitch,
  fetchTimedVoucherOptions,
  buyTimedVoucher,
} = usePppoePortal();

const isDark = ref(false);

const dashboardData = ref(null);
const recentSessions = ref([]);
const showPaymentModal = ref(false);
const showVoucherModal = ref(false);
const showPaymentsOverlay = ref(false);
const showHistoryOverlay = ref(false);
const expandedPaymentId = ref(null);
const paymentLoading = ref(false);
const voucherLoading = ref(false);
const historyLoading = ref(false);
const successMessage = ref('');
const loadError = ref('');
const paymentStatusMessage = ref('');
const paymentErrorMessage = ref('');
const voucherStatusMessage = ref('');
const voucherErrorMessage = ref('');
const pendingPaymentTransactionId = ref('');
const historyDays = ref(30);
const historyData = ref(null);

// Pause/Resume
const pauseLoading = ref(false);

// Plan Switch
const availablePlans = ref([]);
const plansLoading = ref(false);
const selectedPlanId = ref(null);
const planSwitchLoading = ref(false);

// Overlay toggles
const showTimedVoucherOverlay = ref(false);
const showPauseOverlay = ref(false);
const showPlanSwitchOverlay = ref(false);
const showRecentSessionsOverlay = ref(false);

// Timed Vouchers
const tvPackages = ref([]);
const tvPackagesLoading = ref(false);
const selectedTvPackage = ref(null);
const selectedTvDuration = ref(null);
const timedVoucherOptions = ref([]);
const activeTimedVoucher = ref(null);
const selectedVoucherIdx = ref(null);
const timedVoucherPhone = ref('');
const timedVoucherLoading = ref(false);
const timedVoucherMessage = ref('');
const timedVoucherSuccess = ref(false);

// Recent Sessions overlay data
const allRecentSessions = ref([]);

const paymentStep = ref('form'); // 'form' | 'processing' | 'success' | 'failed'
const paymentPollAttempt = ref(0);
const maskedPhone = ref('');
const paymentConfirmation = ref({ receipt: '', paidAt: null, nextDue: null, expiresAt: null, status: '' });
const paymentStatusPollTimer = ref(null);
const paymentEventChannelName = ref('');
const paymentEventReceived = ref(false);

const paymentForm = ref({
  phone: '',
});

const voucherForm = ref({
  code: '',
});

// Button handlers - defined early to ensure availability
const handleLogout = () => {
  console.log('Logout clicked');
  logout();
};

const closePaymentModal = () => {
  if (paymentStep.value === 'processing') return;
  leavePaymentStatusChannel();
  clearPaymentStatusPollTimer();
  showPaymentModal.value = false;
  paymentStep.value = 'form';
  paymentErrorMessage.value = '';
  paymentStatusMessage.value = '';
  paymentPollAttempt.value = 0;
  pendingPaymentTransactionId.value = '';
  maskedPhone.value = '';
  paymentEventReceived.value = false;
  paymentConfirmation.value = { receipt: '', paidAt: null, nextDue: null, expiresAt: null, status: '' };
};

const closeVoucherModal = () => {
  showVoucherModal.value = false;
  voucherErrorMessage.value = '';
  voucherStatusMessage.value = '';
};

const emptyDashboard = () => ({
  user: {
    balance: 0,
    status: 'unknown',
    payment_status: 'unknown',
    package: null,
    expiration_date: null,
    next_payment_due: null,
    amount_due: 0,
    amount_paid: 0,
  },
  current_session: null,
  usage_stats: {
    total_usage_formatted: '0 B',
    total_sessions: 0,
  },
  recent_payments: [],
});

const accountStatus = computed(() => {
  const status = dashboardData.value?.user?.status;
  switch (status) {
    case 'active':
      return {
        heroClass: 'hero-active',
        icon: 'fa-circle-check',
        title: 'Account Active',
        message: 'Your internet service is active and running',
      };
    case 'paused':
      return {
        heroClass: 'hero-paused',
        icon: 'fa-circle-pause',
        title: 'Account Paused',
        message: 'Your account is paused. Internet access is suspended.',
      };
    case 'suspended':
      return {
        heroClass: 'hero-suspended',
        icon: 'fa-triangle-exclamation',
        title: 'Account Suspended',
        message: 'Please make a payment to restore service',
      };
    case 'expired':
      return {
        heroClass: 'hero-expired',
        icon: 'fa-clock',
        title: 'Account Expired',
        message: 'Your package has expired. Please renew',
      };
    default:
      return {
        heroClass: 'hero-unknown',
        icon: 'fa-circle-question',
        title: 'Unknown Status',
        message: 'Please contact support',
      };
  }
});

const paymentStatusLabel = computed(() => {
  const value = String(dashboardData.value?.user?.payment_status || 'unknown').toLowerCase();
  const labels = {
    paid: 'Paid',
    unpaid: 'Unpaid',
    pending: 'Pending',
    overdue: 'Overdue',
    unknown: 'Unknown',
  };
  return labels[value] || value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
});

const paymentStatusClass = computed(() => {
  const value = String(dashboardData.value?.user?.payment_status || '').toLowerCase();
  if (value === 'paid') return 'bg-green-100 text-green-700';
  if (value === 'pending') return 'bg-yellow-100 text-yellow-700';
  if (value === 'unpaid' || value === 'overdue') return 'bg-red-100 text-red-700';
  return 'bg-gray-100 text-gray-600';
});

const planAmount = computed(() => {
  const value = dashboardData.value?.user?.package?.price
    ?? dashboardData.value?.user?.amount_due
    ?? 0;
  return Number(value) || 0;
});

const paymentAmount = computed(() => {
  const due = Number(dashboardData.value?.user?.amount_due ?? 0);
  if (due > 0) return due;
  return planAmount.value;
});

const overlayTotalDuration = computed(() => {
  const sessions = historyData.value?.sessions || [];
  const total = sessions.reduce((sum, session) => sum + (Number(session.duration_seconds) || 0), 0);
  return formatDuration(total);
});

const overlayTotalData = computed(() => {
  const sessions = historyData.value?.sessions || [];
  const total = sessions.reduce((sum, session) => (
    sum + (Number(session.download_bytes) || 0) + (Number(session.upload_bytes) || 0)
  ), 0);
  return formatBytes(total);
});

function normalizeDashboard(data) {
  return data && typeof data === 'object'
    ? {
        ...emptyDashboard(),
        ...data,
        user: { ...emptyDashboard().user, ...(data.user || {}) },
        usage_stats: { ...emptyDashboard().usage_stats, ...(data.usage_stats || {}) },
        recent_payments: Array.isArray(data.recent_payments) ? data.recent_payments : [],
      }
    : emptyDashboard();
}

async function loadDashboard(options = {}) {
  const previous = dashboardData.value;
  try {
    loadError.value = '';
    const data = await fetchDashboard({ force: options.force === true });
    dashboardData.value = normalizeDashboard(data);

    // Non-fatal and non-blocking: slow RADIUS/router history must not delay login/dashboard render.
    fetchSessionHistory(7)
      .then((history) => {
        recentSessions.value = Array.isArray(history?.sessions) ? history.sessions.slice(0, 5) : [];
      })
      .catch((historyErr) => {
        console.warn('Failed to load session history:', historyErr);
        recentSessions.value = [];
      });
  } catch (err) {
    console.error('Failed to load dashboard:', err);
    dashboardData.value = previous || emptyDashboard();
    loadError.value = err?.response?.data?.message || 'Please log in again and retry.';
  }
}

function openPaymentModal() {
  showPaymentsOverlay.value = false;
  showPaymentModal.value = true;
}

function openPaymentsOverlay() {
  showPaymentsOverlay.value = true;
}

function togglePaymentDetails(paymentId) {
  expandedPaymentId.value = expandedPaymentId.value === paymentId ? null : paymentId;
}

function formatPhoneNumber(phone) {
  if (!phone) return '--';
  const str = String(phone);
  if (str.startsWith('254') && str.length === 12) {
    return '+254 ' + str.slice(3, 6) + ' ' + str.slice(6, 9) + ' ' + str.slice(9);
  }
  if (str.startsWith('0') && str.length === 10) {
    return '+254 ' + str.slice(1, 4) + ' ' + str.slice(4, 7) + ' ' + str.slice(7);
  }
  return str;
}

const hasRecentCompletedPayment = computed(() => {
  const payments = dashboardData.value?.recent_payments || [];
  const now = new Date();
  const fiveMinutesAgo = new Date(now.getTime() - 5 * 60 * 1000);
  return payments.some(p =>
    p.status === 'completed' &&
    new Date(p.created_at) > fiveMinutesAgo
  );
});

function openTimedVoucherOverlay() {
  timedVoucherMessage.value = '';
  timedVoucherSuccess.value = false;
  timedVoucherPhone.value = '';
  selectedTvPackage.value = null;
  selectedTvDuration.value = null;
  showTimedVoucherOverlay.value = true;
  if (!tvPackages.value.length) loadTimedVoucherOptions();
}

function openPauseOverlay() {
  showPauseOverlay.value = true;
}

function openPlanSwitchOverlay() {
  selectedPlanId.value = null;
  showPlanSwitchOverlay.value = true;
  if (!availablePlans.value.length) loadPlans();
}

function openRecentSessionsOverlay() {
  allRecentSessions.value = dashboardData.value?.recent_sessions || recentSessions.value || [];
  showRecentSessionsOverlay.value = true;
}

async function openHistoryOverlay() {
  showHistoryOverlay.value = true;
  await fetchOverlayHistory();
}

async function fetchOverlayHistory() {
  historyLoading.value = true;
  try {
    historyData.value = await fetchSessionHistory(historyDays.value);
  } catch (err) {
    console.error('Failed to load usage history:', err);
    historyData.value = { sessions: [], total_sessions: 0 };
  } finally {
    historyLoading.value = false;
  }
}

async function handleMpesaPayment() {
  paymentLoading.value = true;
  paymentStatusMessage.value = '';
  paymentErrorMessage.value = '';
  paymentEventReceived.value = false;
  clearPaymentStatusPollTimer();
  leavePaymentStatusChannel();
  try {
    const phone = '254' + paymentForm.value.phone.replace(/\D/g, '');
    maskedPhone.value = phone.slice(0, 6) + '****' + phone.slice(-2);
    const result = await initiateMpesaPayment(phone, paymentAmount.value);

    pendingPaymentTransactionId.value = result?.data?.transaction_id || '';
    paymentStep.value = 'processing';
    paymentPollAttempt.value = 0;
    paymentStatusMessage.value = 'Waiting for M-Pesa confirmation…';

    if (pendingPaymentTransactionId.value) {
      subscribeToPaymentStatus(pendingPaymentTransactionId.value);
      pollPaymentStatus(pendingPaymentTransactionId.value);
    } else {
      setTimeout(loadDashboard, 4000);
    }
  } catch (err) {
    console.error('Payment failed:', err);
    paymentErrorMessage.value = err?.response?.data?.message || 'Payment request failed. Please try again.';
  } finally {
    paymentLoading.value = false;
  }
}

async function handleVoucherRedeem() {
  voucherLoading.value = true;
  voucherStatusMessage.value = '';
  voucherErrorMessage.value = '';
  try {
    const result = await redeemVoucher(voucherForm.value.code);
    
    voucherStatusMessage.value = result.message || 'Voucher redeemed successfully.';
    showSuccess(voucherStatusMessage.value);
    voucherForm.value = { code: '' };
    
    // Refresh dashboard
    loadDashboard({ force: true });
  } catch (err) {
    console.error('Voucher redemption failed:', err);
    voucherErrorMessage.value = err?.response?.data?.message || 'Voucher redemption failed. Please check the code and try again.';
  } finally {
    voucherLoading.value = false;
  }
}

async function pollPaymentStatus(transactionId) {
  const maxAttempts = 18;

  const check = async () => {
    if (!pendingPaymentTransactionId.value || pendingPaymentTransactionId.value !== transactionId || paymentEventReceived.value) {
      return;
    }

    paymentPollAttempt.value += 1;
    try {
      const res = await checkPaymentStatus(transactionId);
      const data = res?.data ?? res;
      const value = String(data?.status || '').toLowerCase();

      const attempt = paymentPollAttempt.value;
      const dots = '.'.repeat((attempt % 3) + 1);
      paymentStatusMessage.value = `Checking status${dots} (attempt ${attempt})`;

      if (value === 'completed' || value === 'paid') {
        applySuccessfulPaymentUpdate(data);
        return;
      }

      if (value === 'failed' || value === 'cancelled') {
        applyFailedPaymentUpdate(data);
        return;
      }

      if (paymentPollAttempt.value < maxAttempts) {
        paymentStatusPollTimer.value = window.setTimeout(check, 10000);
      } else {
        paymentStep.value = 'failed';
        paymentErrorMessage.value = 'Payment confirmation timed out. If you entered your PIN, please wait a moment then refresh the page.';
      }
    } catch (err) {
      console.error('Payment status check failed:', err);
      if (paymentPollAttempt.value < maxAttempts) {
        paymentStatusPollTimer.value = window.setTimeout(check, 10000);
      }
    }
  };

  paymentStatusPollTimer.value = window.setTimeout(check, 5000);
}

function clearPaymentStatusPollTimer() {
  if (paymentStatusPollTimer.value) {
    window.clearTimeout(paymentStatusPollTimer.value);
    paymentStatusPollTimer.value = null;
  }
}

function leavePaymentStatusChannel() {
  if (paymentEventChannelName.value && typeof window !== 'undefined' && window.Echo) {
    window.Echo.leave(paymentEventChannelName.value);
  }
  paymentEventChannelName.value = '';
}

function subscribeToPaymentStatus(transactionId) {
  if (typeof window === 'undefined' || !window.Echo || !transactionId) {
    return;
  }

  leavePaymentStatusChannel();

  const channelName = `pppoe-portal.payment.${transactionId}`;
  paymentEventChannelName.value = channelName;

  window.Echo
    .channel(channelName)
    .listen('.pppoe.payment.updated', (event) => {
      if (!event || event.transaction_id !== transactionId) {
        return;
      }

      paymentEventReceived.value = true;
      paymentStatusMessage.value = 'Payment status updated.';

      const status = String(event.status || '').toLowerCase();
      if (status === 'completed' || status === 'paid') {
        applySuccessfulPaymentUpdate(event);
        return;
      }

      if (status === 'failed' || status === 'cancelled') {
        applyFailedPaymentUpdate(event);
      }
    });
}

function applySuccessfulPaymentUpdate(data) {
  paymentEventReceived.value = true;
  clearPaymentStatusPollTimer();
  leavePaymentStatusChannel();

  paymentConfirmation.value = {
    receipt: data.payment_reference || '',
    paidAt: data.paid_at || null,
    nextDue: data.user?.next_payment_due || data.next_payment_due || null,
    expiresAt: data.user?.expiration_date || null,
    status: data.user?.status || 'active',
  };

  if (data.user && dashboardData.value?.user) {
    dashboardData.value = {
      ...dashboardData.value,
      user: { ...dashboardData.value.user, ...data.user },
    };
  }

  paymentStep.value = 'success';
  paymentStatusMessage.value = 'Payment confirmed successfully.';
  paymentForm.value = { phone: '' };
  pendingPaymentTransactionId.value = '';
  loadDashboard({ force: true });
}

function applyFailedPaymentUpdate(data = {}) {
  paymentEventReceived.value = true;
  clearPaymentStatusPollTimer();
  leavePaymentStatusChannel();
  paymentStep.value = 'failed';
  pendingPaymentTransactionId.value = '';
  paymentErrorMessage.value = data.message || 'Payment was cancelled or not completed. You can try again.';
}

function showSuccess(message) {
  successMessage.value = message;
  setTimeout(() => {
    successMessage.value = '';
  }, 5000);
}

function formatNumber(num) {
  return new Intl.NumberFormat('en-KE').format(num);
}

function formatDate(dateStr) {
  if (!dateStr) return '--';
  return new Date(dateStr).toLocaleDateString('en-KE', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

function formatDateTime(dateStr) {
  if (!dateStr) return '--';
  return new Date(dateStr).toLocaleString('en-KE', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatPaymentMethod(method) {
  if (!method) return 'Payment';
  if (method === 'mpesa') return 'M-Pesa';
  return String(method).replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatDuration(seconds) {
  if (!seconds) return '0s';
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;
  const parts = [];
  if (hours) parts.push(`${hours}h`);
  if (mins) parts.push(`${mins}m`);
  if (secs || !parts.length) parts.push(`${secs}s`);
  return parts.join(' ');
}

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = Number(bytes) || 0;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex += 1;
  }
  return `${value.toFixed(2)} ${units[unitIndex]}`;
}

async function handlePauseAccount() {
  pauseLoading.value = true;
  try {
    const result = await pauseAccount();
    showSuccess(result.message || 'Account paused successfully.');
    showPauseOverlay.value = false;
    await loadDashboard({ force: true });
  } catch (err) {
    console.error('Pause failed:', err);
    showSuccess(err?.response?.data?.message || 'Failed to pause account.');
  } finally {
    pauseLoading.value = false;
  }
}

async function handleResumeAccount() {
  pauseLoading.value = true;
  try {
    const result = await resumeAccount();
    showSuccess(result.message || 'Account resumed.');
    showPauseOverlay.value = false;
    await loadDashboard({ force: true });
  } catch (err) {
    console.error('Resume failed:', err);
    showSuccess(err?.response?.data?.message || 'Failed to resume account.');
  } finally {
    pauseLoading.value = false;
  }
}

async function loadPlans() {
  plansLoading.value = true;
  try {
    const result = await fetchPlans();
    availablePlans.value = result.data || [];
  } catch (err) {
    console.error('Failed to load plans:', err);
  } finally {
    plansLoading.value = false;
  }
}

async function handlePlanSwitch() {
  if (!selectedPlanId.value) return;
  planSwitchLoading.value = true;
  try {
    const result = await requestPlanSwitch(selectedPlanId.value);
    showSuccess(result.message || 'Plan switch scheduled.');
    selectedPlanId.value = null;
    showPlanSwitchOverlay.value = false;
    await loadDashboard({ force: true });
  } catch (err) {
    console.error('Plan switch failed:', err);
    showSuccess(err?.response?.data?.message || 'Plan switch failed.');
  } finally {
    planSwitchLoading.value = false;
  }
}

async function loadTimedVoucherOptions() {
  tvPackagesLoading.value = true;
  try {
    const result = await fetchTimedVoucherOptions();
    tvPackages.value = result.packages || [];
    activeTimedVoucher.value = result.active_voucher || null;
  } catch (err) {
    console.error('Failed to load timed voucher options:', err);
  } finally {
    tvPackagesLoading.value = false;
  }
}

async function handleBuyTimedVoucher() {
  if (!selectedTvPackage.value || !selectedTvDuration.value || !timedVoucherPhone.value) return;
  timedVoucherLoading.value = true;
  timedVoucherMessage.value = '';
  try {
    const phone = '254' + timedVoucherPhone.value.replace(/\D/g, '');
    const result = await buyTimedVoucher(phone, selectedTvPackage.value.id, selectedTvDuration.value.hours);
    timedVoucherSuccess.value = true;
    timedVoucherMessage.value = result.message || 'Payment request sent. Enter your M-Pesa PIN.';
    showSuccess(timedVoucherMessage.value);
  } catch (err) {
    timedVoucherSuccess.value = false;
    timedVoucherMessage.value = err?.response?.data?.message || 'Failed to initiate payment.';
  } finally {
    timedVoucherLoading.value = false;
  }
}

onMounted(() => {
  const seed = getDashboardSeed();
  if (seed) {
    dashboardData.value = normalizeDashboard(seed);
  }

  loadDashboard({ force: !seed });
  loadPlans();
  loadTimedVoucherOptions();
});

onBeforeUnmount(() => {
  clearPaymentStatusPollTimer();
  leavePaymentStatusChannel();
});
</script>

<style>
/* Thin scrollbars */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.25); border-radius: 9999px; }

/* ══ Hero card variants — used by accountStatus.heroClass computed ══ */
.hero-active    { background: linear-gradient(135deg, #064e3b, #065f46); border-color: rgba(16,185,129,0.4); }
.hero-paused    { background: linear-gradient(135deg, #451a03, #78350f); border-color: rgba(245,158,11,0.4); }
.hero-suspended { background: linear-gradient(135deg, #450a0a, #7f1d1d); border-color: rgba(239,68,68,0.4);  }
.hero-expired   { background: linear-gradient(135deg, #422006, #713f12); border-color: rgba(234,179,8,0.4);  }
.hero-unknown   { background: linear-gradient(135deg, #1e1b4b, #2e1065); border-color: rgba(99,102,241,0.4); }
</style>
