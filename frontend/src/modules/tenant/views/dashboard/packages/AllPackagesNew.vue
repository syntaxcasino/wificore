<template>
  <DataViewContainer
    title="Package Management"
    subtitle="Manage your internet service packages"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search packages by name or description..."
    :stats="[
      { color: 'bg-blue-500', value: packages?.length || 0, tooltip: 'Total packages' },
      { color: 'bg-emerald-500', value: activeCount, tooltip: 'Active packages' },
      { color: 'bg-slate-500', value: inactiveCount, tooltip: 'Inactive packages' }
    ]"
    :total="packages?.length || 0"
    :loading="loading"
    add-button-text="Add Package"
    @refresh="fetchPackages"
    @add="openCreateOverlay"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.type" placeholder="All Types" class="w-36">
        <option value="">All Types</option>
        <option value="hotspot">Hotspot</option>
        <option value="pppoe">PPPoE</option>
      </BaseSelect>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </BaseSelect>
    </template>
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

    <!-- Error State -->
    <div v-if="listError" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ listError }}</p>
      <button @click="fetchPackages" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData?.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="pkg in paginatedData"
          :key="pkg.id"
          :title="pkg.name"
          :subtitle="pkg.description || '—'"
          :meta-lines="getPackageMetaLines(pkg)"
          :status="pkg.status"
          :actions="getPackageActions(pkg)"
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
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[25%]">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell w-[12%]">Type</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Price</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell w-[15%]">Speed</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell w-[12%]">Validity</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Status</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[14%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr
                v-for="pkg in paginatedData"
                :key="pkg.id"
                @click="openDetails(pkg)"
                class="group cursor-pointer hover:bg-blue-50/50 transition-colors"
              >
                <td class="px-6 py-4 w-[25%]">
                  <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="pkg.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                    <span class="text-sm font-medium text-slate-900 truncate">{{ pkg.name }}</span>
                  </div>
                </td>

                <td class="px-6 py-4 hidden lg:table-cell w-[12%]">
                  <span v-if="pkg.type" class="text-xs text-slate-500 truncate">{{ pkg.type }}</span>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>

                <td class="px-6 py-4 w-[12%]">
                  <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">KES {{ formatMoney(pkg.price) }}</span>
                </td>

                <td class="px-6 py-4 hidden xl:table-cell w-[15%]">
                  <span class="text-xs text-slate-500 truncate">{{ formatPackageSpeed(pkg) }}</span>
                </td>

                <td class="px-6 py-4 hidden xl:table-cell w-[12%]">
                  <span v-if="pkg.validity" class="text-xs text-slate-500 truncate">{{ pkg.validity }}</span>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>

                <td class="px-6 py-4 w-[10%]">
                  <EntityStatusBadge :status="pkg.status" size="sm" />
                </td>

                <td class="px-6 py-4 text-right w-[14%]" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button
                      @click="openDetails(pkg)"
                      class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors"
                    >
                      View
                    </button>
                    <div class="relative">
                      <button data-menu-button @click.stop="toggleMenu(pkg.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
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
        :total-items="filteredData?.length || 0"
        item-name="packages"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
          :title="searchQuery ? 'No Matches Found' : 'No Packages Found'"
          :description="searchQuery ? 'No packages match your search criteria.' : 'Get started by creating your first internet service package.'"
          icon="package"
          color-theme="blue"
          :show-clear="!!searchQuery"
          :has-filters="hasActiveFilters"
          clear-text="Clear Search"
          add-text="Add Package"
          @clear="searchQuery = ''"
          @add="openCreateOverlay"
        />

    <!-- Global Dropdown Menu Portal -->
    <Teleport to="body">
      <div
        v-if="activeMenu !== null"
        data-dropdown-menu
        :style="menuPosition"
        class="fixed w-48 bg-white dark:bg-slate-800 rounded-lg shadow-2xl border border-slate-200 dark:border-slate-700 py-1 z-[9999] overflow-hidden"
      >
        <button @click="handleViewMenu()"
          class="flex items-center w-full px-4 py-2.5 text-sm text-blue-600 hover:bg-blue-50 transition-colors md:hidden">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
          View Details
        </button>
        <button @click="handleToggleStatusMenu()"
          :class="[
            'flex items-center w-full px-4 py-2.5 text-sm transition-colors',
            getMenuPackage()?.status === 'active'
              ? 'text-amber-700 hover:bg-amber-50'
              : 'text-emerald-700 hover:bg-emerald-50'
          ]">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          {{ getMenuPackage()?.status === 'active' ? 'Deactivate' : 'Activate' }}
        </button>
        <div class="border-t border-slate-200 my-1"></div>
        <button @click="handleEditMenu()"
          class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
          </svg>
          Edit Package
        </button>
        <button @click="handleDuplicateMenu()"
          class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          Duplicate
        </button>
        <div class="border-t border-slate-200 my-1"></div>
        <button @click="handleDeleteMenu()"
          class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
          Delete Package
        </button>
      </div>
    </Teleport>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { useConfirmStore } from '@/stores/confirm'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useSSE } from '@/modules/common/composables/websocket/useSSE'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'

import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import CreatePackageOverlay from '@/modules/tenant/components/packages/overlays/CreatePackageOverlay.vue'
import ViewPackageOverlay from '@/modules/tenant/components/packages/overlays/ViewPackageOverlay.vue'

const { error: showError } = useToast()

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

// Filtering and Pagination
const { filters, searchQuery, filteredData, hasActiveFilters } = useFilters(packages, { type: '', status: '' })
const { currentPage, itemsPerPage, paginatedData, totalPages } = usePagination(filteredData, 10)

// Stats
const activeCount = computed(() => packages.value?.filter(p => p.status === 'active').length || 0)
const inactiveCount = computed(() => packages.value?.filter(p => p.status === 'inactive').length || 0)

// Reset page on search/filter changes (matching TodosView pattern)
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })
watch(() => filters.value.type, () => { currentPage.value = 1 })
watch(() => filters.value.status, () => { currentPage.value = 1 })

// Menu state
const activeMenu = ref(null)
const menuPosition = ref({})

const toggleMenu = (id, event) => {
  if (activeMenu.value === id) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = id
    const rect = event.currentTarget.getBoundingClientRect()
    menuPosition.value = {
      top: `${rect.bottom + 4}px`,
      right: `${window.innerWidth - rect.right}px`
    }
  }
}

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const formatPackageSpeed = (pkg) => {
  if (!pkg) return '—'
  const down = pkg.download_speed ? String(pkg.download_speed).trim() : ''
  const up = pkg.upload_speed ? String(pkg.upload_speed).trim() : ''

  if (down && up) return `${down} / ${up}`
  return down || up || pkg.speed || '—'
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
    showError(`Failed to ${action} package`)
  }
}

const getPackageMetaLines = (pkg) => {
  const lines = []
  lines.push({ text: `KES ${formatMoney(pkg.price)}` })
  lines.push({ text: formatPackageSpeed(pkg) })
  if (pkg.type) lines.push({ text: pkg.type, class: 'capitalize' })
  return lines
}

const getPackageActions = (pkg) => {
  const actions = []
  actions.push({ label: 'View', onClick: () => openDetails(pkg), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' })
  actions.push({ label: 'Edit', onClick: () => openEditOverlay(pkg), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
  actions.push({ label: 'Duplicate', onClick: () => duplicatePackage(pkg), class: 'text-indigo-700 bg-indigo-50 hover:bg-indigo-100' })
  actions.push({
    label: pkg.status === 'active' ? 'Deactivate' : 'Activate',
    onClick: () => handleToggleStatus(pkg),
    class: pkg.status === 'active' ? 'text-amber-700 bg-amber-50 hover:bg-amber-100' : 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100'
  })
  return actions
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
    showError('Failed to delete package')
  }
}

const closeMenu = () => {
  activeMenu.value = null
  menuPosition.value = {}
}

const handleClickOutside = (event) => {
  const menu = document.querySelector('[data-dropdown-menu]')
  const menuButton = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    closeMenu()
  }
}

const handleKeydown = (event) => {
  if (event.key === 'Escape') closeMenu()
}

// Menu action handlers with closeMenu
const getMenuPackage = () => packages.value.find(p => p.id === activeMenu.value)

const handleViewMenu = () => {
  const pkg = getMenuPackage()
  closeMenu()
  if (pkg) openDetails(pkg)
}

const handleToggleStatusMenu = () => {
  const pkg = getMenuPackage()
  closeMenu()
  if (pkg) handleToggleStatus(pkg)
}

const handleEditMenu = () => {
  const pkg = packages.value.find(p => p.id === activeMenu.value)
  closeMenu()
  if (pkg) openEditOverlay(pkg)
}

const handleDuplicateMenu = () => {
  const pkg = packages.value.find(p => p.id === activeMenu.value)
  closeMenu()
  if (pkg) duplicatePackage(pkg)
}

const handleDeleteMenu = async () => {
  const pkg = packages.value.find(p => p.id === activeMenu.value)
  closeMenu()
  if (!pkg) return

  const confirmed = await confirmStore.open({
    title: 'Confirm Delete',
    message: `Are you sure you want to delete ${pkg.name}? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    variant: 'danger'
  })

  if (confirmed) {
    try {
      await deletePackage(pkg.id)
    } catch (err) {
      showError('Failed to delete package')
    }
  }
}

// Pagination navigation helpers (available for programmatic use)
const goToFirstPage = () => { currentPage.value = 1 }
const goToPreviousPage = () => { currentPage.value = Math.max(1, currentPage.value - 1) }
const goToNextPage = () => { currentPage.value = Math.min(totalPages.value, currentPage.value + 1) }
const goToLastPage = () => { currentPage.value = totalPages.value }

// Event-driven state handlers (no page refresh)
const handlePackageCreatedEvent = (event) => {
  const pkg = event?.package || event?.data?.package || event?.data
  if (!pkg?.id) return
  
  // Check if already exists (avoid duplicates from optimistic updates)
  const exists = packages.value.some(p => p.id === pkg.id)
  if (!exists) {
    packages.value.unshift(pkg)
    console.log('[Packages] Added via event:', pkg.name)
  }
}

const handlePackageUpdatedEvent = (event) => {
  const pkg = event?.package || event?.data?.package || event?.data
  if (!pkg?.id) return
  
  const index = packages.value.findIndex(p => p.id === pkg.id)
  if (index !== -1) {
    packages.value.splice(index, 1, { ...packages.value[index], ...pkg })
    console.log('[Packages] Updated via event:', pkg.name)
  }
}

const handlePackageDeletedEvent = (event) => {
  const id = event?.packageId || event?.package?.id || event?.id
  if (!id) return
  
  packages.value = packages.value.filter(p => p.id !== id)
  console.log('[Packages] Deleted via event:', id)
}

watch([filteredData, itemsPerPage], () => {
  if (currentPage.value > totalPages.value) {
    currentPage.value = Math.max(1, totalPages.value)
  }
  if (currentPage.value < 1) {
    currentPage.value = 1
  }
})

onMounted(() => {
  fetchPackages()
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})

// SSE: event-driven package updates — useSSE auto-closes on onUnmounted
const { subscribeMany } = useSSE('/sse/tenant', {
  channels: 'packages',
})

subscribeMany({
  PackageCreated:      (data) => handlePackageCreatedEvent(data),
  PackageUpdated:      (data) => handlePackageUpdatedEvent(data),
  PackageDeleted:      (data) => handlePackageDeletedEvent(data),
  PackageStatusChanged: (data) => handlePackageUpdatedEvent(data),
})
</script>
