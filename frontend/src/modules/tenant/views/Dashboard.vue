<template>
  <div class="bg-gradient-to-br from-green-50 via-emerald-50/50 to-teal-50/30 -mx-6 -my-6 px-6 py-8 pb-16">
    <!-- Enhanced Header with Welcome Message -->
    <div class="mb-10">
      <div class="flex items-center justify-between flex-wrap gap-6">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-12 h-12 bg-gradient-to-br from-green-600 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
            </div>
            <div>
              <h1 class="text-4xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 bg-clip-text text-transparent">Dashboard Overview</h1>
              <p class="text-sm text-gray-600 mt-1 font-medium">Welcome back! Monitor your network performance in real-time</p>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div v-if="lastUpdated" class="px-4 py-2.5 rounded-xl bg-white shadow-md border border-gray-200/50 backdrop-blur-sm">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <span class="text-xs font-semibold text-gray-700">Updated {{ formatTimeAgo(lastUpdated) }}</span>
            </div>
          </div>
          <div class="px-5 py-2.5 rounded-xl shadow-lg" :class="isConnected ? 'bg-gradient-to-r from-green-600 to-emerald-600 text-white' : 'bg-gradient-to-r from-red-500 to-rose-500 text-white'">
            <div class="flex items-center gap-2">
              <span class="relative flex h-2.5 w-2.5">
                <span v-if="isConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-white"></span>
              </span>
              <span class="text-sm font-bold">{{ isConnected ? 'Live Updates' : 'Offline' }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="text-center">
        <div class="w-16 h-16 border-4 border-green-200 border-t-green-600 rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-gray-600">Loading dashboard statistics...</p>
      </div>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-8">
      
      <!-- PAYMENT ANALYTICS WIDGET -->
      <section>
        <PaymentWidget :paymentData="paymentData" />
      </section>

      <!-- EXPENSES WIDGET -->
      <section>
        <ExpensesWidget :expensesData="expensesData" />
      </section>

      <!-- BUSINESS ANALYTICS WIDGET -->
      <section>
        <BusinessAnalyticsWidget :analyticsData="analyticsData" />
      </section>
      
      <!-- QUICK STATS OVERVIEW -->
      <section>
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
              <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              Quick Stats
            </h2>
            <p class="text-sm text-gray-600 mt-1">Key metrics at a glance</p>
          </div>
          <div class="px-4 py-2 bg-green-100 text-green-700 text-sm font-semibold rounded-lg">
            {{ formatCurrency(stats.totalRevenue) }} Total Revenue
          </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <!-- Total Routers -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                </svg>
              </div>
              <div class="px-3 py-1 rounded-lg text-xs font-semibold" :class="`${routerHealthStatus?.bgColor || 'bg-gray-100'} ${routerHealthStatus?.color || 'text-gray-600'}`">
                {{ routerHealthStatus?.label || 'Loading' }}
              </div>
            </div>
            <p class="text-sm font-medium text-gray-600 mb-1">Total Routers</p>
            <h3 class="text-3xl font-bold text-gray-900 mb-3">{{ stats.totalRouters || 0 }}</h3>
            <div class="flex items-center gap-3">
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                <span class="text-xs font-medium text-gray-700">{{ stats.onlineRouters || 0 }} online</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                <span class="text-xs font-medium text-gray-700">{{ stats.offlineRouters || 0 }} offline</span>
              </div>
            </div>
          </div>

          <!-- Active Sessions -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
              </div>
              <div v-if="userGrowth" class="flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold"
                :class="userGrowth?.isPositive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                <svg v-if="userGrowth?.direction === 'up'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                </svg>
                <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
                <span>{{ userGrowth?.value }}%</span>
              </div>
            </div>
            <p class="text-sm font-medium text-gray-600 mb-1">Active Sessions</p>
            <h3 class="text-3xl font-bold text-gray-900 mb-3">{{ stats.activeSessions || 0 }}</h3>
            <div class="flex items-center gap-3">
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                <span class="text-xs font-medium text-gray-700">{{ stats.hotspotUsers || 0 }} Hotspot</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="w-2 h-2 bg-purple-500 rounded-full"></div>
                <span class="text-xs font-medium text-gray-700">{{ stats.pppoeUsers || 0 }} PPPoE</span>
              </div>
            </div>
          </div>

          <!-- Data Usage -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
              </div>
            </div>
            <p class="text-sm font-medium text-gray-600 mb-1">Data Usage</p>
            <h3 class="text-3xl font-bold text-gray-900 mb-3">{{ formatDataSize(stats.dataUsage) }}</h3>
            <p class="text-xs text-gray-500">Total data transferred</p>
          </div>
        </div>
      </section>


      <!-- Charts Row -->
      <section>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Active Users Chart -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-semibold text-gray-900">Active Users Trend</h3>
              <select class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option>Last 7 days</option>
                <option>Last 30 days</option>
                <option>Last 90 days</option>
              </select>
            </div>
            <div class="h-64 flex items-end justify-between gap-2">
              <div v-for="(item, index) in chartData.users" :key="index" class="flex-1 flex flex-col items-center gap-2 group">
                <div class="relative w-full">
                  <div 
                    class="w-full bg-blue-500 rounded-t-lg hover:bg-blue-600 transition-all duration-300 group-hover:scale-105" 
                    :style="{ height: item.percentage + '%' }"
                  ></div>
                  <div class="absolute -top-6 left-1/2 transform -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity bg-gray-900 text-white text-xs px-2 py-1 rounded whitespace-nowrap">
                    {{ item.value }} users
                  </div>
                </div>
                <span class="text-xs text-gray-500 font-medium">{{ chartData.labels[index] }}</span>
              </div>
            </div>
          </div>

          <!-- Revenue Chart -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-semibold text-gray-900">Revenue Overview</h3>
              <select class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option>This month</option>
                <option>Last month</option>
                <option>This year</option>
              </select>
            </div>
            <div class="h-64 flex items-end justify-between gap-2">
              <div v-for="(item, index) in chartData.revenue" :key="index" class="flex-1 flex flex-col items-center gap-2 group">
                <div class="relative w-full">
                  <div 
                    class="w-full bg-gradient-to-t from-purple-500 to-purple-400 rounded-t-lg hover:from-purple-600 hover:to-purple-500 transition-all duration-300 group-hover:scale-105" 
                    :style="{ height: item.percentage + '%' }"
                  ></div>
                  <div class="absolute -top-6 left-1/2 transform -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity bg-gray-900 text-white text-xs px-2 py-1 rounded whitespace-nowrap">
                    {{ formatCurrency(item.value) }}
                  </div>
                </div>
                <span class="text-xs text-gray-500 font-medium">{{ chartData.labels[index] }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- System Health & Quick Actions -->
      <section>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- System Health -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-6">System Health</h3>
            <div class="space-y-4">
              <!-- Router Health -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Router Network</span>
                  <div class="flex items-center gap-2">
                    <span class="text-sm font-bold text-gray-900">{{ routerHealthPercentage }}%</span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="`${routerHealthStatus?.bgColor || 'bg-gray-100'} ${routerHealthStatus?.color || 'text-gray-600'}`">
                      {{ routerHealthStatus?.label || 'Loading' }}
                    </span>
                  </div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all duration-500"
                    :class="routerHealthPercentage >= 70 ? 'bg-green-500' : routerHealthPercentage >= 50 ? 'bg-yellow-500' : 'bg-red-500'"
                    :style="{ width: routerHealthPercentage + '%' }"
                  ></div>
                </div>
              </div>

              <!-- Active Sessions Capacity -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Active Sessions</span>
                  <span class="text-sm font-bold text-gray-900">{{ stats.activeSessions }} / {{ stats.totalUsers }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                  <div 
                    class="h-full bg-blue-500 rounded-full transition-all duration-500"
                    :style="{ width: stats.totalUsers > 0 ? (stats.activeSessions / stats.totalUsers * 100) + '%' : '0%' }"
                  ></div>
                </div>
              </div>

              <!-- Data Usage -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Data Transferred</span>
                  <span class="text-sm font-bold text-gray-900">{{ formatDataSize(stats.dataUsage) }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                  <div class="h-full bg-gradient-to-r from-orange-400 to-orange-600 rounded-full transition-all duration-500" style="width: 65%"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-6">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-3">
              <button 
                @click="refreshStats"
                class="flex items-center gap-3 p-3 rounded-lg border-2 border-gray-200 hover:border-blue-500 hover:bg-blue-50 transition-all group"
              >
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                  <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                </div>
                <div class="text-left">
                  <p class="text-sm font-semibold text-gray-900">Refresh</p>
                  <p class="text-xs text-gray-500">Update data</p>
                </div>
              </button>

              <router-link 
                to="/routers"
                class="flex items-center gap-3 p-3 rounded-lg border-2 border-gray-200 hover:border-green-500 hover:bg-green-50 transition-all group"
              >
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                  <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                  </svg>
                </div>
                <div class="text-left">
                  <p class="text-sm font-semibold text-gray-900">Routers</p>
                  <p class="text-xs text-gray-500">Manage</p>
                </div>
              </router-link>

              <router-link 
                to="/packages"
                class="flex items-center gap-3 p-3 rounded-lg border-2 border-gray-200 hover:border-purple-500 hover:bg-purple-50 transition-all group"
              >
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                  <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                  </svg>
                </div>
                <div class="text-left">
                  <p class="text-sm font-semibold text-gray-900">Packages</p>
                  <p class="text-xs text-gray-500">Plans</p>
                </div>
              </router-link>

              <router-link 
                to="/users"
                class="flex items-center gap-3 p-3 rounded-lg border-2 border-gray-200 hover:border-indigo-500 hover:bg-indigo-50 transition-all group"
              >
                <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 transition-colors">
                  <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                  </svg>
                </div>
                <div class="text-left">
                  <p class="text-sm font-semibold text-gray-900">Users</p>
                  <p class="text-xs text-gray-500">Manage</p>
                </div>
              </router-link>
            </div>
          </div>
        </div>
      </section>

      <!-- Activity Section -->
      <section>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Router Status -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Router Status</h3>
            <div class="space-y-3">
              <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                <div class="flex items-center gap-3">
                  <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                  <span class="text-sm font-medium text-gray-700">Online</span>
                </div>
                <span class="text-sm font-bold text-green-600">{{ stats.onlineRouters }}</span>
              </div>
              <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                  <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                  <span class="text-sm font-medium text-gray-700">Offline</span>
                </div>
                <span class="text-sm font-bold text-gray-600">{{ stats.offlineRouters }}</span>
              </div>
              <div class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg">
                <div class="flex items-center gap-3">
                  <div class="w-2 h-2 bg-yellow-500 rounded-full animate-pulse"></div>
                  <span class="text-sm font-medium text-gray-700">Provisioning</span>
                </div>
                <span class="text-sm font-bold text-yellow-600">{{ stats.provisioningRouters }}</span>
              </div>
            </div>
          </div>

          <!-- Recent Activity -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Activity</h3>
            <div class="space-y-3 max-h-64 overflow-y-auto">
              <div v-for="(update, index) in recentActivities.slice(0, 5)" :key="index" class="flex items-start gap-3 pb-3 border-b border-gray-100 last:border-0">
                <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                  <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm text-gray-900 truncate">{{ update.message }}</p>
                  <p class="text-xs text-gray-500 mt-1">{{ update.timestamp }}</p>
                </div>
              </div>
              <div v-if="recentActivities.length === 0" class="text-center py-8 text-gray-400 text-sm">
                No recent activity
              </div>
            </div>
          </div>

          <!-- Online Users -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Online Users</h3>
            <div class="space-y-3 max-h-64 overflow-y-auto">
              <div v-for="user in onlineUsers.slice(0, 5)" :key="user.id" class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded-lg transition-colors">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-xs font-semibold">
                  {{ user.name.charAt(0).toUpperCase() }}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-900 truncate">{{ user.name }}</p>
                  <p class="text-xs text-gray-500">Active now</p>
                </div>
                <span class="w-2 h-2 bg-green-500 rounded-full"></span>
              </div>
              <div v-if="onlineUsers.length === 0" class="text-center py-8 text-gray-400 text-sm">
                No users online
              </div>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuth } from '@/modules/common/composables/auth/useAuth'
import { useDashboard } from '@/modules/tenant/composables/data/useDashboard'
import PaymentWidget from '@/modules/tenant/components/dashboard/PaymentWidgetClean.vue'
import ExpensesWidget from '@/modules/tenant/components/dashboard/ExpensesWidgetClean.vue'
import BusinessAnalyticsWidget from '@/modules/tenant/components/dashboard/BusinessAnalyticsWidgetClean.vue'

const { user } = useAuth()
const { isConnected, subscribeToPrivateChannel, subscribeToPresenceChannel } = useBroadcasting()

const {
  stats,
  paymentData,
  expensesData,
  analyticsData,
  chartData,
  recentActivities,
  onlineUsers,
  loading,
  lastUpdated,
  fetchDashboardStats,
  refreshStats,
  updateStatsFromEvent,
  formatCurrency,
  formatDataSize,
  formatTimeAgo,
  routerHealthPercentage,
  routerHealthStatus,
  revenueGrowth,
  userGrowth,
} = useDashboard()

// EVENT-BASED: Subscribe to WebSocket channels (NO POLLING)
onMounted(() => {
  console.log('🚀 Dashboard mounted - EVENT-BASED mode')
  
  // Fetch initial dashboard stats ONCE
  fetchDashboardStats()
  
  // ✅ EVENT-BASED: Subscribe to dashboard stats updates via WebSocket
  // Backend broadcasts DashboardStatsUpdated event when stats change
  subscribeToPrivateChannel('dashboard-stats', {
    'DashboardStatsUpdated': (event) => {
      console.log('📊 Dashboard stats updated via WebSocket:', event)
      if (event.stats) {
        updateStatsFromEvent(event.stats)
      }
    },
    'stats.updated': (event) => {
      console.log('📊 Stats updated (legacy event):', event)
      if (event.stats) {
        updateStatsFromEvent(event.stats)
      }
    },
  })

  // ✅ EVENT-BASED: Subscribe to router status updates
  subscribeToPrivateChannel('router-status', {
    'RouterStatusUpdated': (event) => {
      console.log('🔌 Router status update received:', event)
      // Update stats reactively from event data instead of refetching
      if (event.stats) {
        updateStatsFromEvent(event.stats)
      }
      
      recentActivities.value.unshift({
        timestamp: new Date().toLocaleTimeString(),
        message: event.message || `Router ${event.router_id} status changed`,
      })
      if (recentActivities.value.length > 10) {
        recentActivities.value.pop()
      }
    },
  })

  // ✅ EVENT-BASED: Subscribe to routers channel
  subscribeToPrivateChannel('routers', {
    'RouterCreated': (event) => {
      console.log('✨ New router created:', event)
      recentActivities.value.unshift({
        timestamp: new Date().toLocaleTimeString(),
        message: `New router added: ${event.router?.name || 'Unknown'}`,
      })
      // Trigger stats refresh via event
      if (event.stats) {
        updateStatsFromEvent(event.stats)
      }
    },
    'RouterUpdated': (event) => {
      console.log('🔄 Router updated:', event)
      recentActivities.value.unshift({
        timestamp: new Date().toLocaleTimeString(),
        message: `Router updated: ${event.router?.name || 'Unknown'}`,
      })
    },
  })

  // ✅ EVENT-BASED: Subscribe to presence channel for online users
  subscribeToPresenceChannel('online', {
    here: (users) => {
      console.log('👥 Users currently online:', users)
      onlineUsers.value = users
    },
    joining: (user) => {
      console.log('👋 User joined:', user)
      onlineUsers.value.push(user)
    },
    leaving: (user) => {
      console.log('👋 User left:', user)
      onlineUsers.value = onlineUsers.value.filter(u => u.id !== user.id)
    },
  })

  // ✅ EVENT-BASED: Subscribe to user-specific private channel
  if (user.value?.id) {
    subscribeToPrivateChannel(`App.Models.User.${user.value.id}`, {
      'Notification': (event) => {
        console.log('🔔 Personal notification:', event)
      },
    })
  }
  
  console.log('✅ All WebSocket subscriptions active - NO POLLING!')
})

// Cleanup on unmount (WebSocket cleanup handled by composable)
onUnmounted(() => {
  console.log('🧹 Dashboard unmounted - WebSocket cleanup automatic')
})
</script>

<style scoped>
/* Custom scrollbar for activity sections */
.overflow-y-auto::-webkit-scrollbar {
  width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
