<template>
  <div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg">
    <!-- Header -->
    <div class="flex-shrink-0 bg-white border-b border-slate-200 shadow-sm relative z-10">
      <!-- Top Bar -->
      <div class="px-6 py-5">
        <div class="flex items-center justify-between gap-6">
          <!-- Left: Title & Icon -->
          <div class="flex items-center gap-3">
            <div class="w-11 h-11 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </div>
            <div>
              <h2 class="text-xl font-bold text-slate-900">Package Management</h2>
              <p class="text-xs text-slate-500 mt-0.5">Manage your internet service packages</p>
            </div>
          </div>
          
          <!-- Center: Search Bar -->
          <div class="flex-1 max-w-xl">
            <div class="relative">
              <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
              </div>
              <input 
                v-model="searchQuery"
                type="text" 
                placeholder="Search packages by name or description..." 
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
          
          <!-- Right: Filters, View Toggle, Stats & Actions -->
          <div class="flex items-center gap-3">
            <!-- Type Filter -->
            <select 
              v-model="typeFilter"
              class="px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
            >
              <option value="all">All Types</option>
              <option value="hotspot">Hotspot</option>
              <option value="pppoe">PPPoE</option>
            </select>
            
            <!-- Status Filter -->
            <select 
              v-model="statusFilter"
              class="px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
            >
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
            
            <!-- View Toggle -->
            <div class="flex items-center gap-1 p-1 bg-slate-100 rounded-lg">
              <button
                @click="viewMode = 'list'"
                :class="[
                  'px-3 py-1.5 text-xs font-medium rounded transition-all',
                  viewMode === 'list' 
                    ? 'bg-white text-blue-600 shadow-sm' 
                    : 'text-slate-600 hover:text-slate-900'
                ]"
                title="List View"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
              </button>
              <button
                @click="viewMode = 'grid'"
                :class="[
                  'px-3 py-1.5 text-xs font-medium rounded transition-all',
                  viewMode === 'grid' 
                    ? 'bg-white text-blue-600 shadow-sm' 
                    : 'text-slate-600 hover:text-slate-900'
                ]"
                title="Grid View"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                </svg>
              </button>
            </div>

            <!-- Quick Stats -->
            <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                <span class="text-xs font-semibold text-slate-700">{{ activeCount }}</span>
              </div>
              <span class="text-slate-300">|</span>
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 bg-slate-400 rounded-full"></span>
                <span class="text-xs font-semibold text-slate-700">{{ inactiveCount }}</span>
              </div>
              <span class="text-slate-300">|</span>
              <span class="text-xs font-semibold text-blue-600">{{ packages.length }}</span>
            </div>
            
            <!-- Action Buttons -->
            <button @click="fetchPackages" :disabled="loading"
              class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
              <svg xmlns="http://www.w3.org/2000/svg" :class="loading ? 'animate-spin' : ''" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                  d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"
                  clip-rule="evenodd" />
              </svg>
              <span>Refresh</span>
            </button>
            <button @click="openCreateOverlay"
              class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                  d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"
                  clip-rule="evenodd" />
              </svg>
              <span>Add Package</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Scrollable Content Area -->
    <div class="flex-1 min-h-0 overflow-y-auto">
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
        <button @click="fetchPackages"
          class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
          Retry
        </button>
      </div>
    
      <div v-else-if="!loading" class="flex flex-col">
        <!-- Overlays -->
        <ViewPackageOverlay 
          :show-details-overlay="showDetailsOverlay" 
          :current-package="currentPackage"
          @close-details="closeDetails" 
        />
        <CreatePackageOverlay 
          :show-form-overlay="showFormOverlay" 
          :form-data="formData"
          :form-submitting="formSubmitting"
          :form-message="formMessage"
          :is-editing="false"
          @close-form="closeFormOverlay"
          @submit="addPackage"
        />
        <CreatePackageOverlay 
          :show-form-overlay="showUpdateOverlay" 
          :form-data="formData"
          :form-submitting="formSubmitting"
          :form-message="formMessage"
          :is-editing="true"
          @close-form="closeUpdateOverlay"
          @submit="updatePackage"
        />
        
        <!-- List View -->
        <div v-if="viewMode === 'list' && filteredPackages.length" class="px-6 pt-6 pb-2">
          <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
            <!-- Table Header -->
            <div class="bg-slate-50 border-b border-slate-200">
              <div class="flex items-center justify-between px-6 py-3 gap-4">
                <div class="flex-1 grid grid-cols-8 gap-4">
                  <div class="col-span-2">
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</span>
                  </div>
                  <div>
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Type</span>
                  </div>
                  <div>
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Price</span>
                  </div>
                  <div>
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Speed</span>
                  </div>
                  <div>
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Validity</span>
                  </div>
                  <div>
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</span>
                  </div>
                  <div class="text-right">
                    <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Table Body -->
            <div class="divide-y divide-slate-100">
              <div
                v-for="pkg in filteredPackages"
                :key="pkg.id"
                class="flex items-center justify-between px-6 py-4 hover:bg-blue-50/50 transition-colors cursor-pointer group"
                @click="openDetails(pkg)"
              >
                <div class="flex-1 grid grid-cols-8 gap-4 items-center">
                  <!-- Package Name -->
                  <div class="col-span-2 flex items-center gap-3">
                    <div class="p-2 rounded-lg" :class="pkg.type === 'hotspot' ? 'bg-purple-100' : 'bg-cyan-100'">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" :class="pkg.type === 'hotspot' ? 'text-purple-600' : 'text-cyan-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path v-if="pkg.type === 'hotspot'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                      </svg>
                    </div>
                    <div>
                      <div class="text-sm font-semibold text-slate-900">{{ pkg.name }}</div>
                      <div class="text-xs text-slate-500">{{ pkg.description }}</div>
                    </div>
                  </div>

                  <!-- Type -->
                  <div>
                    <span :class="[
                      'px-2 py-1 text-xs font-medium rounded-full',
                      pkg.type === 'hotspot' ? 'bg-purple-100 text-purple-800' : 'bg-cyan-100 text-cyan-800'
                    ]">
                      {{ pkg.type }}
                    </span>
                  </div>

                  <!-- Price -->
                  <div>
                    <div class="text-sm font-bold text-slate-900">KES {{ formatMoney(pkg.price) }}</div>
                  </div>

                  <!-- Speed -->
                  <div class="text-sm text-slate-900">{{ pkg.speed }}</div>

                  <!-- Validity -->
                  <div class="text-sm text-slate-900">{{ pkg.validity }}</div>

                  <!-- Status -->
                  <div>
                    <span :class="statusBadgeClass(pkg.status)">
                      {{ pkg.status }}
                    </span>
                  </div>

                  <!-- Actions -->
                  <div class="text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <button
                        @click="openDetails(pkg)"
                        class="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors"
                        title="View Details"
                      >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                      </button>
                      <button
                        @click="handleToggleStatus(pkg)"
                        :class="[
                          'p-1.5 rounded transition-colors',
                          pkg.status === 'active' 
                            ? 'text-amber-600 hover:text-amber-700 hover:bg-amber-50' 
                            : 'text-green-600 hover:text-green-700 hover:bg-green-50'
                        ]"
                        :title="pkg.status === 'active' ? 'Deactivate' : 'Activate'"
                      >
                        <svg v-if="pkg.status === 'active'" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                      </button>
                      <!-- 3-dot menu -->
                      <div class="relative" ref="menuRef">
                        <button
                          @click="toggleMenu(pkg.id)"
                          class="p-1.5 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded transition-colors"
                          title="More Actions"
                        >
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                          </svg>
                        </button>
                        <!-- Dropdown Menu -->
                        <div
                          v-if="showMenu === pkg.id"
                          class="absolute right-0 mt-1 w-40 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50"
                        >
                          <button
                            @click="openEditOverlay(pkg); showMenu = null"
                            class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                          >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Edit
                          </button>
                          <button
                            @click="handleDuplicate(pkg); showMenu = null"
                            class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                          >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            Duplicate
                          </button>
                          <button
                            @click="handleDelete(pkg); showMenu = null"
                            class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50 flex items-center gap-2"
                          >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            Delete
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Grid View -->
        <div v-if="viewMode === 'grid' && filteredPackages.length" class="px-6 pt-6 pb-2">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div
              v-for="pkg in filteredPackages"
              :key="pkg.id"
              class="bg-white rounded-lg border border-slate-200 shadow-sm hover:shadow-md transition-all cursor-pointer group"
              @click="openDetails(pkg)"
            >
              <!-- Card Header -->
              <div class="p-6 border-b border-slate-100">
                <div class="flex items-start justify-between mb-3">
                  <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg" :class="pkg.type === 'hotspot' ? 'bg-purple-100' : 'bg-cyan-100'">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" :class="pkg.type === 'hotspot' ? 'text-purple-600' : 'text-cyan-600'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path v-if="pkg.type === 'hotspot'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                      </svg>
                    </div>
                    <div>
                      <span :class="[
                        'px-2 py-1 text-xs font-medium rounded-full',
                        pkg.type === 'hotspot' ? 'bg-purple-100 text-purple-800' : 'bg-cyan-100 text-cyan-800'
                      ]">
                        {{ pkg.type }}
                      </span>
                    </div>
                  </div>
                  <span :class="statusBadgeClass(pkg.status)">
                    {{ pkg.status }}
                  </span>
                </div>
                <h3 class="text-lg font-bold text-slate-900 mb-1">{{ pkg.name }}</h3>
                <p class="text-sm text-slate-500 line-clamp-2">{{ pkg.description }}</p>
              </div>

              <!-- Card Body -->
              <div class="p-6 space-y-4">
                <!-- Price -->
                <div class="flex items-center justify-between">
                  <span class="text-sm text-slate-600">Price</span>
                  <span class="text-xl font-bold text-blue-600">KES {{ formatMoney(pkg.price) }}</span>
                </div>

                <!-- Details Grid -->
                <div class="grid grid-cols-2 gap-3 pt-3 border-t border-slate-100">
                  <div>
                    <div class="text-xs text-slate-500 mb-1">Speed</div>
                    <div class="text-sm font-semibold text-slate-900">{{ pkg.speed }}</div>
                  </div>
                  <div>
                    <div class="text-xs text-slate-500 mb-1">Validity</div>
                    <div class="text-sm font-semibold text-slate-900">{{ pkg.validity }}</div>
                  </div>
                  <div>
                    <div class="text-xs text-slate-500 mb-1">Data Limit</div>
                    <div class="text-sm font-semibold text-slate-900">{{ pkg.data_limit || 'Unlimited' }}</div>
                  </div>
                  <div>
                    <div class="text-xs text-slate-500 mb-1">Devices</div>
                    <div class="text-sm font-semibold text-slate-900">{{ pkg.devices }}</div>
                  </div>
                </div>
              </div>

              <!-- Card Footer -->
              <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between" @click.stop>
                <div class="flex items-center gap-2">
                  <button
                    @click="openDetails(pkg)"
                    class="p-2 text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors"
                    title="View Details"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                  </button>
                  <button
                    @click="handleToggleStatus(pkg)"
                    :class="[
                      'p-2 rounded transition-colors',
                      pkg.status === 'active' 
                        ? 'text-amber-600 hover:text-amber-700 hover:bg-amber-50' 
                        : 'text-green-600 hover:text-green-700 hover:bg-green-50'
                    ]"
                    :title="pkg.status === 'active' ? 'Deactivate' : 'Activate'"
                  >
                    <svg v-if="pkg.status === 'active'" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </button>
                </div>
                <!-- 3-dot menu -->
                <div class="relative">
                  <button
                    @click="toggleMenu(pkg.id)"
                    class="p-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded transition-colors"
                    title="More Actions"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                    </svg>
                  </button>
                  <!-- Dropdown Menu -->
                  <div
                    v-if="showMenu === pkg.id"
                    class="absolute right-0 bottom-full mb-1 w-40 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50"
                  >
                    <button
                      @click="openEditOverlay(pkg); showMenu = null"
                      class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                      Edit
                    </button>
                    <button
                      @click="handleDuplicate(pkg); showMenu = null"
                      class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                      </svg>
                      Duplicate
                    </button>
                    <button
                      @click="handleDelete(pkg); showMenu = null"
                      class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50 flex items-center gap-2"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State (only show when not loading and no packages) -->
        <div v-if="!loading && filteredPackages.length === 0" class="flex flex-col items-center justify-center gap-4 p-12 text-slate-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <p class="text-center text-lg font-medium">No packages found</p>
          <p class="text-center text-sm">Get started by creating your first package</p>
          <button @click="openCreateOverlay"
            class="mt-4 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors">
            Add Package
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import CreatePackageOverlay from '@/modules/tenant/components/packages/overlays/CreatePackageOverlay.vue'
import ViewPackageOverlay from '@/modules/tenant/components/packages/overlays/ViewPackageOverlay.vue'

const {
  packages,
  loading,
  listError,
  showFormOverlay,
  showDetailsOverlay,
  showUpdateOverlay,
  currentPackage,
  formData,
  formSubmitting,
  formMessage,
  showMenu,
  toggleMenu,
  closeMenuOnOutsideClick,
  fetchPackages,
  addPackage,
  updatePackage,
  deletePackage,
  duplicatePackage,
  toggleStatus,
  openCreateOverlay,
  openEditOverlay,
  openDetails,
  closeDetails,
  closeFormOverlay,
  closeUpdateOverlay,
  statusBadgeClass
} = usePackages()

const searchQuery = ref('')
const menuRef = ref(null)
const viewMode = ref('list') // 'list' or 'grid'
const typeFilter = ref('all') // 'all', 'hotspot', 'pppoe'
const statusFilter = ref('all') // 'all', 'active', 'inactive'

const filteredPackages = computed(() => {
  let filtered = packages.value
  
  // Filter by search query
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(pkg =>
      pkg.name.toLowerCase().includes(query) ||
      (pkg.description && pkg.description.toLowerCase().includes(query))
    )
  }
  
  // Filter by type
  if (typeFilter.value !== 'all') {
    filtered = filtered.filter(pkg => pkg.type === typeFilter.value)
  }
  
  // Filter by status
  if (statusFilter.value !== 'all') {
    filtered = filtered.filter(pkg => pkg.status === statusFilter.value)
  }
  
  return filtered
})

const activeCount = computed(() => {
  return packages.value.filter(p => p.status === 'active').length
})

const inactiveCount = computed(() => {
  return packages.value.filter(p => p.status === 'inactive').length
})

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const handleToggleStatus = async (pkg) => {
  const action = pkg.status === 'active' ? 'deactivate' : 'activate'
  if (!confirm(`Are you sure you want to ${action} ${pkg.name}?`)) return
  
  try {
    await toggleStatus(pkg)
  } catch (err) {
    alert(`Failed to ${action} package`)
  }
}

const handleDuplicate = async (pkg) => {
  await duplicatePackage(pkg)
}

const handleDelete = async (pkg) => {
  if (!confirm(`Are you sure you want to delete ${pkg.name}? This action cannot be undone.`)) return
  
  try {
    await deletePackage(pkg.id)
  } catch (err) {
    alert('Failed to delete package')
  }
}

const handleClickOutside = (event) => {
  closeMenuOnOutsideClick(event, menuRef.value)
}

onMounted(() => {
  fetchPackages()
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
