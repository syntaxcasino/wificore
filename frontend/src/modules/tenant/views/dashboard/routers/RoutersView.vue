<template>
  <DataViewContainer
    title="Router Management"
    subtitle="Manage your network infrastructure"
    color-theme="indigo"
    v-model:search-model="searchQuery"
    search-placeholder="Search routers..."
    :stats="[
      { color: 'bg-emerald-500', value: onlineCount },
      { color: 'bg-slate-500', value: offlineCount },
      { color: 'bg-blue-500', value: routers.length },
      { color: 'bg-amber-500', value: filteredRouters.length }
    ]"
    :total="filteredRouters.length"
    :loading="loading"
    @refresh="fetchRouters"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
      </svg>
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary" size="sm" class="shrink-0 h-8 px-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1.5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
        </svg>
        Add Router
      </BaseButton>
    </template>

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

    <!-- Error State -->
    <div v-if="listError" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ listError }}</p>
      <button @click="fetchRouters" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredRouters.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
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
            <EntityStatusBadge :status="router.status" size="sm" />
          </div>

          <div class="mt-3 grid grid-cols-2 gap-3">
            <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
              <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">CPU</div>
              <div v-if="router.live_data?.cpu_load !== undefined && router.live_data?.cpu_load !== null" class="mt-1 flex items-center gap-2">
                <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                  <div :class="getCpuColorClass(Number(router.live_data.cpu_load))" class="h-full rounded-full transition-all" :style="{ width: String(router.live_data.cpu_load) + '%' }"></div>
                </div>
                <div class="text-xs font-medium text-slate-700 w-10 text-right">{{ router.live_data.cpu_load }}%</div>
              </div>
              <div v-else class="mt-1 text-xs text-slate-400">—</div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
              <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Memory</div>
              <div v-if="getMemoryUsage(router) !== null" class="mt-1 flex items-center gap-2">
                <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                  <div :class="getMemoryColorClass(getMemoryUsage(router))" class="h-full rounded-full transition-all" :style="{ width: getMemoryUsage(router) + '%' }"></div>
                </div>
                <div class="text-xs font-medium text-slate-700 w-10 text-right">{{ getMemoryUsage(router) }}%</div>
              </div>
              <div v-else class="mt-1 text-xs text-slate-400">—</div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-md p-2">
              <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Disk</div>
              <div v-if="getDiskUsage(router) !== null" class="mt-1 flex items-center gap-2">
                <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                  <div :class="getDiskColorClass(getDiskUsage(router))" class="h-full rounded-full transition-all" :style="{ width: getDiskUsage(router) + '%' }"></div>
                </div>
                <div class="text-xs font-medium text-slate-700 w-10 text-right">{{ getDiskUsage(router) }}%</div>
              </div>
              <div v-else class="mt-1 text-xs text-slate-400">—</div>
            </div>
          </div>

          <div class="mt-3 flex items-center justify-end gap-2" @click.stop>
            <button @click="loginToRouter(router)" :disabled="router.status !== 'online'" class="px-3 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 rounded-md hover:bg-emerald-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">Login</button>
            <button @click="openDetails(router)" class="px-3 py-2 text-xs font-medium text-blue-700 bg-blue-50 rounded-md hover:bg-blue-100 transition-colors">View</button>
          </div>
        </div>
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">
                  <div class="flex items-center gap-2"><div class="w-7 h-7"></div><span>Router</span></div>
                </th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">IP Address</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">CPU</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Memory</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Disk</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Model</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Last Seen</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="router in paginatedRouters" :key="router.id" class="hover:bg-blue-50/50 transition-colors cursor-pointer group" @click="openDetails(router)">
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
                <td class="px-6 py-4 hidden lg:table-cell">
                  <div class="flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                    </svg>
                    <span class="text-xs text-slate-600 truncate">{{ router.ip_address || 'No IP' }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="router.status" size="sm" />
                </td>
                <td class="px-6 py-4 hidden xl:table-cell">
                  <div v-if="router.live_data?.cpu_load !== undefined && router.live_data?.cpu_load !== null" class="flex items-center gap-1.5">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                      <div :class="getCpuColorClass(router.live_data.cpu_load)" class="h-full rounded-full transition-all" :style="{ width: router.live_data.cpu_load + '%' }"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ router.live_data.cpu_load }}%</span>
                  </div>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
                <td class="px-6 py-4 hidden xl:table-cell">
                  <div v-if="getMemoryUsage(router) !== null" class="flex items-center gap-1.5">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                      <div :class="getMemoryColorClass(getMemoryUsage(router))" class="h-full rounded-full transition-all" :style="{ width: getMemoryUsage(router) + '%' }"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ getMemoryUsage(router) }}%</span>
                  </div>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
                <td class="px-6 py-4 hidden xl:table-cell">
                  <div v-if="getDiskUsage(router) !== null" class="flex items-center gap-1.5">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                      <div :class="getDiskColorClass(getDiskUsage(router))" class="h-full rounded-full transition-all" :style="{ width: getDiskUsage(router) + '%' }"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-700 w-8 text-right">{{ getDiskUsage(router) }}%</span>
                  </div>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span v-if="getRouterModel(router)" class="text-xs text-slate-500 truncate" :title="getRouterModel(router)">{{ formatModel(getRouterModel(router)) }}</span>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span v-if="router.last_updated || router.last_seen" class="text-xs text-slate-500 truncate">{{ formatTimeAgo(router.last_updated || router.last_seen) }}</span>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button @click="loginToRouter(router)" :disabled="router.status !== 'online'" class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors inline-flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                      </svg>
                      Login
                    </button>
                    <button @click="openDetails(router)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors inline-flex items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                      View
                    </button>
                    <div class="relative">
                      <button data-menu-button @click="toggleMenu(router.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
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

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredRouters.length" item-name="routers" class="mt-auto" />

      <!-- Global Dropdown Menu Portal -->
      <Teleport to="body">
        <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white rounded-lg shadow-2xl border border-slate-200 py-1 z-[9999] overflow-hidden">
          <button @click="handleEdit(routers.find(r => r.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Edit Router
          </button>
          <button @click="handleReProv(routers.find(r => r.id === activeMenu))" :disabled="formSubmitting" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Reprovision
          </button>
          <div class="border-t border-slate-200 my-1"></div>
          <button @click="handleDelete(routers.find(r => r.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Delete Router
          </button>
        </div>
      </Teleport>
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Routers Found' : 'No Routers'"
      :description="searchQuery ? 'No routers match your search criteria.' : 'Get started by adding your first router to begin managing your network infrastructure.'"
      icon="router"
      color-theme="indigo"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      @clear="searchQuery = ''"
    >
      <template #action>
        <BaseButton @click="openCreateOverlay" variant="primary" size="sm">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
          </svg>
          Add Your First Router
        </BaseButton>
      </template>
    </DataEmptyState>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useConfirmStore } from '@/stores/confirm'
import { useRouterUtils } from '@/modules/common/composables/utils/useRouterUtils'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import Overlay from '@/modules/tenant/components/routers/modals/CreateRouterModal.vue'
import UpdateOverlay from '@/modules/tenant/components/routers/modals/UpdateRouterModal.vue'
import DetailsOverlay from '@/modules/tenant/components/routers/modals/RouterDetailsModal.vue'

const confirmStore = useConfirmStore()

const {
  routers, loading, refreshing, listError, formError, detailsError, detailsLoading,
  showFormOverlay, showDetailsOverlay, showUpdateOverlay, currentRouter, isEditing,
  selectedRouter, formData, formSubmitting, currentStep, steps, configLoading,
  connectivityVerified, availableInterfaces, configurationProgress, formMessage, formSubmitted,
  fetchRouters, verifyConnectivity, addRouter, editRouter, updateRouter, deleteRouter,
  generateConfigs, applyConfigurations, formatTimestamp, statusBadgeClass,
  openCreateOverlay, openEditOverlay, openDetails, closeDetails, refreshDetails,
  closeFormOverlay, closeUpdateOverlay, nextStep, previousStep, copyToClipboard,
  updateInterfaceAssignments, updateFormData,
} = useRouters()

const {
  getStatusDotClass, getCpuColorClass, getMemoryColorClass, getDiskColorClass,
  getMemoryUsage, getDiskUsage, getRouterModel, formatModel, formatTimeAgo,
} = useRouterUtils()

const activeMenu = ref(null)
const menuPosition = ref({})
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

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

const filteredRouters = computed(() => {
  let filtered = routers.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = routers.value.filter(router => 
      (router.name && router.name.toLowerCase().includes(query)) ||
      (router.ip_address && router.ip_address.includes(query)) ||
      (router.model && router.model.toLowerCase().includes(query))
    )
  }
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

const totalPages = computed(() => Math.ceil(filteredRouters.value.length / itemsPerPage.value))

watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

watch(totalPages, (pages) => {
  const safePages = pages || 1
  if (currentPage.value > safePages) currentPage.value = safePages
  if (currentPage.value < 1) currentPage.value = 1
})

const onlineCount = computed(() => routers.value.filter(r => r.status === 'online').length)
const offlineCount = computed(() => routers.value.filter(r => !r.status || r.status === 'offline').length)

const toggleMenu = (routerId, event) => {
  event.stopPropagation()
  if (activeMenu.value === routerId) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = routerId
    const button = event.currentTarget
    const rect = button.getBoundingClientRect()
    const menuWidth = 192
    const menuHeight = 140
    const viewportHeight = window.innerHeight
    const viewportWidth = window.innerWidth
    let top = rect.bottom + 4
    let left = rect.right - menuWidth
    if (rect.bottom + menuHeight > viewportHeight) top = rect.top - menuHeight - 4
    if (left < 0) left = rect.left
    if (left + menuWidth > viewportWidth) left = viewportWidth - menuWidth - 10
    menuPosition.value = { top: `${top}px`, left: `${left}px` }
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

const handleReprovision = async (router) => {
  if (!router) return
  const confirmed = await confirmStore.confirm(`Reprovision router ${router.name}? This will regenerate all configurations.`)
  if (!confirmed) return
  try {
    await generateConfigs(router.id)
    await fetchRouters()
  } catch (err) {
    console.error('Reprovision error:', err)
  }
}

const handleDelete = async (router) => {
  if (!router) return
  activeMenu.value = null
  menuPosition.value = {}
  const confirmed = await confirmStore.confirm(`Delete router ${router.name}? This action cannot be undone.`)
  if (!confirmed) return
  try {
    await deleteRouter(router.id)
    await fetchRouters()
  } catch (err) {
    console.error('Delete error:', err)
  }
}

const handleFormSubmit = async () => {
  try {
    if (isEditing.value) {
      await updateRouter(formData.value.id, formData.value)
    } else {
      await addRouter(formData.value)
    }
    closeFormOverlay()
    await fetchRouters()
  } catch (err) {
    console.error('Form submit error:', err)
  }
}

const loginToRouter = (router) => {
  if (router.status !== 'online') return
  const winboxUrl = `winbox://${router.ip_address}`
  window.open(winboxUrl, '_blank')
}

onMounted(() => { fetchRouters() })
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
