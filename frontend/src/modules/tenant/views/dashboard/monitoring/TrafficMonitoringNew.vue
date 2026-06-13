<template>
  <DataViewContainer
    title="Traffic Monitoring"
    subtitle="Comprehensive network traffic, performance, and revenue analytics"
    color-theme="blue"
    :stats="stats"
    :total="usageMetrics.activeUsers"
    :loading="loading"
    @refresh="fetchAllMetrics"
  >
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
    </template>

    <template #actions>
      <button
        @click="showAlertSettings = true"
        class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 transition-colors inline-flex items-center gap-1"
      >
        <Bell class="w-4 h-4" />
        Alerts
        <span v-if="alertSummary.total > 0" class="ml-1 px-1.5 py-0.5 text-xs bg-red-500 text-white rounded-full">{{ alertSummary.total }}</span>
      </button>
      <button
        @click="exportData"
        class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 transition-colors inline-flex items-center gap-1"
      >
        <Download class="w-4 h-4" />
        Export
      </button>
    </template>

    <template #filters>
      <BaseSelect v-model="timeRange" @change="setTimeRange(timeRange)" class="w-36">
        <option value="1h">Last Hour</option>
        <option value="6h">Last 6 Hours</option>
        <option value="24h">Last 24 Hours</option>
        <option value="7d">Last 7 Days</option>
        <option value="30d">Last 30 Days</option>
      </BaseSelect>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchAllMetrics" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Main Content -->
    <div v-else class="space-y-6 px-4 md:px-6 py-4">
      <!-- KPI Cards Grid -->
      <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <!-- Traffic Card -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-slate-500 uppercase tracking-wider">Current Traffic</span>
            <Activity class="w-4 h-4 text-blue-500" />
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatSpeed(trafficData.current) }}</div>
          <div class="flex items-center gap-2 mt-1 text-xs">
            <span class="text-green-600">↓ {{ formatSpeed(trafficData.download) }}</span>
            <span class="text-purple-600">↑ {{ formatSpeed(trafficData.upload) }}</span>
          </div>
        </div>

        <!-- Active Users Card -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-slate-500 uppercase tracking-wider">Active Users</span>
            <Users class="w-4 h-4 text-emerald-500" />
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ usageMetrics.activeUsers.toLocaleString() }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Peak: {{ usageMetrics.peakConcurrent.toLocaleString() }}</div>
        </div>

        <!-- Total Data Card -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-slate-500 uppercase tracking-wider">Total Data</span>
            <Database class="w-4 h-4 text-amber-500" />
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatBytes(usageMetrics.totalDataConsumed) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Across {{ usageMetrics.totalSessions }} sessions</div>
        </div>

        <!-- Revenue Card -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-slate-500 uppercase tracking-wider">Revenue</span>
            <DollarSign class="w-4 h-4 text-purple-500" />
          </div>
          <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">₱{{ (revenueMetrics.totalRevenue / 1000).toFixed(1) }}k</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">₱{{ revenueMetrics.revenuePerUser.toFixed(0) }}/user</div>
        </div>

        <!-- Performance Card -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-slate-500 uppercase tracking-wider">Latency</span>
            <Gauge class="w-4 h-4 text-cyan-500" />
          </div>
          <div class="text-2xl font-bold" :class="performanceMetrics.latency > 100 ? 'text-red-600' : 'text-slate-900'">
            {{ performanceMetrics.latency.toFixed(0) }}ms
          </div>
          <div class="text-xs mt-1" :class="performanceMetrics.packetLoss > 1 ? 'text-red-500' : 'text-slate-500'">
            Loss: {{ performanceMetrics.packetLoss.toFixed(2) }}%
          </div>
        </div>

        <!-- System Health Card -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-slate-500 uppercase tracking-wider">System Health</span>
            <Server class="w-4 h-4 text-indigo-500" />
          </div>
          <div class="text-2xl font-bold" :class="systemHealth.uptime < 99 ? 'text-red-600' : 'text-slate-900'">
            {{ systemHealth.uptime.toFixed(1) }}%
          </div>
          <div class="text-xs mt-1" :class="systemHealth.offlineRouters > 0 ? 'text-red-500' : 'text-slate-500'">
            {{ systemHealth.offlineRouters }} offline routers
          </div>
        </div>
      </div>

      <!-- Alerts Banner (if any) -->
      <div v-if="alertSummary.critical > 0 || alertSummary.warning > 0" class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-center gap-3">
          <AlertTriangle class="w-5 h-5 text-red-500" />
          <div class="flex-1">
            <span class="font-semibold text-red-900">{{ alertSummary.critical }} Critical</span>
            <span class="text-red-700" v-if="alertSummary.warning > 0"> and {{ alertSummary.warning }} Warning alerts active</span>
          </div>
          <button @click="showAlertPanel = true" class="text-sm text-red-700 hover:text-red-900 font-medium">View All</button>
        </div>
      </div>

      <!-- Traffic Chart & Top Consumers -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Traffic History Chart -->
        <div class="lg:col-span-2 bg-white rounded-lg border border-slate-200">
          <div class="p-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Traffic History</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">Download vs Upload over time</p>
          </div>
          <div class="p-4">
            <div class="h-64 flex items-end gap-1">
              <div v-for="(point, i) in trafficData.historical.slice(-30)" :key="i" class="flex-1 flex flex-col items-center justify-end gap-0.5 group relative">
                <div class="w-full bg-green-500 rounded-t transition-all hover:bg-green-600" :style="{ height: (point.download / maxTraffic * 100) + '%', minHeight: '4px' }"></div>
                <div class="w-full bg-purple-500 rounded-t transition-all hover:bg-purple-600" :style="{ height: (point.upload / maxTraffic * 100) + '%', minHeight: '4px' }"></div>
                <div class="absolute bottom-full mb-2 hidden group-hover:block bg-slate-800 text-white text-xs rounded px-2 py-1 whitespace-nowrap z-10">
                  {{ formatTime(point.timestamp) }}<br>
                  ↓ {{ formatSpeed(point.download) }}<br>
                  ↑ {{ formatSpeed(point.upload) }}
                </div>
              </div>
            </div>
            <div class="flex items-center justify-center gap-6 mt-4">
              <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-green-500 rounded"></div>
                <span class="text-sm text-slate-600 dark:text-slate-400">Download</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-purple-500 rounded"></div>
                <span class="text-sm text-slate-600 dark:text-slate-400">Upload</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Top Data Consumers -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
          <div class="p-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Top Consumers</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">Highest bandwidth users</p>
          </div>
          <div class="p-4">
            <div class="space-y-3">
              <div v-for="(user, index) in userBehavior.topConsumers.slice(0, 5)" :key="user.id" class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 text-xs font-semibold flex items-center justify-center">{{ index + 1 }}</span>
                  <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-xs font-semibold">
                    {{ user.username.slice(0, 2).toUpperCase() }}
                  </div>
                  <div>
                    <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.username }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatBytes(user.totalData) }} total</div>
                  </div>
                </div>
                <span class="text-sm font-bold text-blue-600">{{ formatSpeed(user.bandwidth) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Network Performance & Capacity -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Performance Metrics -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
          <div class="p-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Network Performance</h3>
          </div>
          <div class="p-4 space-y-4">
            <div>
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">Latency</span>
                <span class="text-sm font-medium" :class="performanceMetrics.latency > alertThresholds.latency ? 'text-red-600' : 'text-slate-900'">{{ performanceMetrics.latency.toFixed(0) }}ms</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full bg-cyan-500 transition-all" :style="{ width: Math.min((performanceMetrics.latency / 200) * 100, 100) + '%', backgroundColor: performanceMetrics.latency > alertThresholds.latency ? '#ef4444' : '#06b6d4' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">Packet Loss</span>
                <span class="text-sm font-medium" :class="performanceMetrics.packetLoss > alertThresholds.packetLoss ? 'text-red-600' : 'text-slate-900'">{{ performanceMetrics.packetLoss.toFixed(2) }}%</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full transition-all" :style="{ width: (performanceMetrics.packetLoss / 5) * 100 + '%', backgroundColor: performanceMetrics.packetLoss > alertThresholds.packetLoss ? '#ef4444' : '#8b5cf6' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">Jitter</span>
                <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ performanceMetrics.jitter.toFixed(1) }}ms</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full bg-amber-500 transition-all" :style="{ width: Math.min((performanceMetrics.jitter / 50) * 100, 100) + '%' }"></div>
              </div>
            </div>
            <div v-if="performanceMetrics.rssi !== null">
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">Signal Quality (RSSI)</span>
                <span class="text-sm font-medium" :class="performanceMetrics.rssi < -70 ? 'text-red-600' : performanceMetrics.rssi < -50 ? 'text-amber-600' : 'text-green-600'">{{ performanceMetrics.rssi }}dBm</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full transition-all" :style="{ width: Math.min(((100 + performanceMetrics.rssi) / 70) * 100, 100) + '%', backgroundColor: performanceMetrics.rssi < -70 ? '#ef4444' : performanceMetrics.rssi < -50 ? '#f59e0b' : '#22c55e' }"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Capacity & Utilization -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
          <div class="p-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Capacity & Utilization</h3>
          </div>
          <div class="p-4 space-y-4">
            <div>
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">Link Utilization</span>
                <span class="text-sm font-medium" :class="capacityMetrics.linkUtilization > alertThresholds.bandwidthSaturation ? 'text-red-600' : 'text-slate-900'">{{ capacityMetrics.linkUtilization.toFixed(1) }}%</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full transition-all" :style="{ width: capacityMetrics.linkUtilization + '%', backgroundColor: capacityMetrics.linkUtilization > alertThresholds.bandwidthSaturation ? '#ef4444' : '#3b82f6' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">System CPU</span>
                <span class="text-sm font-medium" :class="systemHealth.avgCpuUsage > alertThresholds.cpuUsage ? 'text-red-600' : 'text-slate-900'">{{ systemHealth.avgCpuUsage.toFixed(1) }}%</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full transition-all" :style="{ width: systemHealth.avgCpuUsage + '%', backgroundColor: systemHealth.avgCpuUsage > alertThresholds.cpuUsage ? '#ef4444' : '#10b981' }"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between mb-1">
                <span class="text-sm text-slate-600 dark:text-slate-400">System Memory</span>
                <span class="text-sm font-medium" :class="systemHealth.avgMemoryUsage > alertThresholds.memoryUsage ? 'text-red-600' : 'text-slate-900'">{{ systemHealth.avgMemoryUsage.toFixed(1) }}%</span>
              </div>
              <div class="w-full bg-slate-200 rounded-full h-2">
                <div class="h-2 rounded-full transition-all" :style="{ width: systemHealth.avgMemoryUsage + '%', backgroundColor: systemHealth.avgMemoryUsage > alertThresholds.memoryUsage ? '#ef4444' : '#8b5cf6' }"></div>
              </div>
            </div>
            <div class="pt-2 border-t border-slate-100">
              <div class="flex justify-between text-sm">
                <span class="text-slate-600">Router Status</span>
                <span class="font-medium">
                  <span class="text-green-600">{{ systemHealth.onlineRouters }} online</span>
                  <span v-if="systemHealth.offlineRouters > 0" class="text-red-600 ml-2">{{ systemHealth.offlineRouters }} offline</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- User Behavior & Revenue -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- User Behavior Metrics -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
          <div class="p-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">User Behavior</h3>
          </div>
          <div class="p-4">
            <div class="grid grid-cols-2 gap-4">
              <div class="text-center p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
                <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatDuration(userBehavior.avgSessionDuration) }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Avg Session</div>
              </div>
              <div class="text-center p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
                <div class="text-2xl font-bold" :class="userBehavior.reconnectRate > 10 ? 'text-red-600' : 'text-slate-900'">{{ userBehavior.reconnectRate.toFixed(1) }}%</div>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Reconnect Rate</div>
              </div>
              <div class="text-center p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
                <div class="text-2xl font-bold text-emerald-600">{{ userBehavior.newUsers }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">New Users</div>
              </div>
              <div class="text-center p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">
                <div class="text-2xl font-bold text-blue-600">{{ userBehavior.returningUsers }}</div>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Returning</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Revenue Metrics -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
          <div class="p-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Revenue Impact</h3>
          </div>
          <div class="p-4 space-y-4">
            <div class="flex justify-between items-center">
              <span class="text-sm text-slate-600 dark:text-slate-400">Revenue per GB</span>
              <span class="text-lg font-semibold text-slate-900 dark:text-slate-100">₱{{ revenueMetrics.revenuePerGb.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-sm text-slate-600 dark:text-slate-400">Revenue per User</span>
              <span class="text-lg font-semibold text-slate-900 dark:text-slate-100">₱{{ revenueMetrics.revenuePerUser.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between items-center pt-3 border-t border-slate-100">
              <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Total Revenue</span>
              <span class="text-xl font-bold text-purple-600">₱{{ revenueMetrics.totalRevenue.toLocaleString() }}</span>
            </div>
            <div v-if="capacityMetrics.congestedLinks.length > 0" class="mt-3 p-3 bg-amber-50 rounded-lg">
              <div class="flex items-center gap-2 text-amber-700">
                <AlertTriangle class="w-4 h-4" />
                <span class="text-sm font-medium">{{ capacityMetrics.congestedLinks.length }} congested links may impact revenue</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Alerts Table -->
      <div v-if="alerts.length > 0" class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
        <div class="p-4 border-b border-slate-200 flex justify-between items-center">
          <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Recent Alerts</h3>
          <button @click="showAlertPanel = true" class="text-sm text-blue-600 hover:text-blue-800">View All</button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-slate-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Severity</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Message</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Source</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 uppercase">Time</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-700 uppercase">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="alert in alerts.slice(0, 5)" :key="alert.id" class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium" :class="{
                    'bg-red-100 text-red-800': alert.severity === 'critical',
                    'bg-amber-100 text-amber-800': alert.severity === 'warning',
                    'bg-blue-100 text-blue-800': alert.severity === 'info'
                  }">
                    {{ alert.severity }}
                  </span>
                </td>
                <td class="px-4 py-3 text-sm text-slate-900">{{ alert.message }}</td>
                <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ alert.source }}</td>
                <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ formatTime(alert.timestamp) }}</td>
                <td class="px-4 py-3 text-right">
                  <button @click="acknowledgeAlert(alert.id)" class="text-sm text-blue-600 hover:text-blue-800">Ack</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Alert Settings Modal -->
    <SlideOverlay v-model="showAlertSettings" title="Alert Settings" subtitle="Configure monitoring thresholds" width="60%">
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Latency Threshold (ms)</label>
          <input v-model.number="alertThresholds.latency" type="number" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Packet Loss Threshold (%)</label>
          <input v-model.number="alertThresholds.packetLoss" type="number" step="0.1" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Bandwidth Saturation (%)</label>
          <input v-model.number="alertThresholds.bandwidthSaturation" type="number" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">CPU Usage Threshold (%)</label>
          <input v-model.number="alertThresholds.cpuUsage" type="number" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Memory Usage Threshold (%)</label>
          <input v-model.number="alertThresholds.memoryUsage" type="number" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
      </div>
      <template #footer>
        <div class="flex gap-3">
          <button @click="showAlertSettings = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600">Cancel</button>
          <button @click="saveThresholds" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg">Save</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Alert Panel Modal -->
    <SlideOverlay v-model="showAlertPanel" title="All Alerts" subtitle="Active and recent alerts" width="60%">
      <div class="p-6">
        <div v-if="alerts.length === 0" class="text-center py-8 text-slate-500">
          <CheckCircle class="w-12 h-12 mx-auto mb-3 text-green-500" />
          <p>No active alerts</p>
        </div>
        <div v-else class="space-y-3">
          <div v-for="alert in alerts" :key="alert.id" class="p-4 rounded-lg border" :class="{
            'bg-red-50 border-red-200': alert.severity === 'critical',
            'bg-amber-50 border-amber-200': alert.severity === 'warning',
            'bg-blue-50 border-blue-200': alert.severity === 'info'
          }">
            <div class="flex items-start justify-between">
              <div class="flex items-start gap-3">
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium mt-0.5" :class="{
                  'bg-red-100 text-red-800': alert.severity === 'critical',
                  'bg-amber-100 text-amber-800': alert.severity === 'warning',
                  'bg-blue-100 text-blue-800': alert.severity === 'info'
                }">
                  {{ alert.severity }}
                </span>
                <div>
                  <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ alert.message }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ alert.source }} • {{ formatTime(alert.timestamp) }}</p>
                </div>
              </div>
              <button @click="acknowledgeAlert(alert.id)" class="text-sm text-slate-500 hover:text-slate-700">Dismiss</button>
            </div>
          </div>
        </div>
      </div>
    </SlideOverlay>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import {
  Activity, Users, Database, DollarSign, Gauge, Server,
  Bell, Download, AlertTriangle, CheckCircle,
  TrendingUp, TrendingDown, Wifi, Clock
} from 'lucide-vue-next'
import { useTrafficMonitoring } from '@/modules/tenant/composables/useTrafficMonitoring'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  loading,
  error,
  timeRange,
  trafficData,
  usageMetrics,
  performanceMetrics,
  systemHealth,
  revenueMetrics,
  capacityMetrics,
  userBehavior,
  alerts,
  alertThresholds,
  stats,
  alertSummary,
  fetchAllMetrics,
  acknowledgeAlert,
  updateThresholds,
  formatBytes,
  formatSpeed,
  formatDuration,
  formatPercentage,
  setupWebSocketListeners,
  cleanupWebSocketListeners,
  setTimeRange
} = useTrafficMonitoring()

const showAlertSettings = ref(false)
const showAlertPanel = ref(false)
let lastFreshFetchAt = 0
const FRESH_FETCH_MIN_INTERVAL_MS = 5000

const maxTraffic = computed(() => {
  if (!trafficData.value.historical?.length) return 1
  return Math.max(...trafficData.value.historical.map(p => Math.max(p.download || 0, p.upload || 0)), 1)
})

const formatTime = (timestamp) => {
  if (!timestamp) return 'N/A'
  return new Date(timestamp).toLocaleString('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    day: 'numeric',
    month: 'short'
  })
}

const saveThresholds = async () => {
  await updateThresholds(alertThresholds.value)
  showAlertSettings.value = false
}

const exportData = () => {
  const data = {
    traffic: trafficData.value,
    usage: usageMetrics.value,
    performance: performanceMetrics.value,
    revenue: revenueMetrics.value,
    system: systemHealth.value,
    timestamp: new Date().toISOString()
  }
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `traffic-monitoring-${new Date().toISOString().slice(0, 10)}.json`
  a.click()
  URL.revokeObjectURL(url)
}

const refreshIfStale = () => {
  const nowTs = Date.now()
  if (nowTs - lastFreshFetchAt < FRESH_FETCH_MIN_INTERVAL_MS) return
  lastFreshFetchAt = nowTs
  fetchAllMetrics({ force: true }).catch(() => {})
}

const handleWindowFocus = () => {
  refreshIfStale()
}

const handleVisibilityChange = () => {
  if (document.visibilityState === 'visible') {
    refreshIfStale()
  }
}

onMounted(() => {
  refreshIfStale()
  setupWebSocketListeners()
  window.addEventListener('focus', handleWindowFocus)
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onUnmounted(() => {
  cleanupWebSocketListeners()
  window.removeEventListener('focus', handleWindowFocus)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})
</script>
