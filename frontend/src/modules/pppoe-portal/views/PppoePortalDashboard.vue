<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
      <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-12">
        <div class="flex items-center justify-between h-14 sm:h-16">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center">
              <i class="fas fa-wifi text-white text-lg"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900">Customer Portal</h1>
          </div>
          
          <div class="flex items-center gap-4">
            <span class="text-sm text-gray-600 hidden sm:block">
              Welcome, <strong>{{ user?.full_name || user?.username }}</strong>
            </span>
            <button
              @click="handleLogout"
              class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:border-red-200 hover:bg-red-50 hover:text-red-700 transition-colors"
              title="Logout"
            >
              <i class="fas fa-sign-out-alt"></i>
              <span class="hidden sm:inline">Logout</span>
            </button>
          </div>
        </div>
        <nav class="flex items-center gap-1 sm:gap-2 border-t border-gray-100 py-2 overflow-x-auto">
          <button
            class="px-3 py-2 rounded-md text-sm font-medium text-indigo-700 bg-indigo-50 whitespace-nowrap"
          >
            <i class="fas fa-house mr-0 sm:mr-2"></i><span class="hidden sm:inline">Dashboard</span>
          </button>
          <button
            @click="openPaymentsOverlay"
            class="px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 whitespace-nowrap"
          >
            <i class="fas fa-credit-card mr-0 sm:mr-2"></i><span class="hidden sm:inline">Payments</span>
          </button>
          <button
            @click="openHistoryOverlay"
            class="px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 whitespace-nowrap"
          >
            <i class="fas fa-clock-rotate-left mr-0 sm:mr-2"></i><span class="hidden sm:inline">Usage History</span>
          </button>
        </nav>
      </div>
    </header>

    <main class="w-full px-4 sm:px-6 lg:px-8 xl:px-12 py-6 sm:py-8">
      <!-- Loading State -->
      <div v-if="isLoading && !dashboardData" class="flex items-center justify-center h-64">
        <div class="text-center">
          <i class="fas fa-spinner fa-spin text-3xl text-indigo-600 mb-4"></i>
          <p class="text-gray-600">Loading your dashboard...</p>
        </div>
      </div>

      <div v-else-if="loadError" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-5 mb-6">
        <div class="flex items-start gap-3">
          <i class="fas fa-exclamation-circle mt-0.5"></i>
          <div>
            <p class="font-semibold">Failed to load dashboard</p>
            <p class="text-sm opacity-90">{{ loadError }}</p>
            <button
              @click="loadDashboard"
              class="mt-3 px-3 py-2 bg-red-700 text-white rounded-md text-sm hover:bg-red-800 transition-colors"
            >
              Retry
            </button>
          </div>
        </div>
      </div>

      <template v-else-if="dashboardData">
        <!-- Account Status Banner -->
        <div 
          :class="[
            'rounded-xl p-4 mb-6 flex items-center gap-4',
            accountStatus.class
          ]"
        >
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
            <i :class="['fas', accountStatus.icon, 'text-xl']"></i>
          </div>
          <div class="flex-1">
            <h2 class="font-semibold text-lg">{{ accountStatus.title }}</h2>
            <p class="text-sm opacity-90">{{ accountStatus.message }}</p>
          </div>
          <button
            v-if="user?.status === 'active'"
            @click="openPaymentModal"
            class="px-4 py-2 bg-white text-gray-900 rounded-lg font-medium hover:bg-gray-100 transition-colors"
          >
            <i class="fas fa-plus-circle mr-2"></i>
            Top Up
          </button>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 sm:gap-6 mb-6 sm:mb-8">
          <!-- Current Session -->
          <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
              <div class="w-10 h-10 sm:w-12 sm:h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-signal text-green-600 text-lg sm:text-xl"></i>
              </div>
              <span 
                :class="[
                  'px-2 py-1 text-xs font-medium rounded-full',
                  dashboardData.current_session ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'
                ]"
              >
                {{ dashboardData.current_session ? 'Online' : 'Offline' }}
              </span>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Current Session</h3>
            <p class="text-2xl font-bold text-gray-900 mt-1">
              {{ dashboardData.current_session ? dashboardData.current_session.duration_formatted : '--' }}
            </p>
            <p v-if="dashboardData.current_session" class="text-xs text-gray-500 mt-2">
              IP: {{ dashboardData.current_session.ip_address }}
            </p>
          </div>

          <!-- Balance -->
          <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
              <div class="w-10 h-10 sm:w-12 sm:h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-wallet text-blue-600 text-lg sm:text-xl"></i>
              </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Account Balance</h3>
            <p class="text-2xl font-bold text-gray-900 mt-1">
              KES {{ formatNumber(dashboardData.user?.balance || 0) }}
            </p>
            <p class="text-xs text-gray-500 mt-2">
              Expires: {{ formatDate(dashboardData.user?.expiration_date) }}
            </p>
          </div>

          <!-- Data Usage -->
          <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
              <div class="w-10 h-10 sm:w-12 sm:h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-chart-line text-purple-600 text-lg sm:text-xl"></i>
              </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">30-Day Usage</h3>
            <p class="text-2xl font-bold text-gray-900 mt-1">
              {{ dashboardData.usage_stats?.total_usage_formatted || '0 B' }}
            </p>
            <p class="text-xs text-gray-500 mt-2">
              {{ dashboardData.usage_stats?.total_sessions || 0 }} sessions
            </p>
          </div>

          <!-- Payment Status -->
          <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
              <div class="w-10 h-10 sm:w-12 sm:h-12 bg-emerald-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-money-bill-wave text-emerald-600 text-lg sm:text-xl"></i>
              </div>
              <span :class="['px-2 py-1 text-xs font-medium rounded-full', paymentStatusClass]">
                {{ paymentStatusLabel }}
              </span>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Payment Status</h3>
            <p class="text-lg font-bold text-gray-900 mt-1">
              {{ paymentStatusLabel }}
            </p>
            <p class="text-xs text-gray-500 mt-2">
              Due: {{ formatDate(dashboardData.user?.next_payment_due || dashboardData.user?.expiration_date) }}
            </p>
          </div>

          <!-- Package -->
          <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
              <div class="w-10 h-10 sm:w-12 sm:h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-box text-orange-600 text-lg sm:text-xl"></i>
              </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Current Plan</h3>
            <p class="text-lg font-bold text-gray-900 mt-1 truncate">
              {{ dashboardData.user?.package?.name || 'No Plan' }}
            </p>
            <p v-if="dashboardData.user?.package" class="text-xs text-gray-500 mt-2">
              {{ dashboardData.user.package.download_speed }} ↓ / {{ dashboardData.user.package.upload_speed }} ↑
            </p>
            <p v-else class="text-xs text-gray-500 mt-2">
              No package is associated with this account
            </p>
          </div>
        </div>

        <!-- Main Content: Account Info & Quick Top Up Row -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
          <!-- Account Information -->
          <div class="xl:col-span-2 bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
              <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-user-circle text-indigo-500"></i>
                Account Information
              </h3>
            </div>
            <div class="p-4 sm:p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Account Number</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">{{ dashboardData.user?.account_number || '--' }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Username</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">{{ dashboardData.user?.username || '--' }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Full Name</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">{{ dashboardData.user?.full_name || '--' }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Phone</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">{{ dashboardData.user?.phone || '--' }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Email</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">{{ dashboardData.user?.email || '--' }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Expires</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">{{ formatDate(dashboardData.user?.expiration_date) }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Plan Amount</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">KES {{ formatNumber(planAmount) }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Amount Paid</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">KES {{ formatNumber(dashboardData.user?.amount_paid || 0) }}</p>
              </div>
              <div class="rounded-lg bg-gray-50 p-3 sm:p-4">
                <p class="text-xs font-medium uppercase text-gray-500">Amount Due</p>
                <p class="mt-1 font-semibold text-gray-900 text-sm sm:text-base">KES {{ formatNumber(paymentAmount) }}</p>
              </div>
            </div>
          </div>

          <!-- Quick Top Up -->
          <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
              <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-credit-card text-indigo-500"></i>
                Quick Top Up
              </h3>
            </div>
            <div class="p-4 sm:p-6 space-y-3">
              <button
                @click="openPaymentModal"
                class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2"
              >
                <i class="fas fa-mobile-alt"></i>
                M-Pesa Payment
              </button>
              <button
                @click="showVoucherModal = true"
                class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2"
              >
                <i class="fas fa-ticket-alt"></i>
                Redeem Voucher
              </button>
            </div>

            <!-- Payment History (Compact) -->
            <div class="px-4 sm:px-6 pb-4 sm:pb-6">
              <div class="border-t border-gray-200 pt-4">
                <div class="flex items-center justify-between gap-2 mb-3">
                  <h4 class="font-medium text-gray-900 text-sm flex items-center gap-2">
                    <i class="fas fa-receipt text-indigo-500 text-xs"></i>
                    Payment History
                  </h4>
                  <button
                    @click="openPaymentsOverlay"
                    class="text-xs text-indigo-600 hover:text-indigo-700 font-medium"
                  >
                    View All
                  </button>
                </div>
                <div class="space-y-2">
                  <div
                    v-for="payment in dashboardData.recent_payments?.slice(0, 3)"
                    :key="payment.id"
                    class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0"
                  >
                    <div>
                      <p class="font-medium text-gray-900 text-sm">KES {{ payment.amount }}</p>
                      <p class="text-xs text-gray-500">{{ formatDate(payment.created_at) }}</p>
                    </div>
                    <span
                      :class="[
                        'px-2 py-0.5 text-xs font-medium rounded-full',
                        payment.status === 'completed' ? 'bg-green-100 text-green-700' :
                        payment.status === 'pending' ? 'bg-yellow-100 text-yellow-700' :
                        'bg-red-100 text-red-700'
                      ]"
                    >
                      {{ payment.status }}
                    </span>
                  </div>
                  <div v-if="!dashboardData.recent_payments?.length" class="py-4 text-center text-gray-500 text-sm">
                    No recent payments
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Current Session Details (if active) -->
        <div v-if="dashboardData.current_session" class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
          <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
              <i class="fas fa-info-circle text-indigo-500"></i>
              Current Session Details
            </h3>
          </div>
          <div class="p-4 sm:p-6">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <div class="text-center p-3 sm:p-4 bg-gray-50 rounded-lg">
                <i class="fas fa-download text-green-500 mb-2 text-lg"></i>
                <p class="text-xs text-gray-500">Download</p>
                <p class="font-semibold text-gray-900">{{ dashboardData.current_session.download_formatted }}</p>
              </div>
              <div class="text-center p-3 sm:p-4 bg-gray-50 rounded-lg">
                <i class="fas fa-upload text-blue-500 mb-2 text-lg"></i>
                <p class="text-xs text-gray-500">Upload</p>
                <p class="font-semibold text-gray-900">{{ dashboardData.current_session.upload_formatted }}</p>
              </div>
              <div class="text-center p-3 sm:p-4 bg-gray-50 rounded-lg">
                <i class="fas fa-clock text-purple-500 mb-2 text-lg"></i>
                <p class="text-xs text-gray-500">Duration</p>
                <p class="font-semibold text-gray-900">{{ dashboardData.current_session.duration_formatted }}</p>
              </div>
              <div class="text-center p-3 sm:p-4 bg-gray-50 rounded-lg">
                <i class="fas fa-network-wired text-orange-500 mb-2 text-lg"></i>
                <p class="text-xs text-gray-500">IP Address</p>
                <p class="font-semibold text-gray-900 text-xs">{{ dashboardData.current_session.ip_address }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Sessions (Full Width) -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
          <div class="px-4 sm:px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 flex items-center gap-2">
              <i class="fas fa-history text-indigo-500"></i>
              Recent Sessions
            </h3>
            <button
              @click="openHistoryOverlay"
              class="text-sm text-indigo-600 hover:text-indigo-700 font-medium"
            >
              View All
            </button>
          </div>
          <div class="divide-y divide-gray-100">
            <div
              v-for="session in recentSessions"
              :key="session.id"
              class="px-4 sm:px-6 py-3 sm:py-4 hover:bg-gray-50 transition-colors"
            >
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div class="flex items-center gap-3 sm:gap-4">
                  <div
                    :class="[
                      'w-2 h-2 rounded-full flex-shrink-0',
                      session.status === 'active' ? 'bg-green-500 animate-pulse' : 'bg-gray-400'
                    ]"
                  ></div>
                  <div>
                    <p class="font-medium text-gray-900 text-sm sm:text-base">{{ formatDateTime(session.start_time) }}</p>
                    <p class="text-xs sm:text-sm text-gray-500">{{ session.duration_formatted }}</p>
                  </div>
                </div>
                <div class="text-left sm:text-right pl-5 sm:pl-0">
                  <p class="font-medium text-gray-900 text-sm sm:text-base">{{ session.total_formatted }}</p>
                  <p class="text-xs text-gray-500">{{ session.download_formatted }} ↓ {{ session.upload_formatted }} ↑</p>
                </div>
              </div>
            </div>
            <div v-if="recentSessions.length === 0" class="px-4 sm:px-6 py-8 text-center text-gray-500">
              <i class="fas fa-inbox text-3xl sm:text-4xl mb-3 opacity-30"></i>
              <p class="text-sm sm:text-base">No recent sessions found</p>
            </div>
          </div>
        </div>

        <!-- Support Section (Bottom) -->
        <div class="bg-indigo-50 rounded-xl p-4 sm:p-6">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h3 class="font-semibold text-indigo-900 mb-1 flex items-center gap-2">
                <i class="fas fa-headset"></i>
                Need Help?
              </h3>
              <p class="text-sm text-indigo-700">
                Contact your service provider for assistance with your account.
              </p>
            </div>
            <div class="flex flex-wrap gap-4 text-sm text-indigo-600">
              <p v-if="user?.email" class="flex items-center gap-2">
                <i class="fas fa-envelope"></i>
                <span class="break-all">{{ user.email }}</span>
              </p>
              <p v-if="user?.phone" class="flex items-center gap-2">
                <i class="fas fa-phone"></i>
                {{ user.phone }}
              </p>
            </div>
          </div>
        </div>
      </template>

      <div v-else class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-5">
        <div class="flex items-start gap-3">
          <i class="fas fa-exclamation-triangle mt-0.5"></i>
          <div>
            <p class="font-semibold">Dashboard data is temporarily unavailable</p>
            <p class="text-sm opacity-90">Please retry. If this persists, log in again.</p>
            <button
              @click="loadDashboard"
              class="mt-3 px-3 py-2 bg-amber-700 text-white rounded-md text-sm hover:bg-amber-800 transition-colors"
            >
              Retry
            </button>
          </div>
        </div>
      </div>
    </main>

    <!-- M-Pesa Payment Modal -->
    <Teleport to="body">
      <div v-if="showPaymentModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 bg-black/50">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">M-Pesa Payment</h3>
              <button @click="closePaymentModal" class="text-gray-400 hover:text-gray-600" title="Close">
                <i class="fas fa-times text-xl"></i>
              </button>
            </div>

            <form @submit.prevent="handleMpesaPayment" class="space-y-4">
              <div v-if="paymentStatusMessage" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                <div class="flex items-start gap-2">
                  <i class="fas fa-circle-info mt-0.5"></i>
                  <p>{{ paymentStatusMessage }}</p>
                </div>
              </div>

              <div v-if="paymentErrorMessage" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="flex items-start gap-2">
                  <i class="fas fa-exclamation-circle mt-0.5"></i>
                  <p>{{ paymentErrorMessage }}</p>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                <div class="relative">
                  <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">254</span>
                  <input
                    v-model="paymentForm.phone"
                    type="tel"
                    placeholder="7XX XXX XXX"
                    maxlength="9"
                    required
                    class="w-full pl-14 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
                <p class="text-xs text-gray-500 mt-1">Format: 2547XX XXX XXX</p>
              </div>

              <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Amount (KES)</label>
                <p class="text-3xl font-bold text-gray-900">KES {{ formatNumber(paymentAmount) }}</p>
                <p class="mt-1 text-sm text-gray-600">
                  {{ dashboardData?.user?.package?.name || 'Current plan' }} amount. Custom top-up amounts are disabled for this portal.
                </p>
              </div>

              <button
                type="submit"
                :disabled="paymentLoading"
                class="w-full py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
              >
                <i v-if="paymentLoading" class="fas fa-spinner fa-spin"></i>
                <span>{{ paymentLoading ? 'Processing...' : 'Pay with M-Pesa' }}</span>
              </button>

              <button
                type="button"
                @click="closePaymentModal"
                class="w-full py-3 border border-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-50 transition-colors"
              >
                Close
              </button>
            </form>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Payments Slideout -->
    <Teleport to="body">
      <div v-if="showPaymentsOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/40" @click="showPaymentsOverlay = false"></div>
        <aside class="absolute right-0 top-0 h-full w-full max-w-xl bg-white shadow-2xl flex flex-col">
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Payments</h3>
              <p class="text-sm text-gray-500">Pay your plan amount and review recent payments.</p>
            </div>
            <button @click="showPaymentsOverlay = false" class="p-2 text-gray-400 hover:text-gray-700" title="Close">
              <i class="fas fa-times text-xl"></i>
            </button>
          </div>
          <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-5">
              <p class="text-sm text-indigo-700">Current Plan</p>
              <p class="mt-1 text-xl font-bold text-indigo-950">{{ dashboardData?.user?.package?.name || 'No Plan' }}</p>
              <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <p class="text-indigo-700">Plan Amount</p>
                  <p class="font-semibold text-indigo-950">KES {{ formatNumber(planAmount) }}</p>
                </div>
                <div>
                  <p class="text-indigo-700">Amount Due</p>
                  <p class="font-semibold text-indigo-950">KES {{ formatNumber(paymentAmount) }}</p>
                </div>
              </div>
              <button
                @click="openPaymentModal"
                class="mt-5 w-full rounded-lg bg-green-600 px-4 py-3 font-semibold text-white hover:bg-green-700"
              >
                <i class="fas fa-mobile-alt mr-2"></i>
                Pay KES {{ formatNumber(paymentAmount) }} with M-Pesa
              </button>
            </div>

            <div>
              <h4 class="mb-3 font-semibold text-gray-900">Recent Payment History</h4>
              <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                <div
                  v-for="payment in dashboardData?.recent_payments || []"
                  :key="payment.id"
                  class="p-4 flex items-center justify-between gap-4"
                >
                  <div>
                    <p class="font-semibold text-gray-900">KES {{ formatNumber(payment.amount || 0) }}</p>
                    <p class="text-xs text-gray-500">{{ formatDate(payment.created_at) }} · {{ formatPaymentMethod(payment.payment_method) }}</p>
                  </div>
                  <span :class="['px-2 py-1 text-xs font-medium rounded-full', payment.status === 'completed' ? 'bg-green-100 text-green-700' : payment.status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700']">
                    {{ payment.status || 'unknown' }}
                  </span>
                </div>
                <div v-if="!dashboardData?.recent_payments?.length" class="p-8 text-center text-gray-500">
                  <i class="fas fa-receipt mb-3 text-4xl text-gray-200"></i>
                  <p>No payment history found</p>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Usage History Slideout -->
    <Teleport to="body">
      <div v-if="showHistoryOverlay" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/40" @click="showHistoryOverlay = false"></div>
        <aside class="absolute right-0 top-0 h-full w-full max-w-4xl bg-white shadow-2xl flex flex-col">
          <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 class="text-lg font-semibold text-gray-900">Usage History</h3>
              <p class="text-sm text-gray-500">Sessions, bandwidth, IPs, and totals.</p>
            </div>
            <div class="flex items-center gap-3">
              <select
                v-model="historyDays"
                @change="fetchOverlayHistory"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
              >
                <option :value="7">Last 7 days</option>
                <option :value="30">Last 30 days</option>
                <option :value="60">Last 60 days</option>
                <option :value="90">Last 90 days</option>
              </select>
              <button @click="showHistoryOverlay = false" class="p-2 text-gray-400 hover:text-gray-700" title="Close">
                <i class="fas fa-times text-xl"></i>
              </button>
            </div>
          </div>
          <div class="flex-1 overflow-y-auto p-6">
            <div v-if="historyLoading" class="flex h-48 items-center justify-center text-indigo-600">
              <i class="fas fa-spinner fa-spin mr-2"></i>
              Loading usage history...
            </div>
            <template v-else>
              <div class="mb-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-lg bg-gray-50 p-4">
                  <p class="text-sm text-gray-500">Sessions</p>
                  <p class="text-xl font-bold text-gray-900">{{ historyData?.total_sessions || 0 }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                  <p class="text-sm text-gray-500">Total Time</p>
                  <p class="text-xl font-bold text-gray-900">{{ overlayTotalDuration }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                  <p class="text-sm text-gray-500">Total Data</p>
                  <p class="text-xl font-bold text-gray-900">{{ overlayTotalData }}</p>
                </div>
              </div>
              <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full text-sm">
                  <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                      <th class="px-4 py-3 text-left">Date</th>
                      <th class="px-4 py-3 text-left">Duration</th>
                      <th class="px-4 py-3 text-left">Download</th>
                      <th class="px-4 py-3 text-left">Upload</th>
                      <th class="px-4 py-3 text-left">Total</th>
                      <th class="px-4 py-3 text-left">IP</th>
                      <th class="px-4 py-3 text-left">Status</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-for="session in historyData?.sessions || []" :key="session.id" class="hover:bg-gray-50">
                      <td class="px-4 py-3">
                        <p class="font-medium text-gray-900">{{ formatDate(session.start_time) }}</p>
                        <p class="text-xs text-gray-500">{{ formatDateTime(session.start_time) }}</p>
                      </td>
                      <td class="px-4 py-3">{{ session.duration_formatted }}</td>
                      <td class="px-4 py-3">{{ session.download_formatted }}</td>
                      <td class="px-4 py-3">{{ session.upload_formatted }}</td>
                      <td class="px-4 py-3 font-semibold">{{ session.total_formatted }}</td>
                      <td class="px-4 py-3 font-mono text-xs">{{ session.ip_address || '--' }}</td>
                      <td class="px-4 py-3">
                        <span :class="['px-2 py-1 rounded-full text-xs font-medium', session.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600']">
                          {{ session.status === 'active' ? 'Active' : 'Completed' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!historyData?.sessions?.length">
                      <td colspan="7" class="px-4 py-10 text-center text-gray-500">No usage history found</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>
        </aside>
      </div>
    </Teleport>

    <!-- Voucher Modal -->
    <Teleport to="body">
      <div v-if="showVoucherModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 bg-black/50">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Redeem Voucher</h3>
              <button @click="closeVoucherModal" class="text-gray-400 hover:text-gray-600" title="Close">
                <i class="fas fa-times text-xl"></i>
              </button>
            </div>

            <form @submit.prevent="handleVoucherRedeem" class="space-y-4">
              <div v-if="voucherStatusMessage" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                <div class="flex items-start gap-2">
                  <i class="fas fa-circle-info mt-0.5"></i>
                  <p>{{ voucherStatusMessage }}</p>
                </div>
              </div>

              <div v-if="voucherErrorMessage" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="flex items-start gap-2">
                  <i class="fas fa-exclamation-circle mt-0.5"></i>
                  <p>{{ voucherErrorMessage }}</p>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Voucher Code</label>
                <input
                  v-model="voucherForm.code"
                  type="text"
                  placeholder="Enter voucher code"
                  required
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 uppercase"
                />
                <p class="text-xs text-gray-500 mt-1">Enter the voucher code exactly as shown</p>
              </div>

              <button
                type="submit"
                :disabled="voucherLoading"
                class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
              >
                <i v-if="voucherLoading" class="fas fa-spinner fa-spin"></i>
                <span>{{ voucherLoading ? 'Redeeming...' : 'Redeem Voucher' }}</span>
              </button>

              <button
                type="button"
                @click="closeVoucherModal"
                class="w-full py-3 border border-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-50 transition-colors"
              >
                Close
              </button>
            </form>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Success Toast -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="transform translate-y-2 opacity-0"
        enter-to-class="transform translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="transform translate-y-0 opacity-100"
        leave-to-class="transform translate-y-2 opacity-0"
      >
        <div v-if="successMessage" class="fixed bottom-4 right-4 z-50">
          <div class="bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3">
            <i class="fas fa-check-circle"></i>
            <span>{{ successMessage }}</span>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
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
} = usePppoePortal();

const dashboardData = ref(null);
const recentSessions = ref([]);
const showPaymentModal = ref(false);
const showVoucherModal = ref(false);
const showPaymentsOverlay = ref(false);
const showHistoryOverlay = ref(false);
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
  showPaymentModal.value = false;
  paymentErrorMessage.value = '';
  paymentStatusMessage.value = '';
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
        class: 'bg-green-50 border border-green-200 text-green-800',
        icon: 'fa-check-circle',
        title: 'Account Active',
        message: 'Your internet service is active and running',
      };
    case 'suspended':
      return {
        class: 'bg-red-50 border border-red-200 text-red-800',
        icon: 'fa-exclamation-triangle',
        title: 'Account Suspended',
        message: 'Please make a payment to restore service',
      };
    case 'expired':
      return {
        class: 'bg-yellow-50 border border-yellow-200 text-yellow-800',
        icon: 'fa-clock',
        title: 'Account Expired',
        message: 'Your package has expired. Please renew',
      };
    default:
      return {
        class: 'bg-gray-50 border border-gray-200 text-gray-800',
        icon: 'fa-question-circle',
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
  try {
    const phone = '254' + paymentForm.value.phone.replace(/\D/g, '');
    const result = await initiateMpesaPayment(phone, paymentAmount.value);

    pendingPaymentTransactionId.value = result?.data?.transaction_id || '';
    paymentStatusMessage.value = result.message || 'Payment request sent. Complete the M-Pesa prompt on your phone.';
    showSuccess(paymentStatusMessage.value);

    if (pendingPaymentTransactionId.value) {
      pollPaymentStatus(pendingPaymentTransactionId.value);
    } else {
      setTimeout(loadDashboard, 3000);
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
  let attempts = 0;
  const maxAttempts = 18;

  const check = async () => {
    attempts += 1;
    try {
      const status = await checkPaymentStatus(transactionId);
      const value = String(status?.status || '').toLowerCase();
      paymentStatusMessage.value = `Payment status: ${value || 'pending'}`;

      if (value === 'completed' || value === 'paid') {
        showSuccess('Payment confirmed. Your dashboard has been refreshed.');
        paymentForm.value = { phone: '' };
        pendingPaymentTransactionId.value = '';
        await loadDashboard({ force: true });
        return;
      }

      if (value === 'failed' || value === 'cancelled') {
        paymentErrorMessage.value = 'Payment was not completed. Start another request when ready.';
        return;
      }

      if (attempts < maxAttempts) {
        setTimeout(check, 10000);
      }
    } catch (err) {
      console.error('Payment status check failed:', err);
      if (attempts < maxAttempts) {
        setTimeout(check, 10000);
      }
    }
  };

  check();
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

onMounted(() => {
  const seed = getDashboardSeed();
  if (seed) {
    dashboardData.value = normalizeDashboard(seed);
  }

  loadDashboard({ force: !seed });
});
</script>
