<template>
  <DataViewContainer
    title="Access Points"
    subtitle="Manage wireless access points and hotspots"
    color-theme="indigo"
    v-model:search-model="searchQuery"
    search-placeholder="Search access points..."
    :stats="[
      { color: 'bg-emerald-500', value: onlineCount, tooltip: 'Online access points' },
      { color: 'bg-slate-500', value: offlineCount, tooltip: 'Offline access points' },
      { color: 'bg-blue-500', value: totalCount, tooltip: 'Total access points' },
      { color: 'bg-amber-500', value: totalActiveUsers, tooltip: 'Active users' }
    ]"
    :total="accessPoints?.length || 0"
    :loading="loading"
    add-button-text="Add Access Point"
    @refresh="fetchAccessPoints"
    @search-clear="searchQuery = ''"
    @add="openCreateModal"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="online">Online</option>
        <option value="offline">Offline</option>
        <option value="unknown">Unknown</option>
      </BaseSelect>
      <BaseSelect v-model="filters.router_id" placeholder="All Routers" class="w-44">
        <option value="">All Routers</option>
        <option v-for="router in availableRouters" :key="router.id" :value="router.id">{{ router.name }}</option>
      </BaseSelect>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchAccessPoints" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredAccessPoints?.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="ap in paginatedAccessPoints"
          :key="ap?.id"
          :title="ap?.name || 'Unnamed'"
          :subtitle="ap?.location || 'No Location'"
          :meta-lines="getAPMetaLines(ap)"
          :status="ap?.status"
          :actions="getAPActions(ap)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
          <table class="w-full">
            <thead>
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[22%]">Name</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell w-[15%]">IP Address</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Router</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Active Users</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell w-[12%]">Vendor</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[14%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="ap in paginatedAccessPoints" :key="ap?.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4 w-[22%]">
                  <div class="flex items-center gap-2">
                    <span :class="getStatusDotClass(ap?.status)" class="w-1.5 h-1.5 rounded-full"></span>
                    <div class="flex items-center gap-3">
                      <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 font-bold text-sm">
                        {{ ap?.name?.charAt(0).toUpperCase() || '?' }}
                      </div>
                      <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ ap?.name || 'Unnamed' }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ ap?.location || 'No Location' }}</p>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell w-[15%]">
                  <p class="text-sm text-slate-600 font-mono">{{ ap?.ip_address || '—' }}</p>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <p class="text-sm text-slate-900">{{ getRouterName(ap?.router_id) }}</p>
                </td>
                <td class="px-6 py-4 w-[10%]">
                  <EntityStatusBadge :status="ap?.status" size="sm" />
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ ap?.active_users || 0 }}</p>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell w-[12%]">
                  <p class="text-sm text-slate-600 capitalize">{{ ap?.vendor || '—' }}</p>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      @click="viewAP(ap)"
                      class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors"
                    >
                      View
                    </button>
                    <div class="relative">
                      <button data-menu-button @click="toggleMenu(ap.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
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
      <DataPagination
        v-model:current-page="currentPage"
        v-model:items-per-page="itemsPerPage"
        :total-pages="totalPages"
        :total-items="filteredAccessPoints?.length || 0"
        item-name="access points"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Access Points Found' : 'No Access Points'"
      :description="searchQuery ? 'No access points match your search criteria. Try adjusting your filters.' : 'Get started by adding your first access point.'"
      icon="wifi"
      color-theme="indigo"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Search"
      add-text="Add Your First Access Point"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
  <APModal
    v-model="showFormOverlay"
    :is-editing="isEditing"
    :ap="editingAP"
    :available-routers="availableRouters"
    :submitting="formSubmitting"
    :error="formError"
    @close="closeForm"
    @submit="handleSubmit"
  />

  <!-- AP Details Modal -->
  <APDetailsModal
    v-model="showDetailsOverlay"
    :ap-details="selectedAP"
    :loading="detailsLoading"
    :error="detailsError"
    @close="closeDetails"
    @sync="handleSync(selectedAP)"
  />

  <!-- Global Dropdown Menu Portal -->
  <Teleport to="body">
    <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white dark:bg-slate-800 rounded-lg shadow-2xl border border-slate-200 dark:border-slate-700 py-1 z-[9999] overflow-hidden">
      <button v-if="currentAP?.status !== 'online'" @click="handleSync(currentAP)" :disabled="syncingIds.has(activeMenu) || !currentAP" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors disabled:opacity-50">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" :class="{ 'animate-spin': syncingIds.has(activeMenu) }">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        Sync
      </button>
      <button @click="handleEdit(currentAP)" :disabled="!currentAP" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors disabled:opacity-50">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
        </svg>
        Edit
      </button>
      <button @click="viewAP(currentAP)" :disabled="!currentAP" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors disabled:opacity-50">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
        </svg>
        View
      </button>
      <div class="border-t border-slate-200 my-1"></div>
      <button @click="handleDelete(currentAP)" :disabled="!currentAP" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors disabled:opacity-50">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
        </svg>
        Delete
      </button>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useAccessPoints } from '@/modules/tenant/composables/useAccessPoints'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import APModal from '@/modules/tenant/components/access-points/APModal.vue'
import APDetailsModal from '@/modules/tenant/components/access-points/APDetailsModal.vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

const {
  accessPoints,
  onlineAccessPoints,
  offlineAccessPoints,
  availableRouters,
  loading,
  error,
  fetchAccessPoints,
  fetchStatistics,
  createAccessPoint,
  updateAccessPoint,
  deleteAccessPoint,
  syncAccessPoint,
  fetchAccessPoint,
  searchAccessPoints,
  fetchAvailableRouters,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useAccessPoints()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Form state
const showFormOverlay = ref(false)
const isEditing = ref(false)
const editingAP = ref(null)
const formSubmitting = ref(false)
const formError = ref('')

// Details state
const showDetailsOverlay = ref(false)
const selectedAP = ref(null)
const detailsLoading = ref(false)
const detailsError = ref('')

// Menu state
const activeMenu = ref(null)
const menuPosition = ref({})
const syncingIds = ref(new Set())

// Filters
const filters = ref({ status: '', router_id: '' })

// Stats
const onlineCount = computed(() => onlineAccessPoints.value?.length ?? 0)
const offlineCount = computed(() => offlineAccessPoints.value?.length ?? 0)
const totalCount = computed(() => accessPoints.value?.length ?? 0)
const totalActiveUsers = computed(() => 
  accessPoints.value?.reduce((sum, ap) => sum + (ap?.active_users || 0), 0) ?? 0
)


// Filter and paginate
const filteredAccessPoints = computed(() => {
  let data = accessPoints.value || []
  if (searchQuery.value) {
    data = searchAccessPoints(searchQuery.value)
  }
  if (filters.value.status) {
    data = data.filter(ap => ap.status === filters.value.status)
  }
  if (filters.value.router_id) {
    data = data.filter(ap => ap.router_id === filters.value.router_id)
  }
  return data
})

const paginatedAccessPoints = computed(() => {
  if (!filteredAccessPoints.value || !Array.isArray(filteredAccessPoints.value)) return []
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredAccessPoints.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil((filteredAccessPoints.value?.length || 0) / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.router_id || searchQuery.value)

// Current AP for menu
const currentAP = computed(() => accessPoints.value.find(ap => ap.id === activeMenu.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })
watch(() => filters.value.status, () => { currentPage.value = 1 })
watch(() => filters.value.router_id, () => { currentPage.value = 1 })

// Menu toggle
const toggleMenu = (apId, event) => {
  event.stopPropagation()
  if (activeMenu.value === apId) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = apId
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

const closeMenu = () => {
  activeMenu.value = null
  menuPosition.value = {}
}

// Click outside handler
const handleClickOutside = (event) => {
  const menu = document.querySelector('[data-dropdown-menu]')
  const menuButton = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    closeMenu()
  }
}

// Keyboard handler
const handleKeydown = (event) => {
  if (event.key === 'Escape') closeMenu()
}

// Helpers
const getRouterName = (routerId) => {
  const router = availableRouters.value.find(r => r.id === routerId)
  return router ? router.name : 'Unknown'
}

const getStatusDotClass = (status) => {
  if (status === 'online') return 'bg-emerald-500'
  if (status === 'offline') return 'bg-red-500'
  return 'bg-slate-400'
}

const getAPMetaLines = (ap) => {
  const lines = []
  if (ap?.ip_address) lines.push({ text: ap.ip_address, class: 'font-mono' })
  if (ap?.active_users !== undefined) lines.push({ text: `${ap.active_users} active users`, class: 'text-slate-600' })
  return lines
}

const getAPActions = (ap) => {
  const actions = []
  actions.push({ label: 'View', onClick: () => viewAP(ap), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' })
  
  if (ap?.status !== 'online') {
    actions.push({ label: 'Sync', onClick: () => handleSync(ap), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100', disabled: syncingIds.value.has(ap.id) })
  }
  
  actions.push({ label: 'Edit', onClick: () => handleEdit(ap), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
  actions.push({ label: 'Delete', onClick: () => handleDelete(ap), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  
  return actions
}

// Form helpers
const openCreateModal = () => {
  isEditing.value = false
  editingAP.value = null
  formError.value = ''
  showFormOverlay.value = true
  if (!availableRouters.value.length) {
    void fetchAvailableRouters()
  }
}

const handleEdit = (ap) => {
  closeMenu()
  if (!ap) return
  isEditing.value = true
  editingAP.value = ap
  showFormOverlay.value = true
  if (!availableRouters.value.length) {
    void fetchAvailableRouters()
  }
}

const closeForm = () => {
  showFormOverlay.value = false
  setTimeout(() => {
    isEditing.value = false
    editingAP.value = null
    formError.value = ''
  }, 300)
}

const handleSubmit = async (formDataValue) => {
  if (!formDataValue.name || !formDataValue.router_id) {
    formError.value = 'Name and Router are required.'
    return
  }
  
  formSubmitting.value = true
  formError.value = ''

  try {
    if (isEditing.value) {
      await updateAccessPoint(editingAP.value.id, formDataValue)
    } else {
      await createAccessPoint(formDataValue)
    }
    closeForm()
    await fetchAccessPoints()
  } catch (err) {
    formError.value = err.message || 'Failed to save access point'
  } finally {
    formSubmitting.value = false
  }
}

const handleSync = async (ap) => {
  closeMenu()
  if (!ap) return
  syncingIds.value.add(ap.id)
  try {
    await syncAccessPoint(ap.id)
  } finally {
    syncingIds.value.delete(ap.id)
  }
}

const handleDelete = async (ap) => {
  closeMenu()
  if (!ap) return
  const confirmed = await confirmStore.open({
    title: 'Delete Access Point',
    message: `Are you sure you want to delete "${ap.name}"? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    variant: 'danger'
  })
  
  if (!confirmed) return
  
  try {
    await deleteAccessPoint(ap.id)
  } catch (err) {
    console.error('Failed to delete access point:', err)
  }
}

const viewAP = async (ap) => {
  closeMenu()
  if (!ap) return
  selectedAP.value = ap
  showDetailsOverlay.value = true
  detailsLoading.value = true
  detailsError.value = ''
  
  try {
    // Fetch fresh AP details from API
    const freshAP = await fetchAccessPoint(ap.id)
    if (freshAP) {
      selectedAP.value = freshAP
    }
  } catch (err) {
    detailsError.value = err.message || 'Failed to load access point details'
    console.error('Failed to fetch access point details:', err)
  } finally {
    detailsLoading.value = false
  }
}

const closeDetails = () => {
  showDetailsOverlay.value = false
  setTimeout(() => { selectedAP.value = null }, 300)
}

// Lifecycle
onMounted(() => {
  void fetchAccessPoints()
  setupWebSocketListeners()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  cleanupWebSocketListeners()
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
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
