<template>
  <transition
    v-if="showDetailsOverlay"
    enter-active-class="transition-transform duration-300 ease-out"
    enter-from-class="translate-x-full"
    enter-to-class="translate-x-0"
    leave-active-class="transition-transform duration-300 ease-in"
    leave-from-class="translate-x-0"
    leave-to-class="translate-x-full"
  >
    <div
      class="fixed inset-y-0 right-0 z-[9999] w-full sm:w-2/3 lg:w-1/2 xl:w-2/5 bg-white shadow-2xl flex flex-col"
    >
      <!-- Header -->
      <div
        class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex-shrink-0"
      >
        <div class="flex items-center gap-2">
          <div class="p-1.5 bg-blue-100 rounded-lg">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="h-4 w-4 text-blue-600"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"
              />
            </svg>
          </div>
          <div>
            <h3 class="text-base font-semibold text-gray-800">Router Details</h3>
            <p class="text-xs text-gray-500">{{ routerDetails.name || 'Complete device information' }}</p>
          </div>
        </div>
        <button
          type="button"
          @click="$emit('close-details')"
          class="p-1.5 rounded-lg hover:bg-white transition-colors text-gray-500 hover:text-gray-700"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-4 h-4"
            viewBox="0 0 20 20"
            fill="currentColor"
          >
            <path
              fill-rule="evenodd"
              d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
              clip-rule="evenodd"
            />
          </svg>
        </button>
      </div>

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
                <p class="text-gray-900">{{ routerDetails.live_data?.board_name || 'N/A' }}</p>
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
          <div v-if="routerDetails.live_data" class="bg-white p-5 rounded-xl shadow-sm">
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
            <div v-if="routerDetails.live_data.error" class="bg-red-50 p-4 rounded-lg">
              <p class="text-red-700 text-sm">{{ routerDetails.live_data.error }}</p>
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-blue-600 mb-1">CPU Load</label>
                <p class="text-blue-800 font-bold text-lg">
                  {{ routerDetails.live_data.cpu_load ?? 'N/A' }}%
                </p>
              </div>
              <div class="bg-gradient-to-br from-green-50 to-green-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-green-600 mb-1">Memory</label>
                <p class="text-green-800 font-bold text-sm">
                  {{ formatBytes(routerDetails.live_data.free_memory) }} /
                  {{ formatBytes(routerDetails.live_data.total_memory) }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-purple-600 mb-1">Uptime</label>
                <p class="text-purple-800 font-bold text-sm">
                  {{ routerDetails.live_data.uptime || 'N/A' }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-amber-50 to-amber-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-amber-600 mb-1">Interfaces</label>
                <p class="text-amber-800 font-bold text-lg">
                  {{ routerDetails.live_data.interface_count ?? (routerDetails.live_data.interfaces ? routerDetails.live_data.interfaces.length : 0) }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-cyan-600 mb-1"
                  >Active Connections</label
                >
                <p class="text-cyan-800 font-bold text-lg">
                  {{ routerDetails.live_data.active_connections ?? 0 }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-pink-50 to-pink-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-pink-600 mb-1">DHCP Leases</label>
                <p class="text-pink-800 font-bold text-lg">
                  {{ routerDetails.live_data.dhcp_leases ?? 0 }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-teal-50 to-teal-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-teal-600 mb-1">Storage</label>
                <p class="text-teal-800 font-bold text-sm">
                  {{ formatBytes(getDiskUsed(routerDetails.live_data)) }} /
                  {{ formatBytes(routerDetails.live_data.total_hdd_space) }}
                </p>
              </div>
              <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 p-3 rounded-lg">
                <label class="block text-xs font-medium text-indigo-600 mb-1">Identity</label>
                <p class="text-indigo-800 font-bold text-sm">
                  {{ routerDetails.live_data.identity || 'N/A' }}
                </p>
              </div>
            </div>
          </div>

          <!-- Interfaces Section -->
          <div
            v-if="routerDetails.live_data && routerDetails.live_data.interfaces"
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
                v-for="(iface, index) in routerDetails.live_data.interfaces"
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
                  d="M4 19v-6m4 6V5m4 14v-9m4 9V9m4 10V7"
                />
              </svg>
              Traffic
            </h4>

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
              <div v-else class="h-40 bg-gray-50 rounded-lg flex items-end justify-between p-3 gap-1">
                <div v-for="(point, i) in trafficData" :key="i" class="flex-1 flex flex-col items-center justify-end gap-1">
                  <div class="w-full bg-green-500 rounded-t transition-all" :style="{ height: trafficMax > 0 ? (point.download / trafficMax * 100) + '%' : '0%' }"></div>
                  <div class="w-full bg-purple-500 rounded-t transition-all" :style="{ height: trafficMax > 0 ? (point.upload / trafficMax * 100) + '%' : '0%' }"></div>
                </div>
              </div>
              <div class="flex items-center justify-center gap-6 mt-3">
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-green-500 rounded"></div>
                  <span class="text-xs text-gray-600">Download</span>
                </div>
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 bg-purple-500 rounded"></div>
                  <span class="text-xs text-gray-600">Upload</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer Buttons -->
      <div class="border-t border-gray-200 bg-white px-4 py-2.5 flex justify-between gap-3 flex-shrink-0">
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
    </div>
  </transition>
</template>

<script>
import axios from 'axios'

export default {
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

          existing.active_users = (existing.active_users || 0) + (svc.active_users ?? 0)
          existing.total_sessions = (existing.total_sessions || 0) + (svc.total_sessions ?? 0)

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
      return Math.max(...this.trafficData.map((d) => (d.download || 0) + (d.upload || 0)), 1)
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
    }
  },
  watch: {
    showDetailsOverlay(val) {
      if (val) {
        this.loadTraffic()
      }
    },
    selectedRouter() {
      if (this.showDetailsOverlay) {
        this.loadTraffic()
      }
    },
  },
  methods: {
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

      if (!Number.isFinite(numeric) || numeric < 0) return 'N/A'

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
      const result = vmResponse?.data?.result
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

      this.trafficLoading = true
      this.trafficError = ''

      try {
        const response = await axios.get(`/routers/${routerId}/metrics/traffic`, {
          params: {
            range: '1h',
            step: '30s',
          },
        })

        const data = response.data || {}
        if (!data.success) {
          this.trafficError = data.error || 'Failed to load traffic'
          this.trafficData = []
          return
        }

        const inSeries = this.parseVmSeriesValues(data.in)
        const outSeries = this.parseVmSeriesValues(data.out)
        const maxLen = Math.max(inSeries.length, outSeries.length)
        const points = []

        for (let i = 0; i < maxLen; i++) {
          points.push({
            download: inSeries[i]?.v ?? 0,
            upload: outSeries[i]?.v ?? 0,
          })
        }

        this.trafficData = points.slice(-60)

        const currentIn = this.getLastValue(inSeries)
        const currentOut = this.getLastValue(outSeries)
        this.trafficStats.download = currentIn
        this.trafficStats.upload = currentOut
        this.trafficStats.current = currentIn + currentOut
      } catch (err) {
        this.trafficError = err.response?.data?.error || err.message || 'Failed to load traffic'
        this.trafficData = []
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