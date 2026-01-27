<template>
  <div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg overflow-hidden">
    <!-- Header -->
    <div class="flex-shrink-0 bg-white border-b border-slate-200 shadow-sm relative">
      <!-- Top Bar -->
      <div class="px-4 md:px-6 py-3 md:py-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 md:gap-6">
          <!-- Left: Title & Icon -->
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 md:w-11 md:h-11 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                </svg>
              </div>
              <div>
                <h2 class="text-lg md:text-xl font-bold text-slate-900">Router Management</h2>
                <p class="text-xs text-slate-500 mt-0.5 hidden md:block">Monitor and configure your network infrastructure</p>
              </div>
            </div>
            
            <!-- Mobile: Quick Stats -->
            <div class="flex md:hidden items-center gap-2 px-2 py-1.5 bg-slate-50 rounded-lg border border-slate-200">
              <div class="flex items-center gap-1">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span class="text-xs font-semibold text-slate-700">{{ onlineCount }}</span>
              </div>
              <span class="text-slate-300 text-xs">|</span>
              <div class="flex items-center gap-1">
                <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                <span class="text-xs font-semibold text-slate-700">{{ offlineCount }}</span>
              </div>
            </div>
          </div>
          
          <!-- Center: Search Bar (Desktop only) -->
          <div class="hidden md:block flex-1 max-w-xl">
            <div class="relative">
              <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
              </div>
              <input 
                v-model="searchQuery"
                type="text" 
                placeholder="Search routers by name, IP address, or model..." 
                class="w-full py-2.5 pl-10 pr-10 text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white focus:outline-none transition-all placeholder:text-slate-400"
              >
              <div v-if="searchQuery" class="absolute inset-y-0 right-0 flex items-center pr-3">
                <button @click="searchQuery = ''" class="text-slate-400 hover:text-slate-600 transition-colors p-1 hover:bg-slate-100 rounded">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                  </svg>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Right: Stats & Actions -->
          <div class="flex items-center justify-between md:justify-end gap-2 md:gap-3">
            <!-- Desktop: Quick Stats -->
            <div class="hidden md:flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                <span class="text-xs font-semibold text-slate-700">{{ onlineCount }}</span>
              </div>
              <span class="text-slate-300">|</span>
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 bg-slate-400 rounded-full"></span>
                <span class="text-xs font-semibold text-slate-700">{{ offlineCount }}</span>
              </div>
              <span class="text-slate-300">|</span>
              <span class="text-xs font-semibold text-blue-600">{{ routers.length }}</span>
            </div>
            
            <!-- Action Buttons -->
            <button @click="fetchRouters" :disabled="loading || refreshing"
              class="inline-flex items-center gap-1.5 px-2 md:px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
              <svg xmlns="http://www.w3.org/2000/svg" :class="(loading || refreshing) ? 'animate-spin' : ''" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                  d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"
                  clip-rule="evenodd" />
              </svg>
              <span class="hidden md:inline">Refresh</span>
            </button>
            <button @click="openCreateOverlay"
              class="inline-flex items-center gap-1.5 px-3 md:px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                  d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"
                  clip-rule="evenodd" />
              </svg>
              <span class="hidden sm:inline">Add Router</span>
              <span class="sm:hidden">Add</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Area -->
    <div class="flex-1 min-h-0 overflow-y-auto md:overflow-hidden">
      <!-- Overlays (keep mounted so provisioning flow doesn't reset during list refresh/loading) -->
      <DetailsOverlay 
        :show-details-overlay="showDetailsOverlay" 
        :selected-router="currentRouter"
        :loading="detailsLoading"
        :error="detailsError"
        :refreshing="refreshing"
        @close-details="closeDetails" 
        @refresh-details="refreshDetails" 
      />
      <Overlay 
        :show-form-overlay="showFormOverlay" 
        :loading="false" 
        :form-error="formError"
        @close-form="closeFormOverlay"
        @retry="fetchRouters" 
        @refresh-routers="fetchRouters" 
      />
      <UpdateOverlay 
        :show-update-overlay="showUpdateOverlay" 
        :selected-router="selectedRouter" 
        :form-data="formData"
        :form-submitting="formSubmitting" 
        :form-message="formMessage" 
        :form-submitted="formSubmitted"
        :config-token="formData.config_token" 
        :config-loading="configLoading" 
        :error="formError"
        :format-timestamp="formatTimestamp" 
        @close-update="closeUpdateOverlay" 
        @generate-configs="generateConfigs"
        @copy-token="copyToClipboard" 
        @update-router="handleFormSubmit" 
        @retry="fetchRouters" 
      />

      <!-- Loading Skeleton -->
      <div v-if="loading" class="p-6 space-y-4">
      <div class="animate-pulse space-y-4">
        <div v-for="i in 5" :key="i" class="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
          <div class="flex items-center space-x-4">
            <div class="w-12 h-12 bg-gray-200 rounded-lg"></div>
            <div class="flex-1 space-y-2">
              <div class="h-4 bg-gray-200 rounded w-1/4"></div>
              <div class="h-3 bg-gray-200 rounded w-1/3"></div>
            </div>
            <div class="flex gap-2">
              <div class="w-20 h-8 bg-gray-200 rounded"></div>
              <div class="w-8 h-8 bg-gray-200 rounded"></div>
            </div>
          </div>
        </div>
      </div>
      </div>

      <!-- Error -->
      <div v-else-if="listError" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ listError }}</p>
      <button @click="fetchRouters"
        class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
      </div>
    
      <div v-else class="flex flex-col min-h-0">
      <!-- Routers Table Container -->
      <div v-if="filteredRouters.length" class="px-4 md:px-6 pt-4 md:pt-6 pb-2 flex flex-col min-h-0">
        <!-- Mobile Cards -->
        <div class="md:hidden space-y-3">
          <div
            v-for="router in paginatedRouters"
            :key="router.id"
            class="bg-white rounded-lg border border-slate-200 shadow-sm p-4 cursor-pointer active:scale-[0.99] transition-transform"
            @click="openDetails(router)"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2 min-w-0">
                  <span :class="getStatusDotClass(router.status)" class="w-2 h-2 rounded-full flex-shrink-0"></span>
                  <div class="text-sm font-semibold text-slate-900 truncate">{{ router.name }}</div>
                </div>
                <div class="mt-1 text-xs text-slate-600 truncate">{{ router.ip_address || 'No IP' }}</div>
                <div class="mt-1 text-xs text-slate-500 truncate">{{ formatModel(getRouterModel(router)) }}</div>
              </div>
              <span :class="statusBadgeClass(router.status)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize flex-shrink-0">
                {{ router.status || 'Unknown' }}
              </span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3">
              <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
                <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">CPU</div>
                <div v-if="router.live_data?.cpu_load !== undefined && router.live_data?.cpu_load !== null" class="mt-1 flex items-center gap-2">
                  <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="getCpuColorClass(Number(router.live_data.cpu_load))"
                      :style="{ width: String(router.live_data.cpu_load) + '%' }"
                    ></div>
                  </div>
                  <div class="text-xs font-medium text-slate-700 w-10 text-right">{{ router.live_data.cpu_load }}%</div>
                </div>
                <div v-else class="mt-1 text-xs text-slate-400">—</div>
              </div>

              <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
                <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Users</div>
                <div class="mt-1 flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                  </svg>
                  <div v-if="getConnectedUsers(router) !== null" class="text-xs font-medium text-slate-700">{{ getConnectedUsers(router) }}</div>
                  <div v-else class="text-xs text-slate-400">—</div>
                </div>
              </div>

              <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
                <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Memory</div>
                <div v-if="getMemoryUsage(router) !== null" class="mt-1 flex items-center gap-2">
                  <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="getMemoryColorClass(getMemoryUsage(router))"
                      :style="{ width: getMemoryUsage(router) + '%' }"
                    ></div>
                  </div>
                  <div class="text-xs font-medium text-slate-700 w-10 text-right">{{ getMemoryUsage(router) }}%</div>
                </div>
                <div v-else class="mt-1 text-xs text-slate-400">—</div>
              </div>

              <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
                <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Disk</div>
                <div v-if="getDiskUsage(router) !== null" class="mt-1 flex items-center gap-2">
                  <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="getDiskColorClass(getDiskUsage(router))"
                      :style="{ width: getDiskUsage(router) + '%' }"
                    ></div>
                  </div>
                  <div class="text-xs font-medium text-slate-700 w-10 text-right">{{ getDiskUsage(router) }}%</div>
                </div>
                <div v-else class="mt-1 text-xs text-slate-400">—</div>
              </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-2" @click.stop>
              <button
                @click="loginToRouter(router)"
                :disabled="router.status !== 'online'"
                class="px-3 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 rounded-md hover:bg-emerald-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Login
              </button>
              <button
                @click="openDetails(router)"
                class="px-3 py-2 text-xs font-medium text-blue-700 bg-blue-50 rounded-md hover:bg-blue-100 transition-colors"
              >
                View
              </button>
            </div>
          </div>
        </div>

        <!-- Desktop Table -->
        <div class="hidden md:flex bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0">
          <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
            <table class="w-full">
              <!-- Table Header -->
              <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">
                    <div class="flex items-center gap-2">
                      <div class="w-7 h-7"></div>
                      <span>Router</span>
                    </div>
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">IP Address</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">CPU</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Memory</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Disk</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Users</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Model</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Last Seen</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              
              <!-- Table Body - Scrollable -->
              <tbody class="divide-y divide-slate-100">
                <tr 
                  v-for="router in paginatedRouters" 
                  :key="router.id" 
                  class="hover:bg-blue-50/50 transition-colors cursor-pointer group"
                  @click="openDetails(router)"
                >
                  <!-- Router Name with Icon -->
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-7 h-7 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-md flex items-center justify-center text-white flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                        </svg>
                      </div>
                      <div class="flex items-center gap-1.5 min-w-0">
                        <span :class="getStatusDotClass(router.status)" class="w-1.5 h-1.5 rounded-full flex-shrink-0"></span>
                        <span class="text-sm font-semibold text-slate-900 truncate">{{ router.name }}</span>
                      </div>
                    </div>
                  </td>
                  
                  <!-- IP Address -->
                  <td class="px-6 py-4 hidden lg:table-cell">
                    <div class="flex items-center gap-1.5">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                      </svg>
                      <span class="text-xs text-slate-600 truncate">{{ router.ip_address || 'No IP' }}</span>
                    </div>
                  </td>
                  
                  <!-- Status -->
                  <td class="px-6 py-4">
                    <span :class="statusBadgeClass(router.status)"
                      class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">
                      {{ router.status || 'Unknown' }}
                    </span>
                  </td>
                  
                  <!-- CPU Usage -->
                  <td class="px-6 py-4 hidden xl:table-cell">
                    <div v-if="router.live_data?.cpu_load !== undefined && router.live_data?.cpu_load !== null" class="flex items-center gap-1.5">
                      <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                        <div 
                          class="h-full rounded-full transition-all"
                          :class="getCpuColorClass(router.live_data.cpu_load)"
                          :style="{ width: router.live_data.cpu_load + '%' }"
                        ></div>
                      </div>
                      <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ router.live_data.cpu_load }}%</span>
                    </div>
                    <span v-else class="text-xs text-slate-400">—</span>
                  </td>
                  
                  <!-- Memory Usage -->
                  <td class="px-6 py-4 hidden xl:table-cell">
                    <div v-if="getMemoryUsage(router) !== null" class="flex items-center gap-1.5">
                      <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                        <div 
                          class="h-full rounded-full transition-all"
                          :class="getMemoryColorClass(getMemoryUsage(router))"
                          :style="{ width: getMemoryUsage(router) + '%' }"
                        ></div>
                      </div>
                      <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ getMemoryUsage(router) }}%</span>
                    </div>
                    <span v-else class="text-xs text-slate-400">—</span>
                  </td>
                  
                  <!-- Disk Usage -->
                  <td class="px-6 py-4 hidden xl:table-cell">
                    <div v-if="getDiskUsage(router) !== null" class="flex items-center gap-1.5">
                      <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                        <div 
                          class="h-full rounded-full transition-all"
                          :class="getDiskColorClass(getDiskUsage(router))"
                          :style="{ width: getDiskUsage(router) + '%' }"
                        ></div>
                      </div>
                      <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ getDiskUsage(router) }}%</span>
                    </div>
                    <span v-else class="text-xs text-slate-400">—</span>
                  </td>
                  
                  <!-- Connected Users -->
                  <td class="px-6 py-4 hidden xl:table-cell">
                    <div class="flex items-center gap-1 text-xs">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                      </svg>
                      <span v-if="getConnectedUsers(router) !== null" class="font-medium text-slate-700">{{ getConnectedUsers(router) }}</span>
                      <span v-else class="text-slate-400">—</span>
                    </div>
                  </td>
                  
                  <!-- Model -->
                  <td class="px-6 py-4 hidden lg:table-cell">
                    <span v-if="getRouterModel(router)" class="text-xs text-slate-500 truncate" :title="getRouterModel(router)">{{ formatModel(getRouterModel(router)) }}</span>
                    <span v-else class="text-xs text-slate-400">—</span>
                  </td>
                  
                  <!-- Last Seen -->
                  <td class="px-6 py-4 hidden lg:table-cell">
                    <span v-if="router.last_updated || router.last_seen" class="text-xs text-slate-500 truncate">{{ formatTimeAgo(router.last_updated || router.last_seen) }}</span>
                    <span v-else class="text-xs text-slate-400">—</span>
                  </td>
                  
                  <!-- Actions -->
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <button @click="loginToRouter(router)" :disabled="router.status !== 'online'"
                        class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors inline-flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed"
                        :title="router.status !== 'online' ? 'Router must be online to login' : 'Login to router via Winbox/WebFig'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                        </svg>
                        Login
                      </button>
                      
                      <button @click="openDetails(router)"
                        class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors inline-flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                          <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        View
                      </button>

                      <div class="relative">
                        <button 
                          data-menu-button
                          @click="toggleMenu(router.id, $event)"
                          class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                          </svg>
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="mt-3 bg-white rounded-lg border border-slate-200 shadow-sm px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div class="text-sm text-slate-600">
            Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} routers
          </div>

          <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <div class="flex items-center gap-2">
              <span class="text-sm text-slate-600">Show:</span>
              <select
                v-model.number="itemsPerPage"
                class="h-9 px-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:outline-none"
              >
                <option v-for="opt in itemsPerPageOptions" :key="opt" :value="opt">{{ opt }}</option>
              </select>
            </div>

            <div class="flex items-center gap-1.5">
              <button
                type="button"
                @click="goToFirstPage"
                :disabled="currentPage <= 1"
                class="h-9 px-3 text-sm font-medium bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                First
              </button>
              <button
                type="button"
                @click="goToPreviousPage"
                :disabled="currentPage <= 1"
                class="h-9 px-3 text-sm font-medium bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span class="sr-only">Previous</span>
                &lt;
              </button>
              <div class="h-9 px-3 flex items-center text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-lg">
                {{ currentPage }} / {{ totalPages || 1 }}
              </div>
              <button
                type="button"
                @click="goToNextPage"
                :disabled="currentPage >= totalPages"
                class="h-9 px-3 text-sm font-medium bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span class="sr-only">Next</span>
                &gt;
              </button>
              <button
                type="button"
                @click="goToLastPage"
                :disabled="currentPage >= totalPages"
                class="h-9 px-3 text-sm font-medium bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Last
              </button>
            </div>
          </div>
        </div>
        
        <!-- Global Dropdown Menu Portal -->
        <Teleport to="body">
          <div 
            v-if="activeMenu !== null" 
            data-dropdown-menu
            :style="menuPosition"
            class="fixed w-48 bg-white rounded-lg shadow-2xl border border-slate-200 py-1 z-[9999] overflow-hidden"
          >
            <button @click="handleEdit(routers.find(r => r.id === activeMenu))"
              class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Edit Router
            </button>
            <button @click="handleReProv(routers.find(r => r.id === activeMenu))" :disabled="formSubmitting"
              class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              Reprovision
            </button>
            <div class="border-t border-slate-200 my-1"></div>
            <button @click="handleDelete(routers.find(r => r.id === activeMenu))"
              class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
              Delete Router
            </button>
          </div>
        </Teleport>
      </div>

      <!-- Empty State -->
      <div v-if="!filteredRouters.length" class="flex flex-col items-center justify-center gap-6 p-12 text-center">
        <div class="relative">
          <div class="absolute inset-0 bg-blue-100 rounded-full blur-3xl opacity-30"></div>
          <div class="relative w-32 h-32 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl flex items-center justify-center shadow-xl">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
          </div>
        </div>
        <div class="space-y-2">
          <h3 class="text-2xl font-bold text-slate-900">No Routers Found</h3>
          <p class="text-slate-600 max-w-md">
            {{ searchQuery ? 'No routers match your search criteria. Try adjusting your filters.' : 'Get started by adding your first router to begin managing your network infrastructure.' }}
          </p>
        </div>
        <div class="flex gap-3">
          <button v-if="searchQuery" @click="searchQuery = ''"
            class="px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-all shadow-sm">
            Clear Search
          </button>
          <button @click="openCreateOverlay"
            class="px-5 py-2.5 text-sm font-medium text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            Add Your First Router
          </button>
        </div>
      </div>

    </div>

    </div>

  </div>
</template>

<script>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useConfirmStore } from '@/stores/confirm'
import { useRouterUtils } from '@/modules/common/composables/utils/useRouterUtils'
import Overlay from '@/modules/tenant/components/routers/modals/CreateRouterModal.vue'
import UpdateOverlay from '@/modules/tenant/components/routers/modals/UpdateRouterModal.vue'
import DetailsOverlay from '@/modules/tenant/components/routers/modals/RouterDetailsModal.vue'

export default {
  name: 'RouterManagement',
  components: {
    Overlay,
    UpdateOverlay,
    DetailsOverlay,
  },
  setup() {
    const {
      routers,
      loading,
      refreshing,
      listError,
      formError,
      detailsError,
      detailsLoading,
      showFormOverlay,
      showDetailsOverlay,
      showUpdateOverlay,
      currentRouter,
      isEditing,
      selectedRouter,
      formData,
      formSubmitting,
      currentStep,
      steps,
      configLoading,
      connectivityVerified,
      availableInterfaces,
      configurationProgress,
      formMessage,
      formSubmitted,
      fetchRouters,
      verifyConnectivity,
      addRouter,
      editRouter,
      updateRouter,
      deleteRouter,
      generateConfigs,
      applyConfigurations,
      formatTimestamp,
      statusBadgeClass,
      openCreateOverlay,
      openEditOverlay,
      openDetails,
      closeDetails,
      refreshDetails,
      closeFormOverlay,
      closeUpdateOverlay,
      nextStep,
      previousStep,
      copyToClipboard,
      updateInterfaceAssignments,
      updateFormData,
    } = useRouters()

    const confirmStore = useConfirmStore()

    const activeMenu = ref(null)
    const menuPosition = ref({})
    const currentYear = ref(new Date().getFullYear())
    const searchQuery = ref('')
    const lastUpdateTime = ref(new Date().toLocaleTimeString())
    const currentPage = ref(1)
    const itemsPerPage = ref(10)
    const itemsPerPageOptions = [10, 25, 50, 100]
    let updateInterval = null
    
    // Router utility functions
    const {
      getStatusDotClass,
      getCpuColorClass,
      getMemoryColorClass,
      getDiskColorClass,
      getMemoryUsage,
      getDiskUsage,
      getRouterModel,
      formatModel,
      getConnectedUsers,
      formatTimeAgo,
    } = useRouterUtils()
    
    const normalizeName = (router) => String(router?.name ?? '').trim()
    const normalizeId = (router) => String(router?.id ?? '')

    const parseIp = (ipAddress) => {
      const ip = String(ipAddress ?? '').split('/')[0].trim()
      if (!ip) return null
      const parts = ip.split('.').map((p) => Number(p))
      if (parts.length !== 4 || parts.some((n) => Number.isNaN(n))) return null
      return parts
    }

    const compareIp = (aIp, bIp) => {
      if (!aIp && !bIp) return 0
      if (!aIp) return 1
      if (!bIp) return -1
      for (let i = 0; i < 4; i++) {
        if (aIp[i] !== bIp[i]) return aIp[i] - bIp[i]
      }
      return 0
    }

    // Computed properties
    const filteredRouters = computed(() => {
      let filtered = routers.value
      
      // Apply search filter if query exists
      if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase()
        filtered = routers.value.filter(router => 
          (router.name && router.name.toLowerCase().includes(query)) ||
          (router.ip_address && router.ip_address.includes(query)) ||
          (router.model && router.model.toLowerCase().includes(query))
        )
      }
      
      // Sort deterministically so order does not reshuffle between refreshes
      return [...filtered].sort((a, b) => {
        const byName = normalizeName(a).localeCompare(normalizeName(b), undefined, { numeric: true, sensitivity: 'base' })
        if (byName !== 0) return byName

        const byIp = compareIp(parseIp(a?.ip_address), parseIp(b?.ip_address))
        if (byIp !== 0) return byIp

        return normalizeId(a).localeCompare(normalizeId(b), undefined, { numeric: true, sensitivity: 'base' })
      })
    })
    
    const paginatedRouters = computed(() => {
      const start = (currentPage.value - 1) * itemsPerPage.value
      const end = start + itemsPerPage.value
      return filteredRouters.value.slice(start, end)
    })
    
    const totalPages = computed(() => {
      return Math.ceil(filteredRouters.value.length / itemsPerPage.value)
    })
    
    const paginationInfo = computed(() => {
      const total = filteredRouters.value.length
      if (total === 0) {
        return { start: 0, end: 0, total: 0 }
      }
      const start = (currentPage.value - 1) * itemsPerPage.value + 1
      const end = Math.min(start + itemsPerPage.value - 1, total)
      return { start, end, total }
    })
    
    // Reset to page 1 when search changes
    watch(searchQuery, () => {
      currentPage.value = 1
    })
    
    // Reset to page 1 when items per page changes
    watch(itemsPerPage, () => {
      currentPage.value = 1
    })

    watch(totalPages, (pages) => {
      const safePages = pages || 1
      if (currentPage.value > safePages) {
        currentPage.value = safePages
      }
      if (currentPage.value < 1) {
        currentPage.value = 1
      }
    })

    const goToFirstPage = () => {
      currentPage.value = 1
    }

    const goToPreviousPage = () => {
      currentPage.value = Math.max(1, currentPage.value - 1)
    }

    const goToNextPage = () => {
      currentPage.value = Math.min(totalPages.value || 1, currentPage.value + 1)
    }

    const goToLastPage = () => {
      currentPage.value = totalPages.value || 1
    }
    
    const onlineCount = computed(() => 
      routers.value.filter(r => r.status === 'online').length
    )
    
    const offlineCount = computed(() => 
      routers.value.filter(r => !r.status || r.status === 'offline').length
    )
    
    // Update last update time
    const updateLastUpdateTime = () => {
      lastUpdateTime.value = new Date().toLocaleTimeString()
    }

    const toggleMenu = (routerId, event) => {
      event.stopPropagation()
      
      if (activeMenu.value === routerId) {
        activeMenu.value = null
        menuPosition.value = {}
      } else {
        activeMenu.value = routerId
        const button = event.currentTarget
        const rect = button.getBoundingClientRect()

        // Calculate position to place the menu below and to the left of the button
        const menuWidth = 192 // w-48 = 12rem = 192px
        const menuHeight = 140
        const viewportHeight = window.innerHeight
        const viewportWidth = window.innerWidth
        
        let top = rect.bottom + 4
        let left = rect.right - menuWidth
        
        // Adjust if menu would overflow viewport bottom
        if (rect.bottom + menuHeight > viewportHeight) {
          top = rect.top - menuHeight - 4
        }
        
        // Adjust if menu would overflow viewport left
        if (left < 0) {
          left = rect.left
        }
        
        // Adjust if menu would overflow viewport right
        if (left + menuWidth > viewportWidth) {
          left = viewportWidth - menuWidth - 10
        }

        menuPosition.value = {
          top: `${top}px`,
          left: `${left}px`,
        }
      }
    }

    const handleEdit = (router) => {
      openEditOverlay(router)
      activeMenu.value = null
      menuPosition.value = {}
    }

    const handleReProv = (router) => {
      handleReprovision(router)
      activeMenu.value = null
      menuPosition.value = {}
    }

    const handleDelete = (router) => {
      handleDeleteRouter(router)
      activeMenu.value = null
      menuPosition.value = {}
    }

    const handleClickOutside = (event) => {
      if (activeMenu.value !== null) {
        const isMenuButton = event.target.closest('[data-menu-button]')
        const isDropdownMenu = event.target.closest('[data-dropdown-menu]')
        
        if (!isMenuButton && !isDropdownMenu) {
          activeMenu.value = null
          menuPosition.value = {}
        }
      }
    }

    const loginToRouter = (router) => {
      if (router.status !== 'online') {
        alert('Router must be online to login')
        return
      }

      // Extract IP address (remove subnet mask if present)
      const ipAddress = router.ip_address?.split('/')[0] || router.ip_address

      if (!ipAddress) {
        alert('Router IP address not available')
        return
      }

      // Open WebFig (MikroTik web interface) in new tab
      const webfigUrl = `http://${ipAddress}`
      window.open(webfigUrl, '_blank', 'noopener,noreferrer')
    }

    const handleDeleteRouter = async (router) => {
      const confirmed = await confirmStore.open({
        title: 'Confirm Deletion',
        message: `Are you sure you want to delete router ${router.name}?`,
        confirmText: 'Delete',
        cancelText: 'Cancel',
        variant: 'danger',
      })

      if (confirmed) {
        try {
          await deleteRouter(router.id)
          formMessage.value = {
            text: `Router ${router.name} deleted successfully`,
            type: 'success',
          }
        } catch (err) {
          formMessage.value = { text: `Failed to delete router: ${err.message}`, type: 'error' }
        }
      }
    }

    const handleFormSubmit = async () => {
      formSubmitting.value = true
      try {
        if (isEditing.value) {
          await updateRouter()
        } else {
          await addRouter()
        }
      } catch (err) {
        // Form submission error
      } finally {
        formSubmitting.value = false
      }
    }

    const handleReprovision = async (router) => {
      const confirmed = await confirmStore.open({
        title: 'Confirm Reprovision',
        message: `Are you sure you want to reprovision router ${router.name}? This will reapply the existing configurations.`,
        confirmText: 'Reprovision',
        cancelText: 'Cancel',
        variant: 'warning',
      })

      if (confirmed) {
        formData.value = {
          ...router,
          port: router.port || null,
          username: router.username || '',
          password: router.password || '',
          location: router.location || '',
          interface_assignments: router.interface_assignments || [],
          interface_services: router.interface_services || {},
          configurations: router.configurations || {},
          connectivity_script: router.connectivity_script || '',
          service_script: router.service_script || '',
          config_token: router.config_token || '',
        }
        try {
          await applyConfigurations()
          formMessage.value = {
            text: `Router ${router.name} reprovisioned successfully`,
            type: 'success',
          }
          await fetchRouters()
        } catch (err) {
          formMessage.value = {
            text: `Failed to reprovision router: ${err.message}`,
            type: 'error',
          }
        }
      }
    }

    watch(showDetailsOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : ''
      if (open) {
        activeMenu.value = null
        menuPosition.value = {}
      }
    })

    watch(showFormOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : ''
      if (open) {
        activeMenu.value = null
        menuPosition.value = {}
      }
    })

    watch(showUpdateOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : ''
      if (open) {
        activeMenu.value = null
        menuPosition.value = {}
      }
    })

    onMounted(() => {
      // Update last update time every minute (purely cosmetic)
      updateInterval = setInterval(updateLastUpdateTime, 60000)

      // Subscribe to tenant-specific private channel for router updates (requires authentication)
      const authToken = localStorage.getItem('authToken');
      const user = JSON.parse(localStorage.getItem('user') || '{}');
      if (authToken && user.tenant_id) {
        try {
          // Use tenant-specific channels for security isolation
          const routerUpdatesChannel = window.Echo.private(`tenant.${user.tenant_id}.router-updates`);

          // Listen for router events
          routerUpdatesChannel
            .listen('.RouterLiveDataUpdated', (e) => {
              const idx = routers.value.findIndex((r) => r.id === e.router_id);
              if (idx !== -1) {
                routers.value[idx].live_data = e.data;
                routers.value[idx].last_updated = e.timestamp;
              }
            })
            .listen('.RouterStatusUpdated', (e) => {
              // Event contains an array of routers
              if (e.routers && Array.isArray(e.routers)) {
                e.routers.forEach((updatedRouter) => {
                  const idx = routers.value.findIndex((r) => r.id === updatedRouter.id);
                  if (idx !== -1) {
                    routers.value[idx].status = updatedRouter.status;
                    routers.value[idx].model = updatedRouter.model || routers.value[idx].model;
                    routers.value[idx].os_version = updatedRouter.os_version || routers.value[idx].os_version;
                    routers.value[idx].last_updated = new Date().toISOString();
                  }
                });
              }
            })
            .listen('.RouterConnected', (e) => {
              const idx = routers.value.findIndex((r) => r.id === e.router_id);
              if (idx !== -1) {
                routers.value[idx].status = 'connected';
                routers.value[idx].last_updated = e.timestamp;
              }
            })
            .listen('.LogRotationCompleted', (e) => {
              const idx = routers.value.findIndex((r) => r.id === e.router_id);
              if (idx !== -1) {
                routers.value[idx].config = e.config;
                routers.value[idx].last_updated = e.timestamp;
              }
            });

          // Also subscribe to tenant-specific routers channel for status updates
          const statusChannel = window.Echo.private(`tenant.${user.tenant_id}.routers`);
          statusChannel
            .listen('.router.status.changed', (e) => {
              const idx = routers.value.findIndex((r) => r.id === e.router_id);
              if (idx !== -1) {
                routers.value[idx].status = e.status;
              }
            });
        } catch (err) {
          // Could not subscribe to private channels
          console.error('Failed to subscribe to private router channels:', err);
        }
      } else {
        console.warn('User not authenticated or no tenant_id - cannot subscribe to router updates');
      }

      document.addEventListener('click', handleClickOutside)
      // Initial load only; subsequent updates are driven by the Refresh button and real-time events
      fetchRouters()
    })

    onUnmounted(() => {
      document.removeEventListener('click', handleClickOutside)
      clearInterval(updateInterval)
      
      // Leave all tenant-specific channels
      const user = JSON.parse(localStorage.getItem('user') || '{}')
      if (window.Echo && user.tenant_id) {
        window.Echo.leave(`private-tenant.${user.tenant_id}.router-updates`)
        window.Echo.leave(`private-tenant.${user.tenant_id}.routers`)
      }
    })

    return {
      handleEdit,
      handleReProv,
      handleDelete,
      loginToRouter,
      currentYear,
      routers,
      filteredRouters,
      paginatedRouters,
      searchQuery,
      onlineCount,
      offlineCount,
      lastUpdateTime,
      currentPage,
      itemsPerPage,
      itemsPerPageOptions,
      totalPages,
      paginationInfo,
      goToFirstPage,
      goToPreviousPage,
      goToNextPage,
      goToLastPage,
      getStatusDotClass,
      getCpuColorClass,
      getMemoryColorClass,
      getDiskColorClass,
      getMemoryUsage,
      getDiskUsage,
      getConnectedUsers,
      getRouterModel,
      formatModel,
      formatTimeAgo,
      loading,
      refreshing,
      listError,
      formError,
      detailsError,
      detailsLoading,
      showFormOverlay,
      showDetailsOverlay,
      showUpdateOverlay,
      currentRouter,
      isEditing,
      selectedRouter,
      formData,
      formSubmitting,
      currentStep,
      steps,
      configLoading,
      connectivityVerified,
      availableInterfaces,
      configurationProgress,
      formMessage,
      formSubmitted,
      activeMenu,
      toggleMenu,
      menuPosition,
      fetchRouters,
      verifyConnectivity,
      handleFormSubmit,
      handleDeleteRouter,
      handleReprovision,
      generateConfigs,
      applyConfigurations,
      formatTimestamp,
      statusBadgeClass,
      openCreateOverlay,
      openEditOverlay,
      openDetails,
      closeDetails,
      refreshDetails,
      closeFormOverlay,
      closeUpdateOverlay,
      nextStep,
      previousStep,
      copyToClipboard,
      updateInterfaceAssignments,
      updateFormData,
    }
  },
}
</script>

<style scoped>
/* Custom scrollbar */
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 4px;
}

::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

/* Ensure the dropdown menu is clickable and visible without affecting the header/sidebar layout */
[data-dropdown-menu] {
  transition: opacity 0.2s ease-in-out, transform 0.2s ease-in-out;
  transform-origin: top right;
}

/* Add subtle animation for dropdown appearance */
@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

[data-dropdown-menu] {
  animation: slideDown 0.2s ease-in-out;
}

/* Card hover effect */
.group:hover {
  transform: translateY(-2px);
}

/* Pulse animation for status dots */
@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

.animate-pulse {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Ensure proper stacking context for sticky elements */
.sticky {
  position: sticky;
}
</style>