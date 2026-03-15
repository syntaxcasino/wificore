<template>
  <SlideOverlay
    :model-value="showDetailsOverlay"
    title="Router Details"
    :subtitle="routerDetails.name || 'Complete device information'"
    icon="Wifi"
    width="50%"
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
        <p class="text-gray-500 font-medium">Loading router details...</p>
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
        <p class="text-center text-gray-700 font-medium max-w-md">{{ error }}</p>
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
      <div v-else class="p-4 overflow-y-auto flex-1 bg-gray-50">
        <!-- Status Indicator -->
        <div class="flex items-center justify-between mb-6 p-4 bg-white rounded-xl shadow-sm">
          <div class="flex items-center">
            <div :class="statusClass" class="w-3 h-3 rounded-full mr-3"></div>
            <span class="text-sm font-medium capitalize">{{
              routerDetails.status || 'unknown'
            }}</span>
          </div>
          <span class="text-xs px-2 py-1 rounded-full" :class="statusBadgeClass">{{
            statusText
          }}</span>
        </div>

        <!-- VPN Status Card -->
        <div class="mb-6 bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
              <label class="block text-xs font-medium text-gray-500 mb-1">Connection</label>
              <span
                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wide"
                :class="vpnStatusBadgeClass"
              >
                {{ vpnStatusText }}
              </span>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Last Handshake</label>
              <div class="flex flex-col gap-1">
                <p class="text-gray-900 text-sm">EAT: {{ handshakeEatTime }}</p>
                <p class="text-gray-500 text-xs">UTC: {{ handshakeUtcTime }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Router Details -->
        <div class="space-y-4">
          <!-- Basic Info Card -->
          <div class="bg-white p-5 rounded-xl shadow-sm">
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                <label class="block text-xs font-medium text-gray-500 mb-1">Router Name</label>
                <p class="text-gray-900 font-medium">{{ routerDetails.name || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Location</label>
                <p class="text-gray-900">{{ routerDetails.location || 'Not specified' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">IP Address</label>
                <p class="text-gray-900 font-mono">{{ routerDetails.ip_address || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Port</label>
                <p class="text-gray-900">{{ routerDetails.port || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Router ID</label>
                <p class="text-gray-900">{{ routerDetails.id || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Board Name</label>
                <p class="text-gray-900">{{ routerDetails.resources?.board_name || routerDetails.live_data?.board_name || 'N/A' }}</p>
              </div>
            </div>
          </div>

          <!-- Credentials Card -->
          <div class="bg-white p-5 rounded-xl shadow-sm">
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                <p class="text-gray-900">{{ routerDetails.username || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Password</label>
                <p class="text-gray-900">••••••••</p>
              </div>
            </div>
          </div>

          <!-- Hardware Info Card -->
          <div class="bg-white p-5 rounded-xl shadow-sm">
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                <label class="block text-xs font-medium text-gray-500 mb-1">Model</label>
                <p class="text-gray-900">{{ routerDetails.model || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">OS Version</label>
                <p class="text-gray-900">{{ routerDetails.os_version || 'N/A' }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Serial Number</label>
                <p class="text-gray-900 font-mono text-sm">
                  {{ routerDetails.serial_number || 'N/A' }}
                </p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Firmware</label>
                <p class="text-gray-900">{{ routerDetails.firmware || 'N/A' }}</p>
              </div>
            </div>
          </div>

          <!-- Timestamps Card -->
          <div class="bg-white p-5 rounded-xl shadow-sm">
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                <label class="block text-xs font-medium text-gray-500 mb-1">Created</label>
                <p class="text-gray-900 text-sm">{{ formatDate(routerDetails.created_at) }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Last Seen</label>
                <p class="text-gray-900 text-sm">{{ formatDate(routerDetails.last_seen) }}</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Last Updated</label>
                <p class="text-gray-900 text-sm">{{ formatDate(routerDetails.updated_at) }}</p>
              </div>
            </div>
          </div>

          <!-- Live Data Section -->
          <div v-if="routerDetails.resources || routerDetails.live_data" class="bg-white p-5 rounded-xl shadow-sm">
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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

          <!-- Interfaces Section -->
          <div
            v-if="(routerDetails.interfaces || routerDetails.live_data?.interfaces) && (routerDetails.interfaces || routerDetails.live_data?.interfaces).length"
            class="bg-white p-5 rounded-xl shadow-sm"
          >
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                class="p-3 bg-gray-50 rounded-lg border border-gray-200"
              >
                <div class="flex justify-between items-center">
                  <span class="font-medium text-gray-800">{{ iface.name }}</span>
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
                    <span class="text-gray-500">Type:</span>
                    <span class="ml-1 font-medium">{{ iface.type }}</span>
                  </div>
                  <div>
                    <span class="text-gray-500">MTU:</span>
                    <span class="ml-1 font-medium">{{ iface.mtu }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Configured Services Section -->
          <div
            v-if="groupedServices && groupedServices.length"
            class="bg-white p-5 rounded-xl shadow-sm"
          >
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                class="p-3 bg-gray-50 rounded-lg border border-gray-200 flex flex-col gap-1.5"
              >
                <div class="flex items-center justify-between">
                  <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-800">
                      {{ service.service_name || (service.service_type || '').toUpperCase() || 'Service' }}
                    </span>
                    <span class="text-xs text-gray-500">
                      {{ service.service_type || 'unknown' }}
                    </span>
                  </div>
                  <span
                    class="text-[11px] px-2 py-0.5 rounded-full font-medium"
                    :class="{
                      'bg-green-100 text-green-800': service.status === 'active',
                      'bg-yellow-100 text-yellow-800': service.status === 'pending' || service.status === 'starting',
                      'bg-red-100 text-red-800': service.status === 'error',
                      'bg-gray-100 text-gray-700': !service.status || service.status === 'inactive',
                    }"
                  >
                    {{ service.status || 'inactive' }}
                  </span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-[11px] text-gray-600 mt-1">
                  <div>
                    <span class="font-medium text-gray-500">Interfaces:</span>
                    <span class="ml-1">
                      {{ ((service.interfaces && service.interfaces.length ? service.interfaces : (service.interface_name ? [service.interface_name] : [])) || []).join(', ') || 'N/A' }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-gray-500">VLAN:</span>
                    <span class="ml-1">
                      {{ service.vlan_id || (service.vlan_required ? 'Required' : 'N/A') }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-gray-500">Active Users:</span>
                    <span class="ml-1">
                      {{ service.active_users ?? 0 }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-gray-500">Total Sessions:</span>
                    <span class="ml-1">
                      {{ service.total_sessions ?? 0 }}
                    </span>
                  </div>
                  <div>
                    <span class="font-medium text-gray-500">Deployment:</span>
                    <span class="ml-1">
                      {{ service.deployment_status || 'unknown' }}
                    </span>
                  </div>
                  <div v-if="service.last_checked_at">
                    <span class="font-medium text-gray-500">Last Check:</span>
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
            class="bg-white p-5 rounded-xl shadow-sm"
          >
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                class="p-3 bg-gray-50 rounded-lg border border-gray-200 flex items-center justify-between gap-3"
              >
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-7 h-7 bg-blue-100 rounded-md flex items-center justify-center text-blue-600 text-xs font-semibold">
                    {{ (ap.name || 'AP').charAt(0).toUpperCase() }}
                  </div>
                  <div class="min-w-0">
                    <div class="text-sm font-medium text-gray-900 truncate">
                      {{ ap.name || 'Access Point' }}
                    </div>
                    <div class="text-[11px] text-gray-500 truncate">
                      {{ ap.ip_address || 'No IP' }}
                    </div>
                    <div class="text-[11px] text-gray-400 truncate">
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
                      'bg-gray-100 text-gray-700': !ap.status,
                    }"
                  >
                    {{ ap.status || 'unknown' }}
                  </span>
                  <span class="text-gray-500">
                    {{ ap.vendor || 'Vendor' }}
                    <span v-if="ap.model">· {{ ap.model }}</span>
                  </span>
                  <span v-if="ap.serial_number" class="text-gray-400 font-mono">
                    {{ ap.serial_number }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Configuration Token -->
          <div v-if="routerDetails.config_token" class="bg-white p-5 rounded-xl shadow-sm">
            <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
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
                class="text-xs font-mono text-gray-800 bg-gray-50 p-3 rounded-lg overflow-x-auto border border-gray-200"
                >{{ routerDetails.config_token }}</pre
              >
              <button
                type="button"
                @click="copyToClipboard(routerDetails.config_token)"
                class="absolute top-2 right-2 p-1.5 bg-white rounded-lg shadow-sm text-gray-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
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
            <p class="text-xs text-gray-500 mt-2">
              Use this token to apply configurations on the router
            </p>
          </div>

          <div class="bg-white p-5 rounded-xl shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <h4 class="text-sm font-semibold text-gray-700 flex items-center">
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
                 <div v-if="hoveredData && !['pie', 'donut', 'histogram'].includes(selectedChartType)" class="hidden md:flex items-center gap-3 text-xs bg-gray-50 px-2 py-1 rounded border border-gray-100">
                    <span class="font-mono text-gray-500">{{ formatTime(hoveredData.ts) }}</span>
                    <span class="flex items-center gap-1">
                      <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                      <span class="font-medium text-gray-700">DL: {{ formatBytes(hoveredData.download) }}/s</span>
                    </span>
                    <span class="flex items-center gap-1">
                      <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                      <span class="font-medium text-gray-700">UL: {{ formatBytes(hoveredData.upload) }}/s</span>
                    </span>
                 </div>
                 <select
                    v-model="selectedChartType"
                    class="text-xs border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
                  >
                    <option v-for="t in chartTypes" :key="t.value" :value="t.value">
                      {{ t.label }}
                    </option>
                  </select>
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

              <div v-if="trafficLoading" class="text-xs text-gray-500">Loading traffic…</div>
              <div v-else class="bg-white rounded-lg border border-gray-100 p-4" style="height: 300px;">
                <div class="flex h-full">
                  <!-- Y-Axis -->
                  <div v-if="yAxisTicks.length" class="relative h-full w-14 mr-2 border-r border-gray-100">
                    <div v-for="tick in yAxisTicks" :key="tick.value" 
                         class="absolute right-1 text-[10px] text-gray-400 transform translate-y-1/2"
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
                            class="absolute w-full border-t border-gray-100"
                            :style="{ bottom: tick.percent + '%' }">
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
                            class="absolute top-0 bottom-0 border-l border-gray-400 border-dashed pointer-events-none z-10"
                            :style="{ left: hoverX + '%' }">
                          <!-- Dot indicators -->
                          <div class="absolute w-2 h-2 bg-green-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (hoveredData.download / trafficMax * 100) + '%' }"></div>
                          <div class="absolute w-2 h-2 bg-purple-500 rounded-full -ml-1 border border-white"
                               :style="{ bottom: (hoveredData.upload / trafficMax * 100) + '%' }"></div>
                          
                          <!-- Floating Tooltip -->
                          <div class="absolute top-0 left-2 bg-white/90 backdrop-blur-sm shadow-lg border border-gray-200 rounded p-2 text-xs whitespace-nowrap z-20 pointer-events-none"
                               :class="{ '-translate-x-full -left-2': hoverX > 80 }">
                             <div class="font-mono text-gray-500 mb-1">{{ formatTime(hoveredData.ts) }}</div>
                             <div class="flex items-center gap-2 mb-0.5">
                               <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                               <span class="font-medium text-gray-700">DL: {{ formatBytes(hoveredData.download) }}/s</span>
                             </div>
                             <div class="flex items-center gap-2">
                               <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                               <span class="font-medium text-gray-700">UL: {{ formatBytes(hoveredData.upload) }}/s</span>
                             </div>
                          </div>
                       </div>
                    </div>
                    
                    <!-- X-Axis -->
                    <div v-if="xAxisTicks.length" class="h-6 relative mt-1">
                       <div v-for="tick in xAxisTicks" :key="tick.x" 
                            class="absolute text-[10px] text-gray-400 transform -translate-x-1/2 whitespace-nowrap"
                            :style="{ left: tick.x + '%' }">
                         {{ tick.label }}
                       </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="flex items-center justify-center gap-6 mt-3">
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                  <span class="text-xs text-gray-600">Download</span>
                </div>
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-purple-500 rounded-full"></div>
                  <span class="text-xs text-gray-600">Upload</span>
                </div>
              </div>
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
          class="flex-1 px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
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

const { formatHandshakeDateTime } = useRouterUtils()

export default {
  components: { SlideOverlay },
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
        'bg-gray-500': !status || status === 'unknown',
      }
    },
    statusBadgeClass() {
      const status = this.routerDetails.status?.toLowerCase()
      return {
        'bg-green-100 text-green-800': status === 'online' || status === 'active',
        'bg-red-100 text-red-800': status === 'offline' || status === 'disconnected',
        'bg-yellow-100 text-yellow-800': status === 'pending' || status === 'warning',
        'bg-gray-100 text-gray-800': !status || status === 'unknown',
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

      return data
        .filter((_, i) => i % step === 0 || i === data.length - 1)
        .map((d) => {
          const index = data.indexOf(d)
          const x = (index / (data.length - 1)) * 100
          let label = ''
          try {
            label = new Date(d.ts * 1000).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
            })
          } catch (e) {
            label = ''
          }
          return {
            x,
            label,
          }
        })
    },
  },
  data() {
    return {
      trafficLoading: false,
      trafficError: '',
      trafficData: [],
      trafficStats: {
        current: 0,
        download: 0,
        upload: 0,
      },
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
    }
  },
  beforeUnmount() {
    this.stopAutoRefresh()
  },
  watch: {
    showDetailsOverlay(val) {
      if (val) {
        this.loadTraffic()
        this.startAutoRefresh()
      } else {
        this.stopAutoRefresh()
      }
    },
    selectedRouter() {
      if (this.showDetailsOverlay) {
        this.loadTraffic()
      }
    },
  },
  methods: {
    formatTime(ts) {
      if (!ts) return ''
      try {
        return new Date(ts * 1000).toLocaleTimeString()
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
    startAutoRefresh() {
      this.stopAutoRefresh()
      this.refreshInterval = setInterval(() => {
        this.$emit('refresh-details')
      }, 30000)
    },
    stopAutoRefresh() {
      if (this.refreshInterval) {
        clearInterval(this.refreshInterval)
        this.refreshInterval = null
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
            range: '1h',
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
        this.trafficStats.download = lastPoint.download
        this.trafficStats.upload = lastPoint.upload
        this.trafficStats.current = lastPoint.download + lastPoint.upload

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