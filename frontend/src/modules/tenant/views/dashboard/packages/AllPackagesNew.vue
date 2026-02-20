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
                  <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </div>
              <div>
                <h2 class="text-lg md:text-xl font-bold text-slate-900">Package Management</h2>
                <p class="text-xs text-slate-500 mt-0.5 hidden md:block">Manage your internet service packages</p>
              </div>
            </div>

            <!-- Mobile: Quick Stats -->
            <div class="flex md:hidden items-center gap-2 px-2 py-1.5 bg-slate-50 rounded-lg border border-slate-200">
              <div class="flex items-center gap-1">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span class="text-xs font-semibold text-slate-700">{{ activeCount }}</span>
              </div>
              <span class="text-slate-300 text-xs">|</span>
              <div class="flex items-center gap-1">
                <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                <span class="text-xs font-semibold text-slate-700">{{ inactiveCount }}</span>
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

          <!-- Right: Stats & Actions -->
          <div class="flex items-center justify-between md:justify-end gap-2 md:gap-3">
            <!-- Desktop: Quick Stats -->
            <div class="hidden md:flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
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
              class="inline-flex items-center gap-1.5 px-2 md:px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
              <svg xmlns="http://www.w3.org/2000/svg" :class="loading ? 'animate-spin' : ''" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
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
              <span class="hidden sm:inline">Add Package</span>
              <span class="sm:hidden">Add</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Area -->
    <div class="flex-1 min-h-0 overflow-hidden">
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

      <div v-else class="flex flex-col min-h-0 flex-1">
        <div v-if="paginatedPackages.length" class="px-4 md:px-6 pt-4 md:pt-6 pb-2 flex flex-col min-h-0 flex-1">
          <div class="flex-1 min-h-0 overflow-y-auto">
            <!-- Mobile Cards -->
            <div class="md:hidden space-y-3">
              <div
                v-for="pkg in paginatedPackages"
                :key="pkg.id"
                class="bg-white rounded-lg border border-slate-200 shadow-sm p-4 cursor-pointer active:scale-[0.99] transition-transform"
                @click="openDetails(pkg)"
              >
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 min-w-0">
                      <div class="w-2 h-2 rounded-full flex-shrink-0" :class="pkg.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'"></div>
                      <div class="text-sm font-semibold text-slate-900 truncate">{{ pkg.name }}</div>
                    </div>
                    <div class="mt-1 text-xs text-slate-600 truncate">{{ pkg.description || '—' }}</div>
                    <div class="mt-1 text-xs text-slate-500 truncate">{{ pkg.type || '—' }}</div>
                  </div>
                  <span :class="statusBadgeClass(pkg.status)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize flex-shrink-0">
                    {{ pkg.status || 'Unknown' }}
                  </span>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3">
                  <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
                    <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Price</div>
                    <div class="mt-1 text-xs font-medium text-slate-700">KES {{ formatMoney(pkg.price) }}</div>
                  </div>
                  <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
                    <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Speed</div>
                    <div class="mt-1 text-xs font-medium text-slate-700">{{ pkg.speed || '—' }}</div>
                  </div>
                </div>

                <div class="mt-3 flex items-center justify-end gap-2" @click.stop>
                  <button
                    @click="handleToggleStatus(pkg)"
                    :class="[
                      'px-3 py-2 text-xs font-medium rounded-md transition-colors',
                      pkg.status === 'active'
                        ? 'text-amber-700 bg-amber-50 hover:bg-amber-100'
                        : 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100'
                    ]"
                  >
                    {{ pkg.status === 'active' ? 'Deactivate' : 'Activate' }}
                  </button>
                  <button
                    @click="openDetails(pkg)"
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
                  <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
                    <tr>
                      <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">
                        <div class="flex items-center gap-2">
                          <div class="w-7 h-7"></div>
                          <span>Package</span>
                        </div>
                      </th>
                      <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Type</th>
                      <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Price</th>
                      <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Speed</th>
                      <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Validity</th>
                      <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                      <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>

                  <tbody class="divide-y divide-slate-100">
                    <tr
                      v-for="pkg in paginatedPackages"
                      :key="pkg.id"
                      class="hover:bg-blue-50/50 transition-colors cursor-pointer group"
                      @click="openDetails(pkg)"
                    >
                      <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                          <div class="w-7 h-7 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-md flex items-center justify-center text-white flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                          </div>
                          <div class="flex items-center gap-1.5 min-w-0">
                            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="pkg.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                            <div class="min-w-0">
                              <div class="text-sm font-semibold text-slate-900 truncate">{{ pkg.name }}</div>
                              <div class="text-xs text-slate-500 truncate">{{ pkg.description || '—' }}</div>
                            </div>
                          </div>
                        </div>
                      </td>

                      <td class="px-6 py-4 hidden lg:table-cell">
                        <span v-if="pkg.type" class="text-xs text-slate-500 truncate">{{ pkg.type }}</span>
                        <span v-else class="text-xs text-slate-400">—</span>
                      </td>

                      <td class="px-6 py-4">
                        <span class="text-sm font-semibold text-slate-900">KES {{ formatMoney(pkg.price) }}</span>
                      </td>

                      <td class="px-6 py-4 hidden xl:table-cell">
                        <span v-if="pkg.speed" class="text-xs text-slate-500 truncate">{{ pkg.speed }}</span>
                        <span v-else class="text-xs text-slate-400">—</span>
                      </td>

                      <td class="px-6 py-4 hidden xl:table-cell">
                        <span v-if="pkg.validity" class="text-xs text-slate-500 truncate">{{ pkg.validity }}</span>
                        <span v-else class="text-xs text-slate-400">—</span>
                      </td>

                      <td class="px-6 py-4">
                        <span :class="statusBadgeClass(pkg.status)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">
                          {{ pkg.status || 'Unknown' }}
                        </span>
                      </td>

                      <td class="px-6 py-4 text-right" @click.stop>
                        <div class="flex items-center justify-end gap-1">
                          <button
                            @click="handleToggleStatus(pkg)"
                            :class="[
                              'px-2 py-1 text-xs font-medium rounded transition-colors inline-flex items-center gap-1',
                              pkg.status === 'active'
                                ? 'text-amber-700 bg-amber-50 hover:bg-amber-100'
                                : 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100'
                            ]"
                          >
                            {{ pkg.status === 'active' ? 'Deactivate' : 'Activate' }}
                          </button>

                          <button @click="openDetails(pkg)"
                            class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors inline-flex items-center gap-1">
                            View
                          </button>

                          <div class="relative">
                            <button
                              data-menu-button
                              @click="toggleMenu(pkg.id, $event)"
                              class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors"
                            >
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
          </div>

          <div class="mt-3 bg-white rounded-lg border border-slate-200 shadow-sm px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-sm text-slate-600">
              Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} packages
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
                  &​lt;
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
                  &​gt;
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

          <Teleport to="body">
            <div
              v-if="activeMenu !== null"
              data-dropdown-menu
              :style="menuPosition"
              class="fixed w-48 bg-white rounded-lg shadow-2xl border border-slate-200 py-1 z-[9999] overflow-hidden"
            >
              <button @click="openEditOverlay(packages.find(p => p.id === activeMenu)); closeMenu()"
                class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Edit Package
              </button>
              <button @click="handleDuplicate(packages.find(p => p.id === activeMenu)); closeMenu()"
                class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                Duplicate
              </button>
              <div class="border-t border-slate-200 my-1"></div>
              <button @click="handleDelete(packages.find(p => p.id === activeMenu)); closeMenu()"
                class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Delete Package
              </button>
            </div>
          </Teleport>
        </div>

        <div v-else class="flex flex-col items-center justify-center gap-4 p-12 text-slate-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <p class="text-center text-lg font-medium">No packages found</p>
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
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useConfirmStore } from '@/stores/confirm'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
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

const confirmStore = useConfirmStore()
const authStore = useAuthStore()
const { subscribeToPrivateChannel } = useBroadcasting()

const searchQuery = ref('')
const typeFilter = ref('all')
const statusFilter = ref('all')

const currentPage = ref(1)
const itemsPerPage = ref(10)
const itemsPerPageOptions = [10, 20, 50]

const activeMenu = ref(null)
const menuPosition = ref({ top: '0px', left: '0px' })

const filteredPackages = computed(() => {
  let filtered = packages.value
  
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(pkg =>
      pkg.name.toLowerCase().includes(query) ||
      (pkg.description && pkg.description.toLowerCase().includes(query))
    )
  }
  
  if (typeFilter.value !== 'all') {
    filtered = filtered.filter(pkg => pkg.type === typeFilter.value)
  }
  
  if (statusFilter.value !== 'all') {
    filtered = filtered.filter(pkg => pkg.status === statusFilter.value)
  }
  
  return filtered
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredPackages.value.length / itemsPerPage.value)))

const paginatedPackages = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredPackages.value.slice(start, end)
})

const paginationInfo = computed(() => {
  const total = filteredPackages.value.length
  if (total === 0) {
    return { start: 0, end: 0, total: 0 }
  }

  const start = (currentPage.value - 1) * itemsPerPage.value + 1
  const end = Math.min(currentPage.value * itemsPerPage.value, total)

  return { start, end, total }
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

  const confirmed = await confirmStore.open({
    title: `Confirm ${action}`,
    message: `Are you sure you want to ${action} ${pkg.name}?`,
    confirmText: action === 'deactivate' ? 'Deactivate' : 'Activate',
    cancelText: 'Cancel',
    variant: 'warning',
  })

  if (!confirmed) return
  
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
  const confirmed = await confirmStore.open({
    title: 'Confirm Delete',
    message: `Are you sure you want to delete ${pkg.name}? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    variant: 'danger',
  })

  if (!confirmed) return
  
  try {
    await deletePackage(pkg.id)
  } catch (err) {
    alert('Failed to delete package')
  }
}

const closeMenu = () => {
  activeMenu.value = null
}

const toggleMenu = (packageId, event) => {
  if (event) {
    event.stopPropagation()
    const button = event.currentTarget
    const rect = button.getBoundingClientRect()
    menuPosition.value = {
      top: `${rect.bottom + 8}px`,
      left: `${Math.min(rect.left, window.innerWidth - 200)}px`,
    }
  }

  activeMenu.value = activeMenu.value === packageId ? null : packageId
}

const handleClickOutside = (event) => {
  if (!activeMenu.value) return
  const target = event.target
  if (!target) return

  if (target.closest('[data-dropdown-menu]') || target.closest('[data-menu-button]')) {
    return
  }

  closeMenu()
}

const goToFirstPage = () => { currentPage.value = 1 }
const goToPreviousPage = () => { currentPage.value = Math.max(1, currentPage.value - 1) }
const goToNextPage = () => { currentPage.value = Math.min(totalPages.value, currentPage.value + 1) }
const goToLastPage = () => { currentPage.value = totalPages.value }

watch([filteredPackages, itemsPerPage], () => {
  if (currentPage.value > totalPages.value) {
    currentPage.value = totalPages.value
  }
})

onMounted(() => {
  fetchPackages()
  document.addEventListener('click', handleClickOutside)

  const tenantId = authStore.tenantId
  if (tenantId) {
    subscribeToPrivateChannel(`tenant.${tenantId}.packages`, {
      PackageCreated: () => fetchPackages(),
      PackageUpdated: () => fetchPackages(),
      PackageDeleted: () => fetchPackages(),
      PackageStatusChanged: () => fetchPackages(),
      '.PackageCreated': () => fetchPackages(),
      '.PackageUpdated': () => fetchPackages(),
      '.PackageDeleted': () => fetchPackages(),
      '.PackageStatusChanged': () => fetchPackages(),
    })
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
