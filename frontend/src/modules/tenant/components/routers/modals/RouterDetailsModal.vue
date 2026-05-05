<template>
  <SlideOverlay
    :model-value="showDetailsOverlay"
    title="Router Details"
    :subtitle="routerDetails.name || 'Complete device information'"
    icon="Wifi"
    width="70%"
    gradient
    no-padding
    @update:model-value="val => { if (!val) $emit('close-details') }"
    @close="$emit('close-details')"
  >
      <!-- Loading State -->
      <div v-if="loading" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
        <div class="relative">
          <div class="w-12 h-12 border-[3px] border-blue-100 rounded-full"></div>
          <div
            class="w-12 h-12 border-[3px] border-t-blue-500 border-r-transparent border-b-blue-500 border-l-blue-500 rounded-full animate-spin absolute top-0"
          ></div>
        </div>
        <p class="text-slate-500 font-medium">Loading router details...</p>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
        <div class="p-3 bg-red-100 rounded-full">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-8 h-8 text-red-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
        </div>
        <p class="text-center text-slate-700 font-medium max-w-md">{{ error }}</p>
        <button
          type="button"
          @click="$emit('refresh-details')"
          class="px-5 py-2.5 text-sm font-medium text-white bg-gradient-to-r from-blue-500 to-indigo-600 rounded-lg hover:from-blue-600 hover:to-indigo-700 transition-all shadow-sm flex items-center justify-center"
          :disabled="refreshing"
        >
          <svg
            v-if="refreshing"
            xmlns="http://www.w3.org/2000/svg"
            class="h-4 w-4 mr-2 animate-spin"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          {{ refreshing ? 'Refreshing...' : 'Try Again' }}
        </button>
      </div>

      <!-- Main Content -->
      <div v-else class="flex flex-col h-full overflow-hidden bg-slate-50">
        <!-- Header strip -->
        <div class="flex-shrink-0 bg-gradient-to-r from-purple-700 to-indigo-700 px-6 py-4">
          <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white text-xl font-bold shadow-lg flex-shrink-0">
              {{ (routerDetails.name || 'R').charAt(0).toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-lg font-bold text-white truncate">{{ routerDetails.name || 'Router' }}</div>
              <div class="text-sm text-purple-200 font-mono mt-0.5">{{ routerDetails.ip_address || '—' }}</div>
              <div class="flex items-center gap-2 mt-1.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide"
                  :class="routerDetails.status === 'online' ? 'bg-emerald-500/20 text-emerald-300' : routerDetails.status === 'rebooting' ? 'bg-amber-500/20 text-amber-300' : 'bg-rose-500/20 text-rose-300'">
                  <span class="w-1.5 h-1.5 rounded-full mr-1" :class="statusClass"></span>
                  {{ statusText }}
                </span>
                <span v-if="routerDetails.model" class="text-xs text-purple-200 bg-white/10 px-2 py-0.5 rounded-full">{{ routerDetails.model }}</span>
              </div>
            </div>
            <!-- Quick stats -->
            <div class="hidden md:flex items-center gap-4 flex-shrink-0">
              <div class="text-center">
                <div class="text-lg font-bold text-white">{{ routerDetails.uptime || '—' }}</div>
                <div class="text-[10px] text-purple-300 uppercase tracking-wide">Uptime</div>
              </div>
              <div class="text-center">
                <div class="text-lg font-bold text-white">{{ (routerDetails.resources?.cpu_load || routerDetails.live_data?.cpu_load || 0) }}%</div>
                <div class="text-[10px] text-purple-300 uppercase tracking-wide">CPU</div>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <div class="flex gap-1 mt-4 bg-white/10 rounded-xl p-1">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              class="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-xs font-semibold rounded-lg transition-all relative"
              :class="activeTab === tab.id ? 'bg-white text-purple-700 shadow-sm' : 'text-purple-200 hover:text-white hover:bg-white/10'"
            >
              <svg v-if="tab.id === 'system'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
              <svg v-else-if="tab.id === 'events'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              <svg v-else-if="tab.id === 'reports'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
              <svg v-else-if="tab.id === 'users'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
              <svg v-else-if="tab.id === 'payments'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
              <svg v-else-if="tab.id === 'backups'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" /></svg>
              <svg v-else-if="tab.id === 'config'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              {{ tab.label }}
            </button>
          </div>
        </div>

        <!-- Tab Content -->
        <div class="flex-1 overflow-y-auto min-h-0 p-6">

        <!-- VPN Status Card -->
        <div v-show="activeTab === 'system'" class="mb-6 bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
          <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="h-4 w-4 mr-2 text-emerald-500"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"
              />
            </svg>
            VPN Status
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Connection</label>
              <span
                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide"
                :class="vpnStatusBadgeClass"
              >
                {{ vpnStatusText }}
              </span>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Last Handshake</label>
              <div class="flex flex-col gap-1">
                <p class="text-slate-900 text-sm">EAT: {{ handshakeEatTime }}</p>
                <p class="text-slate-500 text-xs">UTC: {{ handshakeUtcTime }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- System Information KPIs -->
        <div v-show="activeTab === 'system'" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">CPU Load</div>
            <div class="text-2xl font-bold text-slate-800">{{ (routerDetails.resources?.cpu_load ?? routerDetails.live_data?.cpu_load) !== undefined && (routerDetails.resources?.cpu_load ?? routerDetails.live_data?.cpu_load) !== null ? (routerDetails.resources?.cpu_load ?? routerDetails.live_data?.cpu_load) + '%' : '—' }}</div>
            <div class="text-xs text-slate-400 mt-1">Current usage</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Memory Usage</div>
            <div class="text-2xl font-bold text-slate-800">{{ getMemoryPercent(routerDetails.resources || routerDetails.live_data) !== null ? getMemoryPercent(routerDetails.resources || routerDetails.live_data) + '%' : '—' }}</div>
            <div class="text-xs text-slate-400 mt-1">{{ formatBytes((routerDetails.resources || routerDetails.live_data)?.total_memory) }} total</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Uptime</div>
            <div class="text-2xl font-bold text-slate-800">{{ (routerDetails.resources || routerDetails.live_data)?.uptime || routerDetails.uptime || '—' }}</div>
            <div class="text-xs text-slate-400 mt-1">Since last reboot</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Active Interfaces</div>
            <div class="text-2xl font-bold text-slate-800">{{ (routerDetails.interfaces || routerDetails.live_data?.interfaces || []).filter(i => i.running === 'true').length || ((routerDetails.resources || routerDetails.live_data)?.interface_count ?? '—') }}</div>
            <div class="text-xs text-slate-400 mt-1">of {{ (routerDetails.interfaces || routerDetails.live_data?.interfaces || []).length || ((routerDetails.resources || routerDetails.live_data)?.interface_count ?? 0) }} total</div>
          </div>
        </div>

        <!-- Router Details -->
        <div class="space-y-4">
          <!-- Basic Info Card -->
          <div v-show="activeTab === 'system'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
              Basic Information
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Router Name</label>
                <p class="text-slate-900 font-medium">{{ routerDetails.name || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Location</label>
                <p class="text-slate-900">{{ routerDetails.location || 'Not specified' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">IP Address</label>
                <p class="text-slate-900 font-mono">{{ routerDetails.ip_address || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Port</label>
                <p class="text-slate-900">{{ routerDetails.port || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Router ID</label>
                <p class="text-slate-900">{{ routerDetails.id || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Board Name</label>
                <p class="text-slate-900">{{ routerDetails.resources?.board_name || routerDetails.live_data?.board_name || 'N/A' }}</p>
              </div>
            </div>
          </div>

          <!-- Credentials Card -->
          <div v-show="activeTab === 'system'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                />
              </svg>
              Access Credentials
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Username</label>
                <p class="text-slate-900">{{ routerDetails.username || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Password</label>
                <p class="text-slate-900">••••••••</p>
              </div>
            </div>
          </div>

          <!-- Hardware Info Card -->
          <div v-show="activeTab === 'system'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"
                />
              </svg>
              Hardware Information
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Model</label>
                <p class="text-slate-900">{{ routerDetails.model || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">OS Version</label>
                <p class="text-slate-900">{{ routerDetails.os_version || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Serial Number</label>
                <p class="text-slate-900 font-mono text-sm">
                  {{ routerDetails.serial_number || 'N/A' }}
                </p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Firmware</label>
                <p class="text-slate-900">{{ routerDetails.firmware || 'N/A' }}</p>
              </div>
            </div>
          </div>

          <!-- Timestamps Card -->
          <div v-show="activeTab === 'system'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
              Timestamps
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Created</label>
                <p class="text-slate-900 text-sm">{{ formatDate(routerDetails.created_at) }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Last Seen</label>
                <p class="text-slate-900 text-sm">{{ formatDate(routerDetails.last_seen) }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Last Updated</label>
                <p class="text-slate-900 text-sm">{{ formatDate(routerDetails.updated_at) }}</p>
              </div>
            </div>
          </div>

          <!-- Live Data Section -->
          <div v-if="routerDetails.resources || routerDetails.live_data" v-show="activeTab === 'system'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
                />
              </svg>
              Live Data
            </h4>
            <div v-if="(routerDetails.resources || routerDetails.live_data)?.error" class="bg-red-50 p-4 rounded-lg">
              <p class="text-red-700 text-sm">{{ (routerDetails.resources || routerDetails.live_data).error }}</p>
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-blue-600 mb-1">CPU Load</label>
                <p class="text-blue-800 font-bold text-lg">
                  {{ (routerDetails.resources || routerDetails.live_data)?.cpu_load ?? 'N/A' }}%
                </p>
              </div>
              <div class="bg-gradient-to-br from-green-50 to-green-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-green-600 mb-1">Memory</label>
                <p class="text-green-800 font-bold text-sm">
                  {{ formatBytes((routerDetails.resources || routerDetails.live_data)?.free_memory) }} /
                  {{ formatBytes((routerDetails.resources || routerDetails.live_data)?.total_memory) }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-purple-600 mb-1">Uptime</label>
                <p class="text-purple-800 font-bold text-sm">
                  {{ (routerDetails.resources || routerDetails.live_data)?.uptime || 'N/A' }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-amber-50 to-amber-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-amber-600 mb-1">Interfaces</label>
                <p class="text-amber-800 font-bold text-lg">
                  {{ (routerDetails.resources || routerDetails.live_data)?.interface_count ?? ((routerDetails.interfaces || routerDetails.live_data?.interfaces) ? (routerDetails.interfaces || routerDetails.live_data?.interfaces).length : 0) }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-cyan-600 mb-1"
                  >Active Connections</label
                >
                <p class="text-cyan-800 font-bold text-lg">
                  {{ routerDetails.active_connections ?? (routerDetails.resources || routerDetails.live_data)?.active_connections ?? 0 }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-pink-50 to-pink-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-pink-600 mb-1">DHCP Leases</label>
                <p class="text-pink-800 font-bold text-lg">
                  {{ (routerDetails.resources || routerDetails.live_data)?.dhcp_leases ?? 0 }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-teal-50 to-teal-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-teal-600 mb-1">Storage</label>
                <p class="text-teal-800 font-bold text-sm">
                  {{ formatBytes(getDiskUsed(routerDetails.resources || routerDetails.live_data)) }} /
                  {{ formatBytes((routerDetails.resources || routerDetails.live_data)?.total_hdd_space) }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-indigo-600 mb-1">Identity</label>
                <p class="text-indigo-800 font-bold text-sm">
                  {{ (routerDetails.resources || routerDetails.live_data)?.identity || 'N/A' }}
                </p>
              </div>
            </div>
          </div>

        <!-- Configurations Tab KPIs -->
        <div v-show="activeTab === 'config'" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Services</div>
            <div class="text-2xl font-bold text-slate-800">{{ groupedServices?.length || 0 }}</div>
            <div class="text-xs text-slate-400 mt-1">{{ groupedServices?.filter(s => s.status === 'active').length || 0 }} active</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Access Points</div>
            <div class="text-2xl font-bold text-slate-800">{{ routerDetails.access_points?.length || 0 }}</div>
            <div class="text-xs text-slate-400 mt-1">{{ routerDetails.access_points?.filter(a => a.status === 'online').length || 0 }} online</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">Interfaces</div>
            <div class="text-2xl font-bold text-slate-800">{{ (routerDetails.interfaces || routerDetails.live_data?.interfaces || []).length || ((routerDetails.resources || routerDetails.live_data)?.interface_count ?? '—') }}</div>
            <div class="text-xs text-slate-400 mt-1">{{ (routerDetails.interfaces || routerDetails.live_data?.interfaces || []).filter(i => i.running === 'true').length }} running</div>
          </div>
          <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
            <div class="text-xs text-slate-500 mb-1">DHCP Leases</div>
            <div class="text-2xl font-bold text-slate-800">{{ (routerDetails.resources || routerDetails.live_data)?.dhcp_leases ?? '—' }}</div>
            <div class="text-xs text-slate-400 mt-1">Active leases</div>
          </div>
        </div>

          <!-- Interfaces Section -->
          <div
            v-if="(routerDetails.interfaces || routerDetails.live_data?.interfaces) && (routerDetails.interfaces || routerDetails.live_data?.interfaces).length"
            v-show="activeTab === 'config'"
            class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm"
          >
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                />
              </svg>
              Network Interfaces
            </h4>
            <div class="space-y-3">
              <div
                v-for="(iface, index) in (routerDetails.interfaces || routerDetails.live_data?.interfaces || [])"
                :key="index"
                class="p-3 bg-slate-50 rounded-lg border border-slate-200"
              >
                <div class="flex justify-between items-center">
                  <span class="font-medium text-slate-800">{{ iface.name }}</span>
                  <span
                    :class="
                      iface.running === 'true'
                        ? 'bg-green-100 text-green-800'
                        : 'bg-red-100 text-red-800'
                    "
                    class="text-xs px-2 py-1 rounded-full"
                  >
                    {{ iface.running === 'true' ? 'Running' : 'Stopped' }}
                  </span>
                </div>
                <div class="grid grid-cols-2 gap-2 mt-2 text-xs">
                  <div>
                    <span class="text-slate-500">Type:</span>
                    <span class="ml-1 font-medium">{{ iface.type }}</span>
                  </div>
                  <div>
                    <span class="text-slate-500">MTU:</span>
                    <span class="ml-1 font-medium">{{ iface.mtu }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Configured Services Section -->
          <div
            v-if="groupedServices && groupedServices.length"
            v-show="activeTab === 'config'"
            class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm"
          >
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 12h6m-6 4h6M9 8h.01M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                />
              </svg>
              Configured Services
            </h4>
            <div class="space-y-3">
              <div
                v-for="service in groupedServices"
                :key="service.id"
                class="p-3 bg-slate-50 rounded-lg border border-slate-200 flex flex-col gap-1.5"
              >
                <div class="flex items-center justify-between">
                  <div class="flex flex-col">
                    <span class="text-sm font-semibold text-slate-800">
                      {{ service.service_name || (service.service_type || '').toUpperCase() || 'Service' }}
                    </span>
                    <span class="text-xs text-slate-500">
                      {{ service.service_type || 'unknown' }}
                    </span>
                  </div>
                  <span
                    class="text-[11px] px-2 py-0.5 rounded-full font-medium"
                    :class="{
                      'bg-green-100 text-green-800': service.status === 'active',
                      'bg-yellow-100 text-yellow-800': service.status === 'pending' || service.status === 'starting',
                      'bg-red-100 text-red-800': service.status === 'error',
                      'bg-slate-100 text-slate-700': !service.status || service.status === 'inactive',
                    }"
                  >
                    {{ service.status || 'inactive' }}
                  </span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-[11px] text-slate-600 mt-1">
                  <div>
                    <span class="font-medium text-slate-500">Interfaces:</span>
                    <span class="ml-1">
                      {{ ((service.interfaces && service.interfaces.length ? service.interfaces : (service.interface_name ? [service.interface_name] : [])) || []).join(', ') || 'N/A' }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-slate-500">VLAN:</span>
                    <span class="ml-1">
                      {{ service.vlan_id || (service.vlan_required ? 'Required' : 'N/A') }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-slate-500">Active Users:</span>
                    <span class="ml-1">
                      {{ service.active_users ?? 0 }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-slate-500">Total Sessions:</span>
                    <span class="ml-1">
                      {{ service.total_sessions ?? 0 }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-slate-500">Deployment:</span>
                    <span class="ml-1">
                      {{ service.deployment_status || 'unknown' }}
                    </span>
                  </div>
                  <div v-if="service.last_checked_at">
                    <span class="font-medium text-slate-500">Last Check:</span>
                    <span class="ml-1">
                      {{ formatDate(service.last_checked_at) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Connected Access Points Section -->
          <div
            v-if="routerDetails.access_points && routerDetails.access_points.length"
            v-show="activeTab === 'config'"
            class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm"
          >
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"
                />
              </svg>
              Connected Access Points
            </h4>
            <div class="space-y-3">
              <div
                v-for="ap in routerDetails.access_points"
                :key="ap.id"
                class="p-3 bg-slate-50 rounded-lg border border-slate-200 flex items-center justify-between gap-3"
              >
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-7 h-7 bg-blue-100 rounded-md flex items-center justify-center text-blue-600 text-xs font-semibold">
                    {{ (ap.name || 'AP').charAt(0).toUpperCase() }}
                  </div>
                  <div class="min-w-0">
                    <div class="text-sm font-medium text-slate-900 truncate">
                      {{ ap.name || 'Access Point' }}
                    </div>
                    <div class="text-[11px] text-slate-500 truncate">
                      {{ ap.ip_address || 'No IP' }}
                    </div>
                    <div class="text-[11px] text-slate-400 truncate">
                      {{ ap.location || 'No location' }}
                    </div>
                  </div>
                </div>
                <div class="flex flex-col items-end text-[11px] gap-0.5">
                  <span
                    class="px-2 py-0.5 rounded-full font-medium capitalize"
                    :class="{
                      'bg-green-100 text-green-800': ap.status === 'online',
                      'bg-red-100 text-red-800': ap.status === 'offline',
                      'bg-yellow-100 text-yellow-800': ap.status === 'unknown',
                      'bg-slate-100 text-slate-700': !ap.status,
                    }"
                  >
                    {{ ap.status || 'unknown' }}
                  </span>
                  <span class="text-slate-500">
                    {{ ap.vendor || 'Vendor' }}
                    <span v-if="ap.model">· {{ ap.model }}</span>
                  </span>
                  <span v-if="ap.serial_number" class="text-slate-400 font-mono">
                    {{ ap.serial_number }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Configuration Token -->
          <div v-if="routerDetails.config_token" v-show="activeTab === 'config'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 mr-2 text-blue-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
                />
              </svg>
              Configuration Token
            </h4>
            <div class="relative">
              <pre
                class="text-xs font-mono text-slate-800 bg-slate-50 p-3 rounded-lg overflow-x-auto border border-slate-200"
                >{{ routerDetails.config_token }}</pre
              >
              <button
                type="button"
                @click="copyToClipboard(routerDetails.config_token)"
                class="absolute top-2 right-2 p-1.5 bg-white rounded-lg shadow-sm text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                title="Copy to clipboard"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"
                  />
                </svg>
              </button>
            </div>
            <p class="text-xs text-slate-500 mt-2">
              Use this token to apply configurations on the router
            </p>
          </div>

        <!-- Reports Tab KPIs -->
        <div v-show="activeTab === 'reports'" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div class="bg-gradient-to-br from-green-50 to-emerald-50 p-4 rounded-xl border border-green-200 shadow-sm">
            <div class="text-xs text-green-600 mb-1">Current Download</div>
            <div class="text-2xl font-bold text-green-900">{{ formatBytes(trafficStats.download) }}/s</div>
            <div class="text-xs text-green-500 mt-1">Live throughput</div>
          </div>
          <div class="bg-gradient-to-br from-purple-50 to-indigo-50 p-4 rounded-xl border border-purple-200 shadow-sm">
            <div class="text-xs text-purple-600 mb-1">Current Upload</div>
            <div class="text-2xl font-bold text-purple-900">{{ formatBytes(trafficStats.upload) }}/s</div>
            <div class="text-xs text-purple-500 mt-1">Live throughput</div>
          </div>
          <div class="bg-gradient-to-br from-blue-50 to-indigo-50 p-4 rounded-xl border border-blue-200 shadow-sm">
            <div class="text-xs text-blue-600 mb-1">Avg CPU</div>
            <div class="text-2xl font-bold text-blue-900">{{ formatPercent(resourceStats.cpu.avg) }}</div>
            <div class="text-xs text-blue-500 mt-1">{{ formatPercent(resourceStats.cpu.current) }} current</div>
          </div>
          <div class="bg-gradient-to-br from-amber-50 to-orange-50 p-4 rounded-xl border border-amber-200 shadow-sm">
            <div class="text-xs text-amber-600 mb-1">Avg Memory</div>
            <div class="text-2xl font-bold text-amber-900">{{ formatPercent(resourceStats.memory.avg) }}</div>
            <div class="text-xs text-amber-500 mt-1">{{ formatPercent(resourceStats.memory.current) }} current</div>
          </div>
        </div>

          <div v-show="activeTab === 'reports'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mt-4">
            <div class="flex items-center justify-between mb-4">
              <h4 class="text-sm font-semibold text-slate-700 flex items-center">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4 mr-2 text-blue-500"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 19v-6m4 6V5m4 14v-9m4 9V9m4 10V7"
                  />
                </svg>
                Traffic
              </h4>
              <div class="flex items-center gap-3">
                 <!-- Time Range Selector -->
                 <select
                    v-model="trafficTimeRange"
                    class="text-xs border-slate-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
                    @change="loadTraffic()"
                  >
                    <option v-for="r in timeRanges" :key="r.value" :value="r.value">
                      {{ r.label }}
                    </option>
                  </select>
                 <div v-if="hoveredData && !['pie', 'donut', 'histogram'].includes(selectedChartType)" class="hidden md:flex items-center gap-3 text-xs bg-slate-50 px-2 py-1 rounded border border-slate-100">
                    <span class="font-mono text-slate-500">{{ formatTime(hoveredData.ts) }}</span>
                    <span class="flex items-center gap-1">
                      <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                      <span class="font-medium text-slate-700">DL: {{ formatBytes(hoveredData.download) }}/s</span>
                    </span>
                    <span class="flex items-center gap-1">
                      <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                      <span class="font-medium text-slate-700">UL: {{ formatBytes(hoveredData.upload) }}/s</span>
                    </span>
                 </div>
                 <select
                    v-model="selectedChartType"
                    class="text-xs border-slate-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
                  >
                    <option v-for="t in chartTypes" :key="t.value" :value="t.value">
                      {{ t.label }}
                    </option>
                  </select>
                 <!-- Fullscreen Button -->
                 <button
                    @click="toggleTrafficFullscreen"
                    class="p-1.5 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-md transition-colors"
                    title="Toggle fullscreen"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path v-if="!trafficFullscreen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                      <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
              </div>
            </div>

            <div v-if="trafficError" class="bg-red-50 p-3 rounded-lg border border-red-200">
              <p class="text-red-700 text-xs">{{ trafficError }}</p>
            </div>

            <div v-else>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-3 border border-blue-200">
                  <div class="text-[11px] text-blue-600 font-medium mb-1">Current</div>
                  <div class="text-base font-bold text-blue-900">{{ formatBytes(trafficStats.current) }}/s</div>
                </div>
                <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-3 border border-green-200">
                  <div class="text-[11px] text-green-600 font-medium mb-1">Download</div>
                  <div class="text-base font-bold text-green-900">{{ formatBytes(trafficStats.download) }}/s</div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-3 border border-purple-200">
                  <div class="text-[11px] text-purple-600 font-medium mb-1">Upload</div>
                  <div class="text-base font-bold text-purple-900">{{ formatBytes(trafficStats.upload) }}/s</div>
                </div>
              </div>

              <div v-if="trafficLoading" class="text-xs text-slate-500">Loading traffic…</div>
              <div v-else 
                   class="bg-white rounded-lg border border-slate-100 p-4 transition-all duration-300 flex flex-col" 
                   :class="{ 'fixed inset-0 z-[9999] h-screen w-screen bg-white p-6': trafficFullscreen, 'relative': !trafficFullscreen }"
                   :style="trafficFullscreen ? {} : { height: '300px' }">
                <!-- Fullscreen header -->
                <div v-if="trafficFullscreen" class="flex items-center justify-between mb-4 flex-shrink-0">
                  <h3 class="text-lg font-semibold text-slate-800">Traffic - Fullscreen</h3>
                  <button @click="toggleTrafficFullscreen" class="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <!-- Fullscreen Stats for Traffic - Moved to top -->
                <div v-if="trafficFullscreen" class="grid grid-cols-3 gap-3 mb-4 flex-shrink-0 px-4">
                  <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-3 border border-blue-200">
                    <div class="text-[11px] text-blue-600 font-medium mb-1">Current</div>
                    <div class="text-base font-bold text-blue-900">{{ formatBytes(trafficStats.current) }}/s</div>
                  </div>
                  <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-3 border border-green-200">
                    <div class="text-[11px] text-green-600 font-medium mb-1">Download</div>
                    <div class="text-base font-bold text-green-900">{{ formatBytes(trafficStats.download) }}/s</div>
                  </div>
                  <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-3 border border-purple-200">
                    <div class="text-[11px] text-purple-600 font-medium mb-1">Upload</div>
                    <div class="text-base font-bold text-purple-900">{{ formatBytes(trafficStats.upload) }}/s</div>
                  </div>
                </div>

                <!-- Fullscreen Legend for Traffic - Moved under stats -->
                <div v-if="trafficFullscreen" class="flex items-center justify-center gap-8 mb-4 flex-shrink-0">
                  <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-green-500 rounded-full"></div>
                    <span class="text-sm text-slate-700 font-medium">Download</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-purple-500 rounded-full"></div>
                    <span class="text-sm text-slate-700 font-medium">Upload</span>
                  </div>
                </div>
                <div class="flex flex-1 min-h-0">
                  <!-- Y-Axis -->
                  <div v-if="yAxisTicks.length" class="relative h-full w-14 mr-2 border-r border-slate-100">
                    <div v-for="tick in yAxisTicks" :key="tick.value" 
                         class="absolute right-1 text-[10px] text-black font-medium transform translate-y-1/2"
                         :style="{ bottom: tick.percent + '%' }">
                      {{ tick.label }}
                    </div>
                  </div>
                  
                  <!-- Graph -->
                  <div class="relative flex-1 flex flex-col">
                    <div 
                      class="relative flex-1 overflow-hidden cursor-crosshair"
                      @mousemove="handleGraphHover"
                      @mouseleave="handleGraphLeave"
                      ref="graphContainer"
                    >
                       <!-- Grid Lines -->
                       <div v-for="tick in yAxisTicks" :key="tick.value" 
                            class="absolute w-full border-t border-slate-100"
                            :style="{ bottom: tick.percent + '%' }">
                       </div>

                       <!-- No Data Message -->
                       <div v-if="!chartRenderData && !trafficLoading" class="absolute inset-0 flex items-center justify-center">
                          <div class="text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-slate-300 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <p class="text-slate-500 text-sm">No historical data available</p>
                            <p class="text-slate-400 text-xs mt-1">Traffic metrics may not be collected</p>
                          </div>
                       </div>

                       <!-- SVG Chart -->
                       <svg v-if="chartRenderData" class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none">
                          <defs>
                            <linearGradient id="gradDownload" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#22c55e" stop-opacity="0.2"/>
                              <stop offset="100%" stop-color="#22c55e" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="gradUpload" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#a855f7" stop-opacity="0.2"/>
                              <stop offset="100%" stop-color="#a855f7" stop-opacity="0"/>
                            </linearGradient>
                          </defs>
                          
                          <!-- Line / Area / Stacked -->
                          <template v-if="['line', 'area', 'stacked'].includes(chartRenderData.type)">
                            <template v-if="chartRenderData.type !== 'line'">
                                <path :d="chartRenderData.paths.download" fill="url(#gradDownload)" stroke="none" />
                                <path :d="chartRenderData.paths.upload" fill="url(#gradUpload)" stroke="none" />
                            </template>
                            
                            <path :d="chartRenderData.paths.download" fill="none" stroke="#22c55e" stroke-width="2" vector-effect="non-scaling-stroke" />
                            <path :d="chartRenderData.paths.upload" fill="none" stroke="#a855f7" stroke-width="2" vector-effect="non-scaling-stroke" />
                          </template>

                          <!-- Bar / Histogram -->
                          <template v-else-if="['bar', 'histogram'].includes(chartRenderData.type)">
                             <g v-for="(bar, i) in chartRenderData.bars" :key="i">
                               <rect :x="bar.x - bar.w/2" :y="bar.yD" :width="bar.w" :height="bar.hD" fill="#22c55e" opacity="0.6" />
                               <rect :x="bar.x - bar.w/2" :y="bar.yU" :width="bar.w" :height="bar.hU" fill="#a855f7" opacity="0.6" />
                             </g>
                          </template>

                          <!-- Pie / Donut -->
                          <template v-else-if="['pie', 'donut'].includes(chartRenderData.type)">
                              <!-- Use a different viewBox or transform for pie to center it -->
                              <!-- Since viewBox is 0 0 1000 200, center is 500, 100. radius ~90 -->
                              <path :d="chartRenderData.paths.download" fill="#22c55e" />
                              <path :d="chartRenderData.paths.upload" fill="#a855f7" />
                              <circle v-if="chartRenderData.type === 'donut'" :cx="chartRenderData.cx" :cy="chartRenderData.cy" :r="chartRenderData.r * 0.6" fill="white" />
                              
                              <!-- Labels -->
                              <text :x="chartRenderData.cx - chartRenderData.r - 20" :y="chartRenderData.cy" text-anchor="end" fill="#22c55e" font-size="12">
                                  DL: {{ formatBytes(chartRenderData.totals.download) }}
                              </text>
                              <text :x="chartRenderData.cx + chartRenderData.r + 20" :y="chartRenderData.cy" text-anchor="start" fill="#a855f7" font-size="12">
                                  UL: {{ formatBytes(chartRenderData.totals.upload) }}
                              </text>
                          </template>

                       </svg>

                       <!-- Hover Line -->
                       <div v-if="hoveredIndex >= 0 && !['pie', 'donut', 'histogram'].includes(selectedChartType)" 
                            class="absolute top-0 bottom-0 border-l border-slate-400 border-dashed pointer-events-none z-10"
                            :style="{ left: hoverX + '%' }">
                          <!-- Dot indicators -->
                          <div class="absolute w-2 h-2 bg-green-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (hoveredData.download / trafficMax * 100) + '%' }"></div>
                          <div class="absolute w-2 h-2 bg-purple-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (hoveredData.upload / trafficMax * 100) + '%' }"></div>
                          
                          <!-- Floating Tooltip -->
                          <div class="absolute top-0 left-2 bg-white/90 backdrop-blur-sm shadow-lg border border-slate-200 rounded p-2 text-xs whitespace-nowrap z-20 pointer-events-none"
                               :class="{ '-translate-x-full -left-2': hoverX > 80 }">
                             <div class="font-mono text-slate-500 mb-1">{{ formatTime(hoveredData.ts) }}</div>
                             <div class="flex items-center gap-2 mb-0.5">
                               <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                               <span class="font-medium text-slate-700">DL: {{ formatBytes(hoveredData.download) }}/s</span>
                             </div>
                             <div class="flex items-center gap-2">
                               <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                               <span class="font-medium text-slate-700">UL: {{ formatBytes(hoveredData.upload) }}/s</span>
                             </div>
                          </div>
                       </div>
                    </div>
                    
                    <!-- X-Axis - Always visible with proper spacing -->
                    <div v-if="xAxisTicks.length" class="h-8 relative mt-2 flex-shrink-0 border-t border-slate-200 pt-1">
                       <div v-for="tick in xAxisTicks" :key="tick.x" 
                            class="absolute text-[11px] text-black font-medium transform -translate-x-1/2 whitespace-nowrap"
                            :style="{ left: tick.x + '%' }">
                         {{ tick.label }}
                       </div>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="!trafficFullscreen" class="flex items-center justify-center gap-6 mt-3">
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                  <span class="text-xs text-slate-600">Download</span>
                </div>
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-purple-500 rounded-full"></div>
                  <span class="text-xs text-slate-600">Upload</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Resource Utilization Section -->
          <div v-show="activeTab === 'reports'" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm mt-4">
            <div class="flex items-center justify-between mb-4">
              <h4 class="text-sm font-semibold text-slate-700 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
                Resource Utilization
              </h4>
              <div class="flex items-center gap-3">
                 <!-- Time Range Selector -->
                 <select
                    v-model="resourceTimeRange"
                    class="text-xs border-slate-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
                    @change="loadResources()"
                  >
                    <option v-for="r in timeRanges" :key="r.value" :value="r.value">
                      {{ r.label }}
                    </option>
                  </select>
                 <!-- Chart Type Selector -->
                 <select
                    v-model="selectedResourceChart"
                    class="text-xs border-slate-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
                  >
                    <option value="line">Line</option>
                    <option value="area">Area</option>
                  </select>
                 <!-- Fullscreen Button -->
                 <button
                    @click="toggleResourceFullscreen"
                    class="p-1.5 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-md transition-colors"
                    title="Toggle fullscreen"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path v-if="!resourceFullscreen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                      <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
              </div>
            </div>

            <div v-if="resourceError" class="bg-red-50 p-3 rounded-lg border border-red-200">
              <p class="text-red-700 text-xs">{{ resourceError }}</p>
            </div>

            <div v-else>
              <!-- Resource Stats Cards -->
              <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-3 border border-blue-200">
                  <div class="flex items-center gap-2 mb-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span class="text-[11px] text-blue-600 font-medium">CPU</span>
                  </div>
                  <div class="text-base font-bold text-blue-900">
                    <span v-if="resourceLoading && resourceData.cpu.length === 0">--</span>
                    <span v-else>{{ formatPercent(resourceStats.cpu.current) }}</span>
                  </div>
                  <div class="text-[10px] text-blue-600">
                    <span v-if="resourceLoading && resourceData.cpu.length === 0">Loading...</span>
                    <span v-else>Avg: {{ formatPercent(resourceStats.cpu.avg) }} | Max: {{ formatPercent(resourceStats.cpu.max) }}</span>
                  </div>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-lg p-3 border border-purple-200">
                  <div class="flex items-center gap-2 mb-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <span class="text-[11px] text-purple-600 font-medium">Memory</span>
                  </div>
                  <div class="text-base font-bold text-purple-900">
                    <span v-if="resourceLoading && resourceData.memory.length === 0">--</span>
                    <span v-else>{{ formatPercent(resourceStats.memory.current) }}</span>
                  </div>
                  <div class="text-[10px] text-purple-600">
                    <span v-if="resourceLoading && resourceData.memory.length === 0">Loading...</span>
                    <span v-else>Avg: {{ formatPercent(resourceStats.memory.avg) }} | Max: {{ formatPercent(resourceStats.memory.max) }}</span>
                  </div>
                </div>
                <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-lg p-3 border border-amber-200">
                  <div class="flex items-center gap-2 mb-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                    </svg>
                    <span class="text-[11px] text-amber-600 font-medium">Disk</span>
                  </div>
                  <div class="text-base font-bold text-amber-900">
                    <span v-if="resourceLoading && resourceData.disk.length === 0">--</span>
                    <span v-else>{{ formatPercent(resourceStats.disk.current) }}</span>
                  </div>
                  <div class="text-[10px] text-amber-600">
                    <span v-if="resourceLoading && resourceData.disk.length === 0">Loading...</span>
                    <span v-else>Avg: {{ formatPercent(resourceStats.disk.avg) }} | Max: {{ formatPercent(resourceStats.disk.max) }}</span>
                  </div>
                </div>
              </div>

              <div v-if="resourceLoading" class="text-xs text-slate-500">Loading resources…</div>
              <div v-else 
                   class="bg-white rounded-lg border border-slate-100 p-4 transition-all duration-300 flex flex-col" 
                   :class="{ 'fixed inset-0 z-[9999] h-screen w-screen bg-white p-6': resourceFullscreen, 'relative': !resourceFullscreen }"
                   :style="resourceFullscreen ? {} : { height: '250px' }">
                <!-- Fullscreen header -->
                <div v-if="resourceFullscreen" class="flex items-center justify-between mb-4 flex-shrink-0">
                  <h3 class="text-lg font-semibold text-slate-800">Resource Utilization - Fullscreen</h3>
                  <button @click="toggleResourceFullscreen" class="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <!-- Fullscreen Stats for Resource - Moved to top -->
                <div v-if="resourceFullscreen" class="grid grid-cols-3 gap-3 mb-4 flex-shrink-0 px-4">
                  <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-3 border border-blue-200">
                    <div class="flex items-center gap-2 mb-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                      </svg>
                      <span class="text-[11px] text-blue-600 font-medium">CPU</span>
                    </div>
                    <div class="text-base font-bold text-blue-900">{{ formatPercent(resourceStats.cpu.current) }}</div>
                    <div class="text-[10px] text-blue-600">Avg: {{ formatPercent(resourceStats.cpu.avg) }} | Max: {{ formatPercent(resourceStats.cpu.max) }}</div>
                  </div>
                  <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-lg p-3 border border-purple-200">
                    <div class="flex items-center gap-2 mb-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                      </svg>
                      <span class="text-[11px] text-purple-600 font-medium">Memory</span>
                    </div>
                    <div class="text-base font-bold text-purple-900">{{ formatPercent(resourceStats.memory.current) }}</div>
                    <div class="text-[10px] text-purple-600">Avg: {{ formatPercent(resourceStats.memory.avg) }} | Max: {{ formatPercent(resourceStats.memory.max) }}</div>
                  </div>
                  <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-lg p-3 border border-amber-200">
                    <div class="flex items-center gap-2 mb-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                      </svg>
                      <span class="text-[11px] text-amber-600 font-medium">Disk</span>
                    </div>
                    <div class="text-base font-bold text-amber-900">{{ formatPercent(resourceStats.disk.current) }}</div>
                    <div class="text-[10px] text-amber-600">Avg: {{ formatPercent(resourceStats.disk.avg) }} | Max: {{ formatPercent(resourceStats.disk.max) }}</div>
                  </div>
                </div>

                <!-- Fullscreen Legend for Resource - Moved under stats -->
                <div v-if="resourceFullscreen" class="flex items-center justify-center gap-8 mb-4 flex-shrink-0">
                  <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-blue-500 rounded-full"></div>
                    <span class="text-sm text-slate-700 font-medium">CPU</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-purple-500 rounded-full"></div>
                    <span class="text-sm text-slate-700 font-medium">Memory</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-amber-500 rounded-full"></div>
                    <span class="text-sm text-slate-700 font-medium">Disk</span>
                  </div>
                </div>
                <div class="flex flex-1 min-h-0">
                  <!-- Y-Axis -->
                  <div class="relative h-full w-12 mr-2 border-r border-slate-200">
                    <div v-for="tick in resourceYAxisTicks" :key="tick.value" 
                         class="absolute right-1 text-[10px] text-black font-medium transform translate-y-1/2"
                         :style="{ bottom: tick.percent + '%' }">
                      {{ tick.label }}
                    </div>
                  </div>
                  
                  <!-- Graph -->
                  <div class="relative flex-1 flex flex-col">
                    <div 
                      class="relative flex-1 overflow-hidden cursor-crosshair"
                      @mousemove="handleResourceGraphHover"
                      @mouseleave="handleResourceGraphLeave"
                      ref="resourceGraphContainer"
                    >
                       <!-- Grid Lines -->
                       <div v-for="tick in resourceYAxisTicks" :key="tick.value" 
                            class="absolute w-full border-t border-slate-100"
                            :style="{ bottom: tick.percent + '%' }">
                       </div>

                       <!-- No Data Message -->
                       <div v-if="!resourceChartRenderData && !resourceLoading" class="absolute inset-0 flex items-center justify-center">
                          <div class="text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-slate-300 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <p class="text-slate-500 text-sm">No historical data available</p>
                            <p class="text-slate-400 text-xs mt-1">Metrics collection may not be configured</p>
                          </div>
                       </div>

                       <!-- SVG Chart -->
                       <svg v-if="resourceChartRenderData" class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none">
                          <defs>
                            <linearGradient id="gradCpu" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.2"/>
                              <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="gradMem" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#a855f7" stop-opacity="0.2"/>
                              <stop offset="100%" stop-color="#a855f7" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="gradDisk" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.2"/>
                              <stop offset="100%" stop-color="#f59e0b" stop-opacity="0"/>
                            </linearGradient>
                          </defs>
                          
                          <!-- Area fills (only for area chart type) -->
                          <template v-if="selectedResourceChart === 'area'">
                            <path :d="resourceChartRenderData.paths.cpuArea" fill="url(#gradCpu)" />
                            <path :d="resourceChartRenderData.paths.memArea" fill="url(#gradMem)" />
                            <path :d="resourceChartRenderData.paths.diskArea" fill="url(#gradDisk)" />
                          </template>
                          
                          <!-- Lines -->
                          <path :d="resourceChartRenderData.paths.cpu" fill="none" stroke="#3b82f6" stroke-width="2" vector-effect="non-scaling-stroke" />
                          <path :d="resourceChartRenderData.paths.mem" fill="none" stroke="#a855f7" stroke-width="2" vector-effect="non-scaling-stroke" />
                          <path :d="resourceChartRenderData.paths.disk" fill="none" stroke="#f59e0b" stroke-width="2" vector-effect="non-scaling-stroke" />
                       </svg>

                       <!-- Hover Line -->
                       <div v-if="resourceHoveredIndex >= 0" 
                            class="absolute top-0 bottom-0 border-l border-slate-400 border-dashed pointer-events-none z-10"
                            :style="{ left: resourceHoverX + '%' }">
                          <!-- Dot indicators -->
                          <div class="absolute w-2 h-2 bg-blue-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (resourceHoveredData.cpu / 100 * 100) + '%' }"></div>
                          <div class="absolute w-2 h-2 bg-purple-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (resourceHoveredData.memory / 100 * 100) + '%' }"></div>
                          <div class="absolute w-2 h-2 bg-amber-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (resourceHoveredData.disk / 100 * 100) + '%' }"></div>
                          
                          <!-- Floating Tooltip -->
                          <div class="absolute top-0 left-2 bg-white/90 backdrop-blur-sm shadow-lg border border-slate-200 rounded p-2 text-xs whitespace-nowrap z-20 pointer-events-none"
                               :class="{ '-translate-x-full -left-2': resourceHoverX > 80 }">
                             <div class="font-mono text-slate-500 mb-1">{{ formatTime(resourceHoveredData.ts) }}</div>
                             <div class="flex items-center gap-2 mb-0.5">
                               <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                               <span class="font-medium text-slate-700">CPU: {{ formatPercent(resourceHoveredData.cpu) }}</span>
                             </div>
                             <div class="flex items-center gap-2 mb-0.5">
                               <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                               <span class="font-medium text-slate-700">Mem: {{ formatPercent(resourceHoveredData.memory) }}</span>
                             </div>
                             <div class="flex items-center gap-2">
                               <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                               <span class="font-medium text-slate-700">Disk: {{ formatPercent(resourceHoveredData.disk) }}</span>
                             </div>
                          </div>
                       </div>
                    </div>
                    
                    <!-- X-Axis - Always visible with proper spacing -->
                    <div v-if="resourceXAxisTicks.length" class="h-8 relative mt-2 flex-shrink-0 border-t border-slate-200 pt-1">
                       <div v-for="tick in resourceXAxisTicks" :key="tick.x" 
                            class="absolute text-[11px] text-black font-medium transform -translate-x-1/2 whitespace-nowrap"
                            :style="{ left: tick.x + '%' }">
                         {{ tick.label }}
                       </div>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="!resourceFullscreen" class="flex items-center justify-center gap-6 mt-3">
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                  <span class="text-xs text-slate-600">CPU</span>
                </div>
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-purple-500 rounded-full"></div>
                  <span class="text-xs text-slate-600">Memory</span>
                </div>
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-amber-500 rounded-full"></div>
                  <span class="text-xs text-slate-600">Disk</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Device Events Tab -->
        <div v-show="activeTab === 'events'" class="space-y-4">
          <!-- Events KPIs -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Total Events</div>
              <div class="text-2xl font-bold text-slate-800">{{ eventsLoading ? '…' : (eventsSummary.total ?? '—') }}</div>
              <div class="text-xs text-slate-400 mt-1">Last 30 days</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Errors</div>
              <div class="text-2xl font-bold text-red-600">{{ eventsLoading ? '…' : ((eventsSummary.critical ?? 0) + (eventsSummary.error ?? 0)) }}</div>
              <div class="text-xs text-slate-400 mt-1">Critical + error</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Warnings</div>
              <div class="text-2xl font-bold text-amber-600">{{ eventsLoading ? '…' : (eventsSummary.warning ?? '—') }}</div>
              <div class="text-xs text-slate-400 mt-1">Last 30 days</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Last 24h</div>
              <div class="text-2xl font-bold text-blue-600">{{ eventsLoading ? '…' : (eventsSummary.last_24h ?? '—') }}</div>
              <div class="text-xs text-slate-400 mt-1">Recent activity</div>
            </div>
          </div>

          <!-- Filter bar -->
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold text-slate-600">Filter:</span>
            <button
              v-for="lv in ['all', 'critical', 'error', 'warning', 'info']"
              :key="lv"
              @click="eventsLevelFilter = lv; fetchRouterEvents()"
              class="px-2.5 py-1 rounded-full text-xs font-semibold transition-all"
              :class="eventsLevelFilter === lv
                ? (lv === 'critical' ? 'bg-red-600 text-white' : lv === 'error' ? 'bg-rose-500 text-white' : lv === 'warning' ? 'bg-amber-500 text-white' : lv === 'info' ? 'bg-blue-500 text-white' : 'bg-slate-700 text-white')
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
            >{{ lv.charAt(0).toUpperCase() + lv.slice(1) }}</button>
            <button @click="fetchRouterEvents()" class="ml-auto flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" :class="{'animate-spin': eventsLoading}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
              Refresh
            </button>
          </div>

          <!-- Events list -->
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <!-- Loading -->
            <div v-if="eventsLoading && !eventsData.length" class="flex flex-col items-center justify-center py-12 gap-3">
              <svg class="animate-spin h-8 w-8 text-purple-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              <p class="text-xs text-slate-500">Loading events…</p>
            </div>
            <!-- Error -->
            <div v-else-if="eventsError" class="flex flex-col items-center justify-center py-12 gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              <p class="text-xs text-red-600">{{ eventsError }}</p>
              <button @click="fetchRouterEvents()" class="text-xs text-blue-600 hover:underline">Retry</button>
            </div>
            <!-- Empty -->
            <div v-else-if="!eventsData.length" class="flex flex-col items-center justify-center py-12 gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
              <p class="text-xs text-slate-500">No events found for this router</p>
            </div>
            <!-- Event rows -->
            <template v-else>
              <div
                v-for="event in eventsData"
                :key="event.id"
                class="flex items-start gap-3 px-4 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors"
              >
                <!-- Level badge -->
                <span
                  class="mt-0.5 flex-shrink-0 w-2 h-2 rounded-full mt-1.5"
                  :class="{
                    'bg-red-600': event.level === 'critical',
                    'bg-rose-500': event.level === 'error',
                    'bg-amber-500': event.level === 'warning',
                    'bg-blue-500': event.level === 'info',
                  }"
                ></span>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span
                      class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded"
                      :class="{
                        'bg-red-100 text-red-700': event.level === 'critical',
                        'bg-rose-100 text-rose-700': event.level === 'error',
                        'bg-amber-100 text-amber-700': event.level === 'warning',
                        'bg-blue-100 text-blue-700': event.level === 'info',
                      }"
                    >{{ event.level }}</span>
                    <span class="text-xs font-semibold text-slate-700">{{ formatEventAction(event.action) }}</span>
                  </div>
                  <p v-if="event.description" class="text-xs text-slate-600 mt-0.5">{{ event.description }}</p>
                  <div class="flex items-center gap-3 mt-1">
                    <span class="text-[10px] text-slate-400">{{ formatEventTime(event.created_at) }}</span>
                    <span v-if="event.user" class="text-[10px] text-slate-400">by {{ event.user.name || event.user.email }}</span>
                    <span v-if="event.ip_address" class="text-[10px] text-slate-400 font-mono">{{ event.ip_address }}</span>
                  </div>
                </div>
              </div>
              <!-- Pagination -->
              <div v-if="eventsMeta && eventsMeta.last_page > 1" class="flex items-center justify-between px-4 py-3 bg-slate-50 border-t border-slate-100">
                <span class="text-xs text-slate-500">Page {{ eventsMeta.current_page }} of {{ eventsMeta.last_page }}</span>
                <div class="flex gap-2">
                  <button :disabled="eventsMeta.current_page === 1" @click="fetchRouterEvents(eventsMeta.current_page - 1)" class="px-2.5 py-1 text-xs font-medium bg-white border border-slate-200 rounded hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed">Prev</button>
                  <button :disabled="eventsMeta.current_page === eventsMeta.last_page" @click="fetchRouterEvents(eventsMeta.current_page + 1)" class="px-2.5 py-1 text-xs font-medium bg-white border border-slate-200 rounded hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed">Next</button>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Internet Users Tab -->
        <div v-show="activeTab === 'users'" class="space-y-4">
          <!-- Users KPIs -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Total Users</div>
              <div class="text-2xl font-bold text-slate-800">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Online Now</div>
              <div class="text-2xl font-bold text-emerald-600">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Active Sessions</div>
              <div class="text-2xl font-bold text-blue-600">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Bandwidth Usage</div>
              <div class="text-2xl font-bold text-purple-600">—</div>
            </div>
          </div>
          <!-- Users List Placeholder -->
          <div class="bg-white p-8 rounded-xl border border-slate-200 shadow-sm text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-slate-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-700 mb-1">Internet Users</h4>
            <p class="text-xs text-slate-500 max-w-md mx-auto">Connected users with their session status will be displayed here. This feature is coming soon.</p>
          </div>
        </div>

        <!-- Payments Tab -->
        <div v-show="activeTab === 'payments'" class="space-y-4">
          <!-- Payments KPIs -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Total Revenue</div>
              <div class="text-2xl font-bold text-slate-800">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Active Subscriptions</div>
              <div class="text-2xl font-bold text-emerald-600">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Pending Payments</div>
              <div class="text-2xl font-bold text-amber-600">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">This Month</div>
              <div class="text-2xl font-bold text-blue-600">—</div>
            </div>
          </div>
          <!-- Payments List Placeholder -->
          <div class="bg-white p-8 rounded-xl border border-slate-200 shadow-sm text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-slate-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-700 mb-1">Payments</h4>
            <p class="text-xs text-slate-500 max-w-md mx-auto">User payments and subscription status will be displayed here. This feature is coming soon.</p>
          </div>
        </div>

        <!-- Backups Tab -->
        <div v-show="activeTab === 'backups'" class="space-y-4">
          <!-- Backups KPIs -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Total Backups</div>
              <div class="text-2xl font-bold text-slate-800">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Last Backup</div>
              <div class="text-2xl font-bold text-emerald-600">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Auto Backups</div>
              <div class="text-2xl font-bold text-blue-600">—</div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
              <div class="text-xs text-slate-500 mb-1">Storage Used</div>
              <div class="text-2xl font-bold text-purple-600">—</div>
            </div>
          </div>
          <!-- Backups List Placeholder -->
          <div class="bg-white p-8 rounded-xl border border-slate-200 shadow-sm text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-slate-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-700 mb-1">Backups</h4>
            <p class="text-xs text-slate-500 max-w-md mx-auto">Configuration backups and restore history will be displayed here. This feature is coming soon.</p>
          </div>
        </div>
        </div>
      </div>

    <template #footer>
      <div class="flex justify-between gap-3">
        <button
          type="button"
          @click="$emit('refresh-details')"
          :disabled="refreshing"
          class="flex-1 px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded-md hover:bg-blue-100 transition-colors flex items-center justify-center disabled:opacity-75 disabled:cursor-not-allowed"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-3.5 w-3.5 mr-1.5"
            :class="{ 'animate-spin': refreshing }"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          {{ refreshing ? 'Refreshing...' : 'Refresh' }}
        </button>
        <button
          type="button"
          @click="$emit('close-details')"
          class="flex-1 px-3 py-1.5 text-xs font-medium text-slate-700 bg-slate-100 rounded-md hover:bg-slate-200 transition-colors"
        >
          Close
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script>
import axios from 'axios'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useRouterUtils } from '@/modules/common/composables/utils/useRouterUtils'
import { useAuthStore } from '@/stores/auth'

const { formatHandshakeDateTime, getMemoryUsage, getDiskUsage, parseMemoryValue } = useRouterUtils()

export default {
  components: { SlideOverlay },
  setup() {
    const authStore = useAuthStore()
    return { authStore }
  },
  props: {
    showDetailsOverlay: Boolean,
    selectedRouter: Object,
    loading: Boolean,
    error: String,
    refreshing: Boolean,
  },
  emits: ['close-details', 'refresh-details'],
  computed: {
    routerDetails() {
      return this.selectedRouter || {}
    },
    statusClass() {
      const status = this.routerDetails.status?.toLowerCase()
      return {
        'bg-green-500': status === 'online' || status === 'active',
        'bg-red-500': status === 'offline' || status === 'disconnected',
        'bg-yellow-500': status === 'pending' || status === 'warning',
        'bg-slate-500': !status || status === 'unknown',
      }
    },
    statusBadgeClass() {
      const status = this.routerDetails.status?.toLowerCase()
      return {
        'bg-green-100 text-green-800': status === 'online' || status === 'active',
        'bg-red-100 text-red-800': status === 'offline' || status === 'disconnected',
        'bg-yellow-100 text-yellow-800': status === 'pending' || status === 'warning',
        'bg-slate-100 text-slate-800': !status || status === 'unknown',
      }
    },
    statusText() {
      const status = this.routerDetails.status?.toLowerCase()
      if (status === 'online' || status === 'active') return 'Connected'
      if (status === 'offline' || status === 'disconnected') return 'Offline'
      if (status === 'pending' || status === 'warning') return 'Warning'
      return 'Unknown'
    },
    vpnStatusBadgeClass() {
      const status = String(this.routerDetails.vpn_status || '').toLowerCase()
      return {
        'bg-emerald-100 text-emerald-700': status === 'active' || status === 'connected',
        'bg-amber-100 text-amber-700': status === 'pending',
        'bg-slate-100 text-slate-600': status === 'inactive' || status === 'disconnected' || !status,
      }
    },
    vpnStatusText() {
      const status = String(this.routerDetails.vpn_status || '').toLowerCase()
      if (!status) return 'Unknown'
      if (status === 'active' || status === 'connected') return 'Active'
      if (status === 'inactive' || status === 'disconnected') return 'Inactive'
      if (status === 'pending') return 'Pending'
      return status
    },
    handshakeEatTime() {
      return formatHandshakeDateTime(this.routerDetails, 'Africa/Nairobi')
    },
    handshakeUtcTime() {
      return formatHandshakeDateTime(this.routerDetails, 'UTC')
    },
    groupedServices() {
      const services = this.routerDetails.services || []
      if (!services.length) return []

      const groupedMap = {}

      services.forEach((svc) => {
        const type = svc.service_type || 'unknown'
        if (!groupedMap[type]) {
          groupedMap[type] = {
            ...svc,
            interfaces: Array.isArray(svc.interfaces) ? [...svc.interfaces] : [],
            active_users: svc.active_users ?? 0,
            total_sessions: svc.total_sessions ?? 0,
          }
        } else {
          const existing = groupedMap[type]

          if (Array.isArray(svc.interfaces)) {
            const merged = new Set([...(existing.interfaces || []), ...svc.interfaces])
            existing.interfaces = Array.from(merged)
          }

          const existingStatus = existing.status || 'inactive'
          const newStatus = svc.status || 'inactive'
          if (existingStatus !== 'active' && newStatus === 'active') {
            existing.status = 'active'
          }

          // Use live data if available for more accurate session counts
          const live = this.routerDetails.live_data || this.routerDetails.resources || {}
          
          if (type === 'pppoe' && live.pppoe_sessions !== undefined) {
             existing.total_sessions = live.pppoe_sessions
             existing.active_users = live.pppoe_sessions
          } else if (type === 'hotspot' && live.hotspot_active !== undefined) {
             existing.total_sessions = live.hotspot_active
             existing.active_users = live.hotspot_active
          } else {
             // Fallback to database values
             existing.active_users = (existing.active_users || 0) + (svc.active_users ?? 0)
             existing.total_sessions = (existing.total_sessions || 0) + (svc.total_sessions ?? 0)
          }

          if (!existing.deployment_status || existing.deployment_status === 'deployed') {
            existing.deployment_status = svc.deployment_status || existing.deployment_status
          }

          if (!existing.last_checked_at || (svc.last_checked_at && svc.last_checked_at > existing.last_checked_at)) {
            existing.last_checked_at = svc.last_checked_at
          }
        }
      })

      return Object.values(groupedMap)
    },
    trafficMax() {
      if (!Array.isArray(this.trafficData) || this.trafficData.length === 0) return 1
      if (this.selectedChartType === 'stacked') {
        return Math.max(
          ...this.trafficData.map((d) => (d.download || 0) + (d.upload || 0)),
          1,
        )
      }
      return Math.max(
        ...this.trafficData.map((d) => Math.max(d.download || 0, d.upload || 0)),
        1,
      )
    },
    chartRenderData() {
      if (!this.trafficData.length) return null

      const width = 1000
      const height = 200
      const max = this.trafficMax
      const data = this.trafficData
      const count = data.length
      const type = this.selectedChartType

      // Common: Line / Area / Stacked
      if (['line', 'area', 'stacked'].includes(type)) {
        const getPt = (val, i) => {
          const x = (i / (count - 1 || 1)) * width
          const y = height - (val / max) * height
          return `${x.toFixed(1)},${y.toFixed(1)}`
        }

        if (type === 'stacked') {
          // Stacked Area
          // Layer 1: Download (Base)
          // Layer 2: Upload (On top of Download)
          const dPoints = data.map((d, i) => getPt(d.download, i))
          const uPoints = data.map((d, i) => {
            const total = (d.download || 0) + (d.upload || 0)
            const x = (i / (count - 1 || 1)) * width
            const y = height - (total / max) * height
            return `${x.toFixed(1)},${y.toFixed(1)}`
          })

          const dLine = `M ${dPoints.join(' L ')}`
          // Build area for upload: move to last point of upload, line to first point of upload,
          // then line to first point of download, then line to last point of download (reverse)
          // Actually simplest is:
          // Download Area: 0 to Download curve
          // Upload Area: Download curve to Total curve

          // Download Area Path
          const dArea = `${dLine} L ${width},${height} L 0,${height} Z`

          // Upload Area Path: Top Curve (Total) -> Down to Download Curve -> Back to Start
          // We need points in reverse for the bottom part of the shape
          const dPointsRev = [...dPoints].reverse()
          const uArea = `M ${uPoints.join(' L ')} L ${dPointsRev.join(' L ')} Z`

          return {
            type,
            paths: {
              download: dArea, // In stacked, the "line" is the top of the area usually, or we just render areas
              upload: uArea,
            },
          }
        }

        const dPoints = data.map((d, i) => getPt(d.download, i))
        const uPoints = data.map((d, i) => getPt(d.upload, i))
        const dLine = `M ${dPoints.join(' L ')}`
        const uLine = `M ${uPoints.join(' L ')}`

        if (type === 'area') {
          return {
            type,
            paths: {
              download: `${dLine} L ${width},${height} L 0,${height} Z`,
              upload: `${uLine} L ${width},${height} L 0,${height} Z`,
            },
          }
        }

        // Line
        return {
          type,
          paths: {
            download: dLine,
            upload: uLine,
          },
        }
      }

      // Bar
      if (type === 'bar') {
        const barWidth = width / count
        const gap = Math.max(1, barWidth * 0.2)
        const w = Math.max(1, barWidth - gap)

        const bars = data.map((d, i) => {
          const x = i * barWidth + gap / 2
          const hD = (d.download / max) * height
          const hU = (d.upload / max) * height
          return {
            x,
            w,
            yD: height - hD,
            hD,
            yU: height - hU,
            hU,
          }
        })
        return { type, bars }
      }

      // Pie / Donut
      if (type === 'pie' || type === 'donut') {
        const totalD = data.reduce((acc, d) => acc + (d.download || 0), 0)
        const totalU = data.reduce((acc, d) => acc + (d.upload || 0), 0)
        const total = totalD + totalU || 1

        const cx = width / 2
        const cy = height / 2
        const r = Math.min(width, height) / 2 - 10

        // Helper to calc path
        const getSector = (startPct, endPct) => {
          const startAngle = startPct * 360
          const endAngle = endPct * 360
          
          // Convert to radians, shifted by -90deg to start at top
          const toRad = (deg) => ((deg - 90) * Math.PI) / 180
          const x1 = cx + r * Math.cos(toRad(startAngle))
          const y1 = cy + r * Math.sin(toRad(startAngle))
          const x2 = cx + r * Math.cos(toRad(endAngle))
          const y2 = cy + r * Math.sin(toRad(endAngle))
          
          const largeArc = endAngle - startAngle > 180 ? 1 : 0
          
          return `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`
        }

        const pctD = totalD / total
        const dPath = getSector(0, pctD)
        const uPath = getSector(pctD, 1)

        return {
          type,
          cx,
          cy,
          r,
          paths: {
            download: dPath,
            upload: uPath,
          },
          totals: { download: totalD, upload: totalU },
        }
      }

      // Histogram (Distribution)
      if (type === 'histogram') {
        const bins = 20
        const binWidth = max / bins
        const dFreq = new Array(bins).fill(0)
        const uFreq = new Array(bins).fill(0)

        data.forEach((d) => {
          const dIdx = Math.min(Math.floor(d.download / binWidth), bins - 1)
          const uIdx = Math.min(Math.floor(d.upload / binWidth), bins - 1)
          dFreq[dIdx]++
          uFreq[uIdx]++
        })

        const maxFreq = Math.max(...dFreq, ...uFreq, 1)
        const barW = (width / bins) - 2

        const bars = dFreq.map((count, i) => {
          const hD = (count / maxFreq) * height
          const hU = (uFreq[i] / maxFreq) * height
          return {
            x: i * (width / bins),
            w: barW,
            yD: height - hD,
            hD,
            yU: height - hU,
            hU,
            label: this.formatBytes(i * binWidth),
          }
        })

        return { type, bars }
      }

      return null
    },
    yAxisTicks() {
      if (['pie', 'donut'].includes(this.selectedChartType)) return []
      
      const max = this.trafficMax
      const ticks = 5
      return Array.from({ length: ticks + 1 }, (_, i) => {
        const val = (max / ticks) * i
        return {
          value: val,
          label: this.formatBytes(val),
          percent: (i / ticks) * 100,
        }
      })
    },
    hoverX() {
      if (this.hoveredIndex < 0 || !this.trafficData.length) return 0
      return (this.hoveredIndex / (this.trafficData.length - 1)) * 100
    },
    xAxisTicks() {
      if (['pie', 'donut'].includes(this.selectedChartType)) return []
      if (!this.trafficData.length) return []

      if (this.selectedChartType === 'histogram') {
          // Histogram buckets
          const bins = 20
          const max = this.trafficMax
          const binWidth = max / bins
          // Show 5 labels distributed
          const count = 5
          return Array.from({ length: count }, (_, i) => {
              const idx = Math.floor(i * (bins / (count - 1)))
              // Clamp
              const safeIdx = Math.min(idx, bins)
              const val = safeIdx * binWidth
              return {
                  x: (safeIdx / bins) * 100,
                  label: this.formatBytes(val)
              }
          })
      }

      const count = 6
      const data = this.trafficData
      const step = Math.floor((data.length - 1) / (count - 1)) || 1
      const padding = 5 // 5% padding on each side
      const usableWidth = 100 - (padding * 2)

      const seenLabels = new Set()
      const ticks = []
      let tickIndex = 0

      for (let i = 0; i < data.length; i += step) {
        const d = data[i]
        let label = ''
        try {
          label = new Date(d.ts * 1000).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
          })
        } catch (e) {
          label = ''
        }

        // Skip duplicate labels, but always include first and last
        if (seenLabels.has(label) && i > 0 && i < data.length - 1) {
          continue
        }
        seenLabels.add(label)

        const x = padding + ((tickIndex / (count - 1)) * usableWidth)
        ticks.push({ x, label })
        tickIndex++

        if (ticks.length >= count) break
      }

      // Ensure last data point is included
      const lastData = data[data.length - 1]
      const lastLabel = new Date(lastData.ts * 1000).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      })
      if (ticks.length > 0 && ticks[ticks.length - 1].label !== lastLabel) {
        const lastX = padding + usableWidth
        ticks.push({ x: lastX, label: lastLabel })
      }

      return ticks
    },
    // Resource graph computed properties
    trafficStats() {
      // Always derive from latest trafficData for real-time updates
      const lastPoint = this.trafficData[this.trafficData.length - 1] || { download: 0, upload: 0 }
      return {
        current: (lastPoint.download || 0) + (lastPoint.upload || 0),
        download: lastPoint.download || 0,
        upload: lastPoint.upload || 0,
      }
    },
    combinedResourceData() {
      // Combine CPU, Memory, Disk data by timestamp
      const map = new Map()
      
      this.resourceData.cpu.forEach((p) => {
        if (!map.has(p.ts)) map.set(p.ts, { ts: p.ts, cpu: 0, memory: 0, disk: 0 })
        map.get(p.ts).cpu = p.value
      })
      
      this.resourceData.memory.forEach((p) => {
        if (!map.has(p.ts)) map.set(p.ts, { ts: p.ts, cpu: 0, memory: 0, disk: 0 })
        map.get(p.ts).memory = p.value
      })
      
      this.resourceData.disk.forEach((p) => {
        if (!map.has(p.ts)) map.set(p.ts, { ts: p.ts, cpu: 0, memory: 0, disk: 0 })
        map.get(p.ts).disk = p.value
      })
      
      return Array.from(map.values()).sort((a, b) => a.ts - b.ts)
    },
    resourceChartRenderData() {
      const data = this.combinedResourceData
      if (!data.length) return null

      const width = 1000
      const height = 200
      const max = 100 // Percentage 0-100

      const getPt = (val, i) => {
        const x = (i / (data.length - 1 || 1)) * width
        const y = height - ((val || 0) / max) * height
        return `${x.toFixed(1)},${y.toFixed(1)}`
      }

      // Generate points for each resource
      const cpuPoints = data.map((d, i) => getPt(d.cpu, i))
      const memPoints = data.map((d, i) => getPt(d.memory, i))
      const diskPoints = data.map((d, i) => getPt(d.disk, i))

      // Generate lines
      const cpuLine = `M ${cpuPoints.join(' L ')}`
      const memLine = `M ${memPoints.join(' L ')}`
      const diskLine = `M ${diskPoints.join(' L ')}`

      // Generate area paths (line + bottom corners + close)
      const cpuArea = `${cpuLine} L ${width},${height} L 0,${height} Z`
      const memArea = `${memLine} L ${width},${height} L 0,${height} Z`
      const diskArea = `${diskLine} L ${width},${height} L 0,${height} Z`

      return {
        paths: {
          cpu: cpuLine,
          mem: memLine,
          disk: diskLine,
          cpuArea,
          memArea,
          diskArea,
        },
      }
    },
    resourceYAxisTicks() {
      const ticks = 5
      const max = 100 // Percentage
      return Array.from({ length: ticks + 1 }, (_, i) => {
        const val = (max / ticks) * i
        return {
          value: val,
          label: `${Math.round(val)}%`,
          percent: (i / ticks) * 100,
        }
      })
    },
    resourceHoverX() {
      if (this.resourceHoveredIndex < 0 || !this.combinedResourceData.length) return 0
      return (this.resourceHoveredIndex / (this.combinedResourceData.length - 1)) * 100
    },
    resourceXAxisTicks() {
      if (!this.combinedResourceData.length) return []

      const count = 5 // Reduced from 6 to 5 for better spacing
      const data = this.combinedResourceData
      const step = Math.floor((data.length - 1) / (count - 1)) || 1
      const padding = 5 // 5% padding on each side
      const usableWidth = 100 - (padding * 2)

      const seenLabels = new Set()
      const ticks = []
      let tickIndex = 0

      for (let i = 0; i < data.length; i += step) {
        const d = data[i]
        let label = ''
        try {
          label = new Date(d.ts * 1000).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
          })
        } catch (e) {
          label = ''
        }

        // Skip duplicate labels, but always include first and last
        if (seenLabels.has(label) && i > 0 && i < data.length - 1) {
          continue
        }
        seenLabels.add(label)

        const x = padding + ((tickIndex / (count - 1)) * usableWidth)
        ticks.push({ x, label })
        tickIndex++

        if (ticks.length >= count) break
      }

      // Ensure last data point is included
      const lastData = data[data.length - 1]
      const lastLabel = new Date(lastData.ts * 1000).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      })
      if (ticks.length > 0 && ticks[ticks.length - 1].label !== lastLabel) {
        const lastX = padding + usableWidth
        ticks.push({ x: lastX, label: lastLabel })
      }

      return ticks
    },
    // Resource stats computed property for real-time updates
    resourceStats() {
      const cpuValues = this.resourceData.cpu.map(d => d.value).filter(Number.isFinite)
      const memValues = this.resourceData.memory.map(d => d.value).filter(Number.isFinite)
      const diskValues = this.resourceData.disk.map(d => d.value).filter(Number.isFinite)
      
      const calcStats = (values) => {
        if (!values.length) return { current: null, avg: null, max: null }
        return {
          current: values[values.length - 1],
          avg: values.reduce((a, b) => a + b, 0) / values.length,
          max: Math.max(...values),
        }
      }
      
      return {
        cpu: calcStats(cpuValues),
        memory: { ...calcStats(memValues), used: null, total: null },
        disk: { ...calcStats(diskValues), used: null, total: null },
      }
    },
  },
  data() {
    return {
      activeTab: 'system',
      eventsLoading: false,
      eventsError: '',
      eventsData: [],
      eventsSummary: {},
      eventsMeta: null,
      eventsLevelFilter: 'all',
      tabs: [
        { id: 'system', label: 'System Information' },
        { id: 'events', label: 'Device Events' },
        { id: 'reports', label: 'Reports' },
        { id: 'users', label: 'Internet Users' },
        { id: 'payments', label: 'Payments' },
        { id: 'backups', label: 'Backups' },
        { id: 'config', label: 'Configurations' },
      ],
      trafficLoading: false,
      trafficError: '',
      trafficData: [],
      // trafficStats removed - now a computed property for real-time updates
      selectedChartType: 'line',
      chartTypes: [
        { value: 'line', label: 'Time Series (Line)' },
        { value: 'area', label: 'Area' },
        { value: 'stacked', label: 'Stacked Area' },
        { value: 'bar', label: 'Bar' },
        { value: 'pie', label: 'Pie (Total)' },
        { value: 'donut', label: 'Donut (Total)' },
        { value: 'histogram', label: 'Histogram' },
      ],
      hoveredIndex: -1,
      hoveredData: null,
      refreshInterval: null,
      // Time range selectors - independent for traffic and resources
      trafficTimeRange: '1h',
      resourceTimeRange: '1h',
      timeRanges: [
        { value: '15m', label: 'Last 15 min' },
        { value: '30m', label: 'Last 30 min' },
        { value: '1h', label: 'Last 1 hour' },
        { value: '6h', label: 'Last 6 hours' },
        { value: '24h', label: 'Last 24 hours' },
        { value: '7d', label: 'Last 7 days' },
      ],
      // Resource metrics
      resourceLoading: false,
      resourceError: '',
      resourceData: {
        cpu: [],
        memory: [],
        disk: [],
      },
      // resourceStats removed - now a computed property for real-time updates
      // Resource graph hover state
      resourceHoveredIndex: -1,
      resourceHoveredData: null,
      selectedResourceChart: 'line',
      trafficFullscreen: false,
      resourceFullscreen: false,
      // WebSocket connection for event-based updates
      metricsChannel: null,
      subscribedRouterId: null,
    }
  },
  beforeUnmount() {
    this.unsubscribeFromMetrics()
  },
  mounted() {
    if (this.showDetailsOverlay) {
      this.loadTraffic()
      this.loadResources()
      // Only subscribe if not already subscribed to this router
      if (this.subscribedRouterId !== this.routerDetails?.id) {
        this.subscribeToMetrics()
      }
    }
  },
  watch: {
    showDetailsOverlay(val) {
      if (val) {
        this.loadTraffic()
        this.loadResources()
        this.subscribeToMetrics()
        if (this.activeTab === 'events') {
          this.fetchRouterEvents()
        }
      } else {
        this.unsubscribeFromMetrics()
        this.eventsData = []
        this.eventsSummary = {}
        this.eventsMeta = null
        this.eventsError = ''
        this.eventsLevelFilter = 'all'
      }
    },
    selectedRouter() {
      if (this.showDetailsOverlay) {
        this.loadTraffic()
        this.loadResources()
      }
      this.eventsData = []
      this.eventsSummary = {}
      this.eventsMeta = null
      this.eventsError = ''
      if (this.showDetailsOverlay && this.activeTab === 'events') {
        this.$nextTick(() => this.fetchRouterEvents())
      }
    },
    trafficTimeRange() {
      if (this.showDetailsOverlay) {
        this.loadTraffic()
      }
    },
    resourceTimeRange() {
      if (this.showDetailsOverlay) {
        this.loadResources()
      }
    },
    activeTab(val) {
      if (val === 'events' && !this.eventsData.length && !this.eventsLoading) {
        this.fetchRouterEvents()
      }
    },
  },
  methods: {
    async fetchRouterEvents(page = 1) {
      const routerId = this.routerDetails?.id
      if (!routerId) {
        console.warn('[Events] No routerId, skipping fetch')
        return
      }
      this.eventsLoading = true
      this.eventsError = ''
      try {
        const params = { page, per_page: 20 }
        if (this.eventsLevelFilter !== 'all') params.level = this.eventsLevelFilter
        console.log('[Events] Fetching', `/routers/${routerId}/events`, params)
        const res = await axios.get(`/routers/${routerId}/events`, { params })
        console.log('[Events] Response', res.data)
        if (res.data?.success) {
          this.eventsData = res.data.events?.data || []
          this.eventsMeta = res.data.events ? {
            current_page: res.data.events.current_page,
            last_page: res.data.events.last_page,
            total: res.data.events.total,
          } : null
          this.eventsSummary = res.data.summary || {}
        } else {
          this.eventsError = res.data?.error || 'Failed to load events'
        }
      } catch (e) {
        console.error('[Events] Error', e.response?.data || e.message)
        this.eventsError = e.response?.data?.error || e.message || 'Failed to load events'
      } finally {
        this.eventsLoading = false
      }
    },
    formatEventAction(action) {
      return (action || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    },
    formatEventTime(ts) {
      if (!ts) return ''
      try {
        const d = new Date(ts)
        const diff = Math.floor((Date.now() - d.getTime()) / 1000)
        if (diff < 60) return `${diff}s ago`
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      } catch { return ts }
    },
    formatTime(ts) {
      if (!ts) return ''
      try {
        return new Date(ts * 1000).toLocaleTimeString([], {
          hour: '2-digit',
          minute: '2-digit',
        })
      } catch (e) {
        return ''
      }
    },
    handleGraphHover(event) {
      if (!this.trafficData.length) return
      if (['pie', 'donut', 'histogram'].includes(this.selectedChartType)) return

      const container = this.$refs.graphContainer
      if (!container) return
      
      const rect = container.getBoundingClientRect()
      const x = event.clientX - rect.left
      const width = rect.width
      const count = this.trafficData.length

      // x / width = index / (count - 1)
      // index = (x / width) * (count - 1)
      let index = Math.round((x / width) * (count - 1))
      index = Math.max(0, Math.min(index, count - 1))

      this.hoveredIndex = index
      this.hoveredData = this.trafficData[index]
    },
    handleGraphLeave() {
      this.hoveredIndex = -1
      this.hoveredData = null
    },
    handleResourceGraphHover(event) {
      if (!this.combinedResourceData.length) return

      const container = this.$refs.resourceGraphContainer
      if (!container) return
      
      const rect = container.getBoundingClientRect()
      const x = event.clientX - rect.left
      const width = rect.width
      const count = this.combinedResourceData.length

      let index = Math.round((x / width) * (count - 1))
      index = Math.max(0, Math.min(index, count - 1))

      this.resourceHoveredIndex = index
      this.resourceHoveredData = this.combinedResourceData[index]
    },
    handleResourceGraphLeave() {
      this.resourceHoveredIndex = -1
      this.resourceHoveredData = null
    },
    toggleResourceFullscreen() {
      this.resourceFullscreen = !this.resourceFullscreen
      this.toggleBodyClass(this.resourceFullscreen)
    },
    toggleTrafficFullscreen() {
      this.trafficFullscreen = !this.trafficFullscreen
      this.toggleBodyClass(this.trafficFullscreen)
    },
    toggleBodyClass(isFullscreen) {
      if (isFullscreen) {
        document.body.classList.add('graph-fullscreen-active')
      } else {
        document.body.classList.remove('graph-fullscreen-active')
      }
    },
    subscribeToMetrics() {
      // Prevent double subscription - check if already subscribed to this router
      const routerId = this.routerDetails?.id
      const tenantId = this.authStore?.tenantId
      
      if (!routerId || !tenantId || !window.Echo) {
        console.log('Cannot subscribe - missing routerId, tenantId, or Echo')
        return
      }
      
      const channelName = `tenant.${tenantId}.router.${routerId}`
      
      // Already subscribed to this exact channel - skip
      if (this.metricsChannel && this.subscribedRouterId === routerId) {
        console.log(`Already subscribed to ${channelName}, skipping`)
        return
      }
      
      // Unsubscribe from previous router if different
      if (this.metricsChannel && this.subscribedRouterId !== routerId) {
        console.log(`Switching from router ${this.subscribedRouterId} to ${routerId}`)
        this.unsubscribeFromMetrics()
      }
      
      this.subscribedRouterId = routerId
      
      this.metricsChannel = window.Echo.private(channelName)
        .listen('.router.metrics.updated', (event) => {
          this.handleMetricsUpdate(event)
        })
      
      console.log('Subscribed to metrics channel:', channelName)
    },
    unsubscribeFromMetrics() {
      if (this.metricsChannel && this.subscribedRouterId) {
        const tenantId = this.authStore?.tenantId
        const channelName = `tenant.${tenantId}.router.${this.subscribedRouterId}`
        
        this.metricsChannel.stopListening('.router.metrics.updated')
        window.Echo?.leave(channelName)
        
        console.log('Unsubscribed from metrics channel:', channelName)
        
        this.metricsChannel = null
        this.subscribedRouterId = null
      }
    },
    handleMetricsUpdate(event) {
      if (!event || !event.metrics) return
      
      const { metric_type, time_range, metrics } = event
      
      if (metric_type === 'traffic' && Array.isArray(metrics)) {
        // Only update if the time range matches traffic time selection
        if (time_range !== this.trafficTimeRange) return
        
        this.trafficData = metrics.slice(-60)
        this.trafficLoading = false
        
        // trafficStats now computed property - automatically updates from trafficData
        // const lastPoint = this.trafficData[this.trafficData.length - 1] || { download: 0, upload: 0 }
        // this.trafficStats.download = lastPoint.download
        // this.trafficStats.upload = lastPoint.upload
        // this.trafficStats.current = lastPoint.download + lastPoint.upload
        
        // Update tooltip if hovering
        if (this.hoveredIndex >= 0 && this.hoveredIndex < this.trafficData.length) {
          this.hoveredData = this.trafficData[this.hoveredIndex]
        }
      } else if (metric_type === 'resources') {
        // Only update if the time range matches resource time selection
        if (time_range !== this.resourceTimeRange) return

        const cpuSeries = this.normalizeResourceSeries(metrics.cpu)
        if (cpuSeries.length > 0) {
          this.resourceData.cpu = cpuSeries.map(p => ({ ts: p.ts, value: p.v }))
        }
        const memorySeries = this.normalizeResourceSeries(metrics.memory)
        if (memorySeries.length > 0) {
          this.resourceData.memory = memorySeries.map(p => ({ ts: p.ts, value: p.v }))
        }
        const diskSeries = this.normalizeResourceSeries(metrics.disk)
        if (diskSeries.length > 0) {
          this.resourceData.disk = diskSeries.map(p => ({ ts: p.ts, value: p.v }))
        }
        this.resourceLoading = false
        // resourceStats now computed property - automatically updates
        // this.calculateResourceStats()
        
        // Update tooltip if hovering
        if (this.resourceHoveredIndex >= 0 && this.resourceHoveredIndex < this.combinedResourceData.length) {
          this.resourceHoveredData = this.combinedResourceData[this.resourceHoveredIndex]
        }
      }
    },
    formatDate(dateString) {
      if (!dateString) return 'N/A'
      try {
        const date = new Date(dateString)
        return date.toLocaleString()
      } catch (err) {
        return 'Invalid date'
      }
    },
    formatBytes(bytes) {
      if (bytes === undefined || bytes === null || bytes === 'N/A') return 'N/A'

      let numeric = null
      if (typeof bytes === 'number') {
        numeric = bytes
      } else {
        const raw = String(bytes).trim()
        const match = raw.match(/^([\d.]+)\s*([KMGT]i?B)?$/i)
        if (match) {
          const n = Number(match[1])
          const unit = String(match[2] || 'B').toUpperCase()
          const multipliers = {
            B: 1,
            KB: 1024,
            KIB: 1024,
            MB: 1024 * 1024,
            MIB: 1024 * 1024,
            GB: 1024 * 1024 * 1024,
            GIB: 1024 * 1024 * 1024,
            TB: 1024 * 1024 * 1024 * 1024,
            TIB: 1024 * 1024 * 1024 * 1024,
          }
          numeric = n * (multipliers[unit] || 1)
        } else {
          const n = Number(raw)
          numeric = Number.isFinite(n) ? n : null
        }
      }

      if (!Number.isFinite(numeric) || numeric < 0) return '0 B'
      if (numeric === 0) return '0 B'

      const units = ['B', 'KB', 'MB', 'GB', 'TB']
      let value = numeric
      let unitIndex = 0
      while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024
        unitIndex++
      }
      return `${value.toFixed(1)} ${units[unitIndex]}`
    },
    getMemoryPercent(live) {
      const total = live?.total_memory ?? live?.['total-memory']
      const free = live?.free_memory ?? live?.['free-memory']
      const t = parseMemoryValue(total)
      const f = parseMemoryValue(free)
      if (t === null || f === null || t === 0) return null
      return Math.max(0, Math.min(100, Math.round(((t - f) / t) * 100)))
    },
    getDiskUsed(live) {
      const total = live?.total_hdd_space
      const free = live?.free_hdd_space

      const parse = (v) => {
        if (v === undefined || v === null || v === '' || v === 'N/A') return null
        if (typeof v === 'number') return v

        const raw = String(v).trim()
        const match = raw.match(/^([\d.]+)\s*([KMGT]i?B)?$/i)
        if (match) {
          const n = Number(match[1])
          const unit = String(match[2] || 'B').toUpperCase()
          const multipliers = {
            B: 1,
            KB: 1024,
            KIB: 1024,
            MB: 1024 * 1024,
            MIB: 1024 * 1024,
            GB: 1024 * 1024 * 1024,
            GIB: 1024 * 1024 * 1024,
            TB: 1024 * 1024 * 1024 * 1024,
            TIB: 1024 * 1024 * 1024 * 1024,
          }
          return n * (multipliers[unit] || 1)
        }

        const n = Number(raw)
        return Number.isFinite(n) ? n : null
      }

      const t = parse(total)
      const f = parse(free)

      if (!Number.isFinite(t) || !Number.isFinite(f)) return null
      const used = t - f
      return used < 0 ? 0 : used
    },
    parseVmSeriesValues(vmResponse) {
      const result = vmResponse?.data?.result ?? vmResponse?.result
      if (!Array.isArray(result) || !result.length) return []
      const values = result[0]?.values
      if (!Array.isArray(values)) return []

      return values
        .map((pair) => {
          if (!Array.isArray(pair) || pair.length < 2) return null
          const ts = Number(pair[0])
          const v = Number(pair[1])
          if (!Number.isFinite(ts) || !Number.isFinite(v)) return null
          return { ts, v }
        })
        .filter(Boolean)
    },
    normalizeResourceSeries(series) {
      if (!series) return []

      let points = []
      if (Array.isArray(series)) {
        points = series
          .map((point) => {
            if (Array.isArray(point) && point.length >= 2) {
              return { ts: Number(point[0]), v: Number(point[1]) }
            }
            if (point && typeof point === 'object') {
              const ts = Number(point.ts ?? point.timestamp ?? point.t)
              const v = Number(point.v ?? point.value ?? point.y)
              return { ts, v }
            }
            return null
          })
          .filter(Boolean)
      } else if (typeof series === 'object') {
        points = this.parseVmSeriesValues(series)
      }

      return points
        .map((point) => {
          if (!Number.isFinite(point.ts) || !Number.isFinite(point.v)) return null
          const clamped = Math.max(0, Math.min(100, point.v))
          return { ts: point.ts, v: clamped }
        })
        .filter(Boolean)
    },
    getLastValue(points) {
      if (!Array.isArray(points) || points.length === 0) return 0
      return points[points.length - 1]?.v ?? 0
    },
    async loadTraffic() {
      const routerId = String(this.routerDetails?.id ?? '')
      if (!routerId) return

      // Only show loading state if we have no data (initial load)
      if (this.trafficData.length === 0) {
        this.trafficLoading = true
        this.trafficError = ''
      }

      try {
        const response = await axios.get(`/routers/${routerId}/metrics/traffic`, {
          params: {
            range: this.trafficTimeRange,
            step: '30s',
          },
        })

        const data = response.data || {}
        if (!data.success) {
          throw new Error(data.error || 'Failed to load traffic')
        }

        const inSeries = this.parseVmSeriesValues(data.in)
        const outSeries = this.parseVmSeriesValues(data.out)

        // Align by timestamp
        const map = new Map()

        inSeries.forEach((p) => {
          if (!map.has(p.ts)) map.set(p.ts, { ts: p.ts, download: 0, upload: 0 })
          map.get(p.ts).upload = p.v
        })

        outSeries.forEach((p) => {
          if (!map.has(p.ts)) map.set(p.ts, { ts: p.ts, download: 0, upload: 0 })
          map.get(p.ts).download = p.v
        })

        const points = Array.from(map.values()).sort((a, b) => a.ts - b.ts)
        this.trafficData = points.slice(-60)
        this.trafficError = '' // Clear error on success

        const lastPoint = this.trafficData[this.trafficData.length - 1] || {
          download: 0,
          upload: 0,
        }
        // trafficStats now computed property - no need to set manually
        // this.trafficStats.download = lastPoint.download
        // this.trafficStats.upload = lastPoint.upload
        // this.trafficStats.current = lastPoint.download + lastPoint.upload

        // Update tooltip data if hovering
        if (this.hoveredIndex >= 0) {
          if (this.hoveredIndex < this.trafficData.length) {
            this.hoveredData = this.trafficData[this.hoveredIndex]
          } else {
            this.hoveredIndex = -1
            this.hoveredData = null
          }
        }
      } catch (err) {
        // Only show error if we don't have existing data
        if (this.trafficData.length === 0) {
          this.trafficError = err.response?.data?.error || err.message || 'Failed to load traffic'
          this.trafficData = []
        } else {
          console.warn('Background traffic refresh failed:', err.message)
        }
      } finally {
        this.trafficLoading = false
      }
    },
    async loadResources() {
      const routerId = String(this.routerDetails?.id ?? '')
      if (!routerId) return

      this.resourceLoading = true
      this.resourceError = ''

      try {
        const response = await axios.get(`/routers/${routerId}/metrics/resources`, {
          params: {
            range: this.resourceTimeRange,
            step: '30s',
          },
        })

        const data = response.data || {}
        console.log('Resource metrics response:', data)
        
        if (!data.success) {
          throw new Error(data.error || 'Failed to load resource metrics')
        }

        // Parse CPU data - only update if we have data to prevent clearing on empty response
        const cpuSeries = this.normalizeResourceSeries(data.cpu)
        if (cpuSeries.length > 0) {
          this.resourceData.cpu = cpuSeries.map(p => ({ ts: p.ts, value: p.v }))
        }

        // Parse Memory data (as percentage) - only update if we have data
        const memSeries = this.normalizeResourceSeries(data.memory)
        if (memSeries.length > 0) {
          this.resourceData.memory = memSeries.map(p => ({ ts: p.ts, value: p.v }))
        }

        // Parse Disk data (as percentage) - only update if we have data
        const diskSeries = this.normalizeResourceSeries(data.disk)
        if (diskSeries.length > 0) {
          this.resourceData.disk = diskSeries.map(p => ({ ts: p.ts, value: p.v }))
        }

        console.log('Parsed resource data:', {
          cpu: this.resourceData.cpu.length,
          memory: this.resourceData.memory.length,
          disk: this.resourceData.disk.length,
          cpuSample: this.resourceData.cpu[0],
          memSample: this.resourceData.memory[0],
          diskSample: this.resourceData.disk[0]
        })

        // Calculate stats
        // resourceStats now computed property - automatically updates
        // this.calculateResourceStats()
        this.resourceError = ''

        // Update tooltip data if hovering
        if (this.resourceHoveredIndex >= 0) {
          if (this.resourceHoveredIndex < this.combinedResourceData.length) {
            this.resourceHoveredData = this.combinedResourceData[this.resourceHoveredIndex]
          } else {
            this.resourceHoveredIndex = -1
            this.resourceHoveredData = null
          }
        }
      } catch (err) {
        this.resourceError = err.response?.data?.error || err.message || 'Failed to load resource metrics'
        console.error('Resource metrics error:', err)
      } finally {
        this.resourceLoading = false
      }
    },
    // calculateResourceStats removed - now computed property
    getResourceMax(type) {
      return 100 // CPU, Memory, Disk are all percentages 0-100
    },
    formatPercent(value) {
      if (value === undefined || value === null || !Number.isFinite(value)) return 'N/A'
      return `${value.toFixed(1)}%`
    },
    getResourcePath(type) {
      const data = this.resourceData[type]
      if (!data || data.length === 0) return ''
      
      const width = 1000
      const height = 100
      const max = 100 // Percentage 0-100
      
      const points = data.map((d, i) => {
        const x = (i / (data.length - 1 || 1)) * width
        const y = height - (d.value / max) * height
        return `${x.toFixed(1)},${y.toFixed(1)}`
      })
      
      const line = `M ${points.join(' L ')}`
      return `${line} L ${width},${height} L 0,${height} Z`
    },
    getResourceLine(type) {
      const data = this.resourceData[type]
      if (!data || data.length === 0) return ''
      
      const width = 1000
      const height = 100
      const max = 100 // Percentage 0-100
      
      const points = data.map((d, i) => {
        const x = (i / (data.length - 1 || 1)) * width
        const y = height - (d.value / max) * height
        return `${x.toFixed(1)},${y.toFixed(1)}`
      })
      
      return `M ${points.join(' L ')}`
    },
    copyToClipboard(text) {
      navigator.clipboard
        .writeText(text)
        .then(() => {
          console.log('Copied to clipboard:', text)
        })
        .catch((err) => {
          console.error('Failed to copy:', err)
        })
    },
    servicesSummary() {
      const services = this.routerDetails.services || []
      if (!services.length) return 'No services configured'

      const grouped = services.reduce(
        (acc, svc) => {
          const type = svc.service_type || 'unknown'
          acc[type] = (acc[type] || 0) + 1
          return acc
        },
        {},
      )

      return Object.entries(grouped)
        .map(([type, count]) => `${count} ${type}`)
        .join(', ')
    },
  },
}
</script>

<style>
/* Hide sidebar when graph fullscreen is active */
body.graph-fullscreen-active aside[class*="sidebar"],
body.graph-fullscreen-active aside.fixed.left-0 {
  display: none !important;
}

/* Ensure fullscreen graphs are truly fullscreen */
body.graph-fullscreen-active {
  overflow: hidden;
}
</style>