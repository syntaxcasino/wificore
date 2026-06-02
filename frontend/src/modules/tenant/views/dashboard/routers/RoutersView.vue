<template>
  <DataViewContainer
    title="Router Management"
    subtitle="Manage your network infrastructure"
    color-theme="indigo"
    v-model:search-model="searchQuery"
    search-placeholder="Search routers..."
    :stats="[
      { color: 'bg-emerald-500', value: onlineCount, tooltip: 'Online routers' },
      { color: 'bg-slate-500', value: offlineCount, tooltip: 'Offline routers' },
      { color: 'bg-amber-500', value: issueCount, tooltip: 'Routers with issues' }
    ]"
    :total="filteredRouters.length"
    :loading="loading"
    add-button-text="Add Router"
    @refresh="fetchRouters"
    @add="openCreateOverlay"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
      </svg>
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
    <ReprovisionOverlay
      :visible="showReprovisionOverlay"
      :router="reprovisioningRouter"
      @close="closeReprovisionOverlay"
      @retry="handleReprovisionRetry"
    />
    <MassRouterOrchestrationOverlay
      :visible="showMassOrchestrationOverlay"
      :routers="filteredRouters"
      :templates="templateMarketplace"
      :preview="massOrchestrationPreview"
      :loading="massOrchestrationLoading"
      :deploying="massOrchestrationDeploying"
      :error="massOrchestrationError"
      :deploy-error="massOrchestrationDeployError"
      :deploy-result="massOrchestrationDeployResult"
      @close="closeMassOrchestrationOverlay"
      @preview="handleMassOrchestrationPreview"
      @deploy="handleMassOrchestrationDeploy"
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
      :vendor-options="vendorOptions"
      @close-update="closeUpdateOverlay" 
      @generate-configs="generateConfigs"
      @copy-token="copyToClipboard" 
      @update-router="handleFormSubmit" 
      @retry="fetchRouters" 
    />

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filterStatus" placeholder="All Statuses" class="w-40" @change="handleFilterChange">
        <option value="">All Statuses</option>
        <option value="online">Online</option>
        <option value="offline">Offline</option>
        <option value="error">Error</option>
        <option value="rebooting">Rebooting</option>
      </BaseSelect>
      <button v-if="filterStatus" @click="clearFilters" class="text-xs text-indigo-600 hover:text-indigo-700 font-medium">Clear filters</button>
      <button @click="openMassOrchestration" class="text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-full px-3 py-1.5 shadow-sm inline-flex items-center gap-1.5">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
        Plan Bulk Change
      </button>
    </template>

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
    <div v-else-if="filteredRouters.length" class="flex flex-col h-full px-2 md:px-4 pt-1 pb-1 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="router in paginatedRouters"
          :key="router.id"
          :title="router.name"
          :subtitle="router.ip_address || 'No IP'"
          :meta-lines="getRouterMetaLines(router)"
          :status="router.status"
          :actions="getRouterActions(router)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white dark:bg-slate-800 border-x border-t border-slate-200 dark:border-slate-700 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
          <table class="w-full table-fixed">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[22%]">
                  <div class="flex items-center gap-2"><div class="w-7 h-7"></div><span>Router</span></div>
                </th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[14%] hidden lg:table-cell">IP Address</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[10%]">Status</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[10%] hidden xl:table-cell">CPU</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[10%] hidden xl:table-cell">Memory</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[10%] hidden xl:table-cell">Disk</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[12%] hidden lg:table-cell">Model</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[12%] hidden lg:table-cell">Last Seen</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wider w-[15%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full table-fixed">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="router in paginatedRouters" :key="router.id" class="hover:bg-blue-50/50 transition-colors cursor-pointer group" @click="openDetails(router)">
                <td class="px-4 py-4 w-[22%]">
                  <div class="flex items-center gap-3">
                    <div class="w-7 h-7 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-md flex items-center justify-center text-white flex-shrink-0">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                      </svg>
                    </div>
                    <div class="flex items-center gap-1.5 min-w-0">
                      <span :class="getStatusDotClass(router.status)" class="w-1.5 h-1.5 rounded-full flex-shrink-0"></span>
                      <span class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{{ router.name }}</span>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4 w-[14%] hidden lg:table-cell">
                  <div class="flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-slate-400 dark:text-slate-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                    </svg>
                    <span class="text-xs text-slate-600 dark:text-slate-400 truncate">{{ router.ip_address || 'No IP' }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 w-[10%]"><EntityStatusBadge :status="router.status" size="sm" /></td>
                <td class="px-4 py-4 w-[10%] hidden xl:table-cell">
                  <div v-if="router.live_data?.cpu_load !== undefined && router.live_data?.cpu_load !== null" class="flex items-center gap-1.5">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden min-w-[30px]">
                      <div :class="getCpuColorClass(router.live_data.cpu_load)" class="h-full rounded-full transition-all" :style="{ width: router.live_data.cpu_load + '%' }"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-700 dark:text-slate-300 w-6 text-right">{{ router.live_data.cpu_load }}%</span>
                  </div>
                  <span v-else class="text-xs text-slate-400 dark:text-slate-500">—</span>
                </td>
                <td class="px-4 py-4 w-[10%] hidden xl:table-cell">
                  <div v-if="getMemoryUsage(router) !== null" class="flex items-center gap-1.5">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden min-w-[30px]">
                      <div :class="getMemoryColorClass(getMemoryUsage(router))" class="h-full rounded-full transition-all" :style="{ width: getMemoryUsage(router) + '%' }"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-700 dark:text-slate-300 w-6 text-right">{{ getMemoryUsage(router) }}%</span>
                  </div>
                  <span v-else class="text-xs text-slate-400 dark:text-slate-500">—</span>
                </td>
                <td class="px-4 py-4 w-[10%] hidden xl:table-cell">
                  <div v-if="getDiskUsage(router) !== null" class="flex items-center gap-1.5">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden min-w-[30px]">
                      <div :class="getDiskColorClass(getDiskUsage(router))" class="h-full rounded-full transition-all" :style="{ width: getDiskUsage(router) + '%' }"></div>
                    </div>
                    <span class="text-xs font-medium text-slate-700 dark:text-slate-300 w-6 text-right">{{ getDiskUsage(router) }}%</span>
                  </div>
                  <span v-else class="text-xs text-slate-400 dark:text-slate-500">—</span>
                </td>
                <td class="px-4 py-4 w-[12%] hidden lg:table-cell">
                  <span v-if="getRouterModel(router)" class="text-xs text-slate-500 dark:text-slate-400 truncate block" :title="getRouterModel(router)">{{ formatModel(getRouterModel(router)) }}</span>
                  <span v-else class="text-xs text-slate-400 dark:text-slate-500">—</span>
                </td>
                <td class="px-4 py-4 w-[12%] hidden lg:table-cell">
                  <span v-if="router.last_updated || router.last_seen" class="text-xs text-slate-500 dark:text-slate-400 truncate block">{{ formatTimeAgo(router.last_updated || router.last_seen) }}</span>
                  <span v-else class="text-xs text-slate-400 dark:text-slate-500">—</span>
                </td>
                <td class="px-4 py-4 text-right w-[15%]" @click.stop>
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
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredRouters.length" item-name="routers" />

      <!-- Global Dropdown Menu Portal -->
      <Teleport to="body">
        <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white dark:bg-slate-800 rounded-lg shadow-2xl border border-slate-200 dark:border-slate-700 py-1 z-[9999] overflow-hidden">
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
          <button @click.stop="handleDelete(routers.find(r => r.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
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
      :title="searchQuery || filterStatus ? 'No Routers Found' : 'No Routers'"
      :description="searchQuery || filterStatus ? 'No routers match your search criteria.' : 'Get started by adding your first router to begin managing your network infrastructure.'"
      icon="router"
      color-theme="indigo"
      :show-clear="!!searchQuery || !!filterStatus"
      :has-filters="!!filterStatus"
      clear-text="Clear Filters"
      add-text="Add Your First Router"
      @clear="clearFilters"
      @add="openCreateOverlay"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useConfirmStore } from '@/stores/confirm'
import { useRouterUtils } from '@/modules/common/composables/utils/useRouterUtils'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import UpdateOverlay from '@/modules/tenant/components/routers/modals/UpdateRouterModal.vue'
import DetailsOverlay from '@/modules/tenant/components/routers/modals/RouterDetailsModal.vue'
import Overlay from '@/modules/tenant/components/routers/modals/CreateRouterModal.vue'
import ReprovisionOverlay from '@/modules/tenant/components/routers/modals/ReprovisionOverlay.vue'
import MassRouterOrchestrationOverlay from '@/modules/tenant/components/routers/modals/MassRouterOrchestrationOverlay.vue'

const confirmStore = useConfirmStore()

const { error: showError, success: showSuccess } = useToast()

const {
  routers, loading, refreshing, listError, formError, detailsError, detailsLoading, vendorOptions, templateMarketplace,
  showMassOrchestrationOverlay, massOrchestrationPreview, massOrchestrationLoading, massOrchestrationError, massOrchestrationDeploying, massOrchestrationDeployError, massOrchestrationDeployResult,
  showFormOverlay, showDetailsOverlay, showUpdateOverlay, currentRouter, isEditing,
  selectedRouter, formData, formSubmitting, configLoading, formMessage, formSubmitted,
  fetchRouters, addRouter, updateRouter, deleteRouter, reprovisionRouter, generateConfigs,
  previewMassOrchestration, deployMassOrchestration, closeMassOrchestrationOverlay,
  formatTimestamp, openCreateOverlay, openEditOverlay, openDetails, closeDetails, refreshDetails,
  closeFormOverlay, closeUpdateOverlay, copyToClipboard,
  setupRealtimeUpdates, cleanupRealtimeUpdates,
} = useRouters()

const {
  getStatusDotClass, getCpuColorClass, getMemoryColorClass, getDiskColorClass,
  getMemoryUsage, getDiskUsage, getRouterModel, formatModel, formatTimeAgo,
} = useRouterUtils()

const showReprovisionOverlay = ref(false)
const reprovisioningRouter = ref(null)

const activeMenu = ref(null)
const menuPosition = ref({})
const searchQuery = ref('')
const filterStatus = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
let lastFreshFetchAt = 0
const FRESH_FETCH_MIN_INTERVAL_MS = 5000

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
    filtered = filtered.filter(router => 
      (router.name && router.name.toLowerCase().includes(query)) ||
      (router.ip_address && router.ip_address.includes(query)) ||
      (router.model && router.model.toLowerCase().includes(query))
    )
  }
  if (filterStatus.value) {
    filtered = filtered.filter(router => router.status === filterStatus.value)
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
watch(filterStatus, () => { currentPage.value = 1 })

watch(totalPages, (pages) => {
  const safePages = pages || 1
  if (currentPage.value > safePages) currentPage.value = safePages
  if (currentPage.value < 1) currentPage.value = 1
})

const onlineCount = computed(() => routers.value.filter(r => r.status === 'online').length)
const offlineCount = computed(() => routers.value.filter(r => !r.status || r.status === 'offline').length)
const issueCount = computed(() => routers.value.filter(r => r.status === 'error' || r.status === 'rebooting').length)

// Mobile card helpers
const getRouterMetaLines = (router) => {
  const lines = []
  if (router.model) lines.push({ text: formatModel(getRouterModel(router)) })
  if (router.live_data?.cpu_load !== undefined) {
    lines.push({ text: `CPU: ${router.live_data.cpu_load}%` })
  }
  return lines
}

const getRouterActions = (router) => {
  const actions = [
    { label: 'View', onClick: () => openDetails(router), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' }
  ]
  if (router.status === 'online') {
    actions.push({ label: 'Login', onClick: () => loginToRouter(router), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' })
  }
  return actions
}

// Filter helpers
const clearFilters = () => {
  filterStatus.value = ''
  searchQuery.value = ''
  currentPage.value = 1
}

const handleFilterChange = () => {
  // Handled by watcher
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

const closeReprovisionOverlay = () => {
  showReprovisionOverlay.value = false
  reprovisioningRouter.value = null
  fetchRouters()
}

const openMassOrchestration = () => {
  showMassOrchestrationOverlay.value = true
}

const handleMassOrchestrationPreview = async (options) => {
  try {
    await previewMassOrchestration(filteredRouters.value, options)
  } catch (err) {
    console.error('Mass orchestration preview error:', err)
  }
}

const handleMassOrchestrationDeploy = async (options) => {
  try {
    await deployMassOrchestration(filteredRouters.value, options)
    showSuccess('Mass orchestration jobs queued successfully')
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.response?.data?.error || err.message || 'Failed to queue mass orchestration deployment'
    showError(`Mass deployment failed: ${errorMessage}`)
    console.error('Mass orchestration deploy error:', err)
  }
}

const handleReprovisionRetry = async (router) => {
  if (!router) return
  try {
    await reprovisionRouter(router.id)
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.response?.data?.error || err.message || 'Failed to reprovision router'
    showError(`Reprovision failed: ${errorMessage}`)
  }
}

const handleReprovision = async (router) => {
  if (!router) return

  activeMenu.value = null
  menuPosition.value = {}

  const confirmed = await confirmStore.open({
    title: 'Reprovision Router',
    message: `Reprovision "${router.name}"? This resets the provisioning state and restarts the probing process.`,
    confirmText: 'Reprovision',
    cancelText: 'Cancel',
    variant: 'info',
  })
  if (!confirmed) return

  try {
    reprovisioningRouter.value = router
    showReprovisionOverlay.value = true
    await reprovisionRouter(router.id)
  } catch (err) {
    showReprovisionOverlay.value = false
    reprovisioningRouter.value = null
    const errorMessage = err.response?.data?.message || err.response?.data?.error || err.message || 'Failed to reprovision router'
    showError(`Reprovision failed: ${errorMessage}`)
    console.error('Reprovision error:', err)
  }
}

const handleDelete = async (router) => {
  if (!router) return
  
  // Hide menu immediately before showing dialog
  activeMenu.value = null
  menuPosition.value = {}
  
  const confirmed = await confirmStore.open({
    title: 'Delete Router',
    message: `Delete router ${router.name}? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    variant: 'danger'
  })
  
  if (!confirmed) return
  
  try {
    await deleteRouter(router.id)
    showSuccess('Router deleted successfully')
    // Real-time update via WebSocket - no fetchRouters() needed
  } catch (err) {
    const errorMessage = err.response?.data?.error || err.message || 'Failed to delete router'
    showError(`Delete failed: ${errorMessage}`)
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
    // Real-time update via WebSocket - no fetchRouters() needed
  } catch (err) {
    console.error('Form submit error:', err)
  }
}

const loginToRouter = (router) => {
  if (router.status !== 'online') return
  const winboxUrl = `winbox://${router.ip_address}`
  window.open(winboxUrl, '_blank')
}

const refreshIfStale = () => {
  const nowTs = Date.now()
  if (nowTs - lastFreshFetchAt < FRESH_FETCH_MIN_INTERVAL_MS) return
  lastFreshFetchAt = nowTs
  fetchRouters()
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
  setupRealtimeUpdates()
  document.addEventListener('click', handleClickOutside)
  window.addEventListener('focus', handleWindowFocus)
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onUnmounted(() => {
  cleanupRealtimeUpdates()
  document.removeEventListener('click', handleClickOutside)
  window.removeEventListener('focus', handleWindowFocus)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})

const handleClickOutside = (event) => {
  const menuButton = document.querySelector('[data-menu-button]')
  const dropdownMenu = document.querySelector('[data-dropdown-menu]')
  if (activeMenu.value && dropdownMenu && !dropdownMenu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    activeMenu.value = null
    menuPosition.value = {}
  }
}
</script>

<style scoped>
/* Scrollbar — no Tailwind equivalent for ::-webkit-scrollbar pseudo-elements */
::-webkit-scrollbar        { width: 8px; height: 8px; }
::-webkit-scrollbar-track  { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb  { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
:global(.dark) ::-webkit-scrollbar-track { background: #1e293b; }
:global(.dark) ::-webkit-scrollbar-thumb { background: #475569; }
</style>
