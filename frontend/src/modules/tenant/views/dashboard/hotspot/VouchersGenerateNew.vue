<template>
  <DataViewContainer
    title="Voucher Management"
    subtitle="Generate and manage hotspot vouchers"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search by voucher code..."
    :stats="statsForView"
    :total="pagination.total"
    :loading="loading"
    add-button-text="Create Voucher"
    @refresh="refreshVouchers"
    @add="openCreateOverlay"
    @search-clear="searchQuery = ''"
  >

    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
      </svg>
    </template>

    <!-- Create Voucher Overlay -->
    <SlideOverlay v-model="showCreateOverlay" title="Create Voucher" subtitle="Generate new hotspot vouchers" icon="Ticket" width="60%" @close="closeCreateOverlay">
      <form @submit.prevent="generateVouchers" class="space-y-5">
        <!-- Package Selection -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Select Package *</label>
          <select v-model="formData.package_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
            <option value="">Choose a package...</option>
            <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
          </select>
          <p v-if="selectedPackage" class="mt-1.5 text-xs text-gray-500 dark:text-slate-400">
            Price: KES {{ selectedPackage.price }} | Speed: {{ selectedPackage.download_speed || '-' }} | Validity: {{ selectedPackage.validity || '-' }}
          </p>
        </div>

        <!-- Quantity -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Number of Vouchers *</label>
          <input v-model.number="formData.quantity" type="number" min="1" max="100" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" placeholder="1-100" />
          <p class="mt-1 text-xs text-gray-400">Maximum 100 vouchers per batch</p>
        </div>

        <!-- Prefix -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Voucher Prefix (Optional)</label>
          <input v-model="formData.prefix" type="text" maxlength="10" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" placeholder="e.g., WIFI, HOT" />
        </div>

        <!-- Expiry Date -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Expiry Date (Optional)</label>
          <input v-model="formData.expires_at" type="date" :min="minDate" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" />
        </div>

        <!-- Notes -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Notes (Optional)</label>
          <textarea v-model="formData.notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500" placeholder="Any notes..."></textarea>
        </div>

        <!-- Summary -->
        <div v-if="formData.package_id && formData.quantity" class="bg-cyan-50 border border-cyan-200 rounded-lg p-3">
          <h4 class="text-xs font-semibold text-cyan-900 mb-2">Summary</h4>
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div><span class="text-cyan-600">Package:</span> <span class="font-medium text-cyan-900">{{ selectedPackage?.name }}</span></div>
            <div><span class="text-cyan-600">Quantity:</span> <span class="font-medium text-cyan-900">{{ formData.quantity }}</span></div>
            <div><span class="text-cyan-600">Total Value:</span> <span class="font-medium text-cyan-900">KES {{ totalValue }}</span></div>
            <div><span class="text-cyan-600">Prefix:</span> <span class="font-medium text-cyan-900">{{ formData.prefix || 'None' }}</span></div>
          </div>
        </div>

        <!-- Error -->
        <div v-if="generateError" class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ generateError }}</div>
      </form>

      <template #footer>
        <div class="flex gap-3">
          <button type="button" @click="closeCreateOverlay" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">Cancel</button>
          <button @click="handleGenerate" :disabled="generating || !formData.package_id || !formData.quantity" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-cyan-600 rounded-lg hover:bg-cyan-700 transition-colors disabled:opacity-50">
            <Ticket class="w-4 h-4" />
            {{ generating ? 'Generating...' : `Generate ${formData.quantity || 0} Voucher${formData.quantity !== 1 ? 's' : ''}` }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Voucher Detail Overlay -->
    <SlideOverlay v-model="showDetailOverlay" title="Voucher Details" subtitle="View voucher information" icon="Ticket" width="60%" @close="closeDetailOverlay">
      <div v-if="selectedVoucher" class="space-y-3">
        <div class="flex items-center justify-center p-4 bg-cyan-50 rounded-lg">
          <span class="font-mono text-xl font-bold text-cyan-700">{{ selectedVoucher.code }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Status</span>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(selectedVoucher.status)">{{ selectedVoucher.status }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Package</span>
          <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ selectedVoucher.package?.name || '-' }}</span>
        </div>
        <div v-if="selectedVoucher.package?.price" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Price</span>
          <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">KES {{ selectedVoucher.package.price }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Router</span>
          <span class="text-sm text-gray-900 dark:text-slate-100">{{ selectedVoucher.router?.name || 'Any' }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Expires</span>
          <span class="text-sm text-gray-900 dark:text-slate-100">{{ selectedVoucher.expires_at ? formatDate(selectedVoucher.expires_at) : 'No expiry' }}</span>
        </div>
        <div v-if="selectedVoucher.used_at" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Used At</span>
          <span class="text-sm text-gray-900 dark:text-slate-100">{{ formatDate(selectedVoucher.used_at) }}</span>
        </div>
        <div v-if="selectedVoucher.notes" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Notes</span>
          <span class="text-sm text-gray-900 text-right max-w-[60%]">{{ selectedVoucher.notes }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Created</span>
          <span class="text-sm text-gray-900 dark:text-slate-100">{{ formatDate(selectedVoucher.created_at) }}</span>
        </div>
        <div v-if="selectedVoucher.batch_id" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Batch ID</span>
          <span class="text-xs font-mono text-gray-500">{{ selectedVoucher.batch_id }}</span>
        </div>
      </div>
      <template #footer>
        <div class="flex gap-3">
          <button type="button" @click="closeDetailOverlay" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">Close</button>
          <button v-if="selectedVoucher?.status === 'unused'" @click="handleRevoke(selectedVoucher)" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
            <Ban class="w-4 h-4" />Revoke
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filterStatus" placeholder="All Statuses" class="w-40" @change="handleFilterChange">
        <option value="">All Statuses</option>
        <option value="unused">Unused</option>
        <option value="used">Used</option>
        <option value="expired">Expired</option>
        <option value="revoked">Revoked</option>
      </BaseSelect>
      <BaseSelect v-model="filterPackage" placeholder="All Packages" class="w-48" @change="handleFilterChange">
        <option value="">All Packages</option>
        <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
      </BaseSelect>
      <button v-if="hasActiveFilters" @click="clearFilters" class="text-xs text-cyan-600 hover:text-cyan-700 font-medium">Clear filters</button>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <Ticket class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="refreshVouchers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading && !vouchers.length" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredVouchers.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="voucher in paginatedVouchers"
          :key="voucher.id"
          :title="voucher.code"
          :subtitle="voucher.package?.name || 'No package'"
          :meta-lines="[
            { text: `Status: ${voucher.status}`, class: statusClass(voucher.status) },
            { text: `Expires: ${voucher.expires_at ? formatDate(voucher.expires_at) : 'No expiry'}` },
            { text: `Created: ${formatDate(voucher.created_at)}` }
          ]"
          :status="voucher.status"
          :actions="getVoucherActions(voucher)"
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
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[20%]">Code</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[25%]">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[18%]">Expires</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Created</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="voucher in paginatedVouchers" :key="voucher.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4 w-[20%]">
                  <span class="font-mono text-sm font-semibold text-cyan-700">{{ voucher.code }}</span>
                </td>
                <td class="px-6 py-4 w-[25%]">
                  <span class="text-sm text-slate-900">{{ voucher.package?.name || '-' }}</span>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize" :class="statusClass(voucher.status)">{{ voucher.status }}</span>
                </td>
                <td class="px-6 py-4 w-[18%]">
                  <span class="text-xs text-slate-500 dark:text-slate-400">{{ voucher.expires_at ? formatDate(voucher.expires_at) : 'No expiry' }}</span>
                </td>
                <td class="px-6 py-4 w-[15%]">
                  <span class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(voucher.created_at) }}</span>
                </td>
                <td class="px-6 py-4 text-right w-[10%]">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openDetailOverlay(voucher)" class="p-1.5 text-cyan-600 hover:bg-cyan-50 rounded-md transition-colors" title="View Details">
                      <Eye class="w-4 h-4" />
                    </button>
                    <button v-if="voucher.status === 'unused'" @click="handleRevoke(voucher)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors" title="Revoke Voucher">
                      <Ban class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="pagination.lastPage" :total-items="pagination.total" item-name="vouchers" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery || hasActiveFilters ? 'No Matches Found' : 'No Vouchers'"
      :description="searchQuery || hasActiveFilters ? 'No vouchers match your search criteria.' : 'Create your first voucher to get started.'"
      icon="box"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Filters"
      add-text="Create Voucher"
      @clear="clearFilters"
      @add="openCreateOverlay"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Ticket, Eye, Ban } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useVouchers } from '@/modules/tenant/composables/useVouchers'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

const {
  vouchers,
  packages,
  loading,
  error,
  generating,
  generateError,
  pagination,
  statsForView,
  fetchPackages,
  fetchVouchers,
  refreshVouchers,
  fetchStats,
  fetchVoucherDetails,
  goToPage,
  generateVouchers,
  revokeVoucher,
  filterVouchers,
  statusClass,
  formatDate,
  getPackageById,
  calculateTotalValue,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useVouchers()

// State
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(25)
const showCreateOverlay = ref(false)
const showDetailOverlay = ref(false)
const selectedVoucher = ref(null)
const filterStatus = ref('')
const filterPackage = ref('')

const filters = computed(() => ({
  status: filterStatus.value,
  package_id: filterPackage.value
}))

const hasActiveFilters = computed(() => filterStatus.value || filterPackage.value)

// Computed
const filteredVouchers = computed(() => filterVouchers(searchQuery.value, filters.value))

const paginatedVouchers = computed(() => {
  // Use server-side pagination if no local filters
  if (!searchQuery.value && !filterStatus.value && !filterPackage.value) {
    return vouchers.value
  }
  // Use client-side filtering with pagination
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredVouchers.value.slice(start, end)
})

const selectedPackage = computed(() => getPackageById(formData.value.package_id))
const totalValue = computed(() => calculateTotalValue(selectedPackage.value, formData.value.quantity))
const minDate = computed(() => new Date().toISOString().split('T')[0])

// Form
const formData = ref({
  package_id: '',
  quantity: 10,
  prefix: '',
  expires_at: '',
  notes: ''
})

// Reset page on search/filter change
watch([searchQuery, itemsPerPage], () => {
  currentPage.value = 1
})

// Watch filter changes to trigger API call
watch([filterStatus, filterPackage], () => {
  currentPage.value = 1
  fetchVouchers({
    page: 1,
    status: filterStatus.value || undefined,
    package_id: filterPackage.value || undefined
  })
})

// Actions
const clearFilters = () => {
  filterStatus.value = ''
  filterPackage.value = ''
  searchQuery.value = ''
  currentPage.value = 1
  fetchVouchers({ page: 1 })
}

const handleFilterChange = () => {
  // Handled by watcher
}

const openCreateOverlay = () => {
  formData.value = { package_id: '', quantity: 10, prefix: '', expires_at: '', notes: '' }
  showCreateOverlay.value = true
}

const closeCreateOverlay = () => {
  showCreateOverlay.value = false
}

const openDetailOverlay = async (voucher) => {
  showDetailOverlay.value = true
  selectedVoucher.value = voucher

  try {
    const details = await fetchVoucherDetails(voucher.id)
    if (details?.id === voucher.id) {
      selectedVoucher.value = details
    }
  } catch (err) {
    console.error('Failed to load voucher details:', err)
  }
}

const closeDetailOverlay = () => {
  showDetailOverlay.value = false
  selectedVoucher.value = null
}

const handleGenerate = async () => {
  const success = await generateVouchers(formData.value)
  if (success) {
    closeCreateOverlay()
  }
}

const handleRevoke = async (voucher) => {
  const confirmed = await confirmStore.open({
    title: 'Revoke Voucher',
    message: `Are you sure you want to revoke voucher ${voucher.code}? This action cannot be undone.`,
    confirmText: 'Revoke',
    cancelText: 'Cancel',
    variant: 'danger'
  })
  
  if (!confirmed) return
  
  const success = await revokeVoucher(voucher)
  if (success) {
    closeDetailOverlay()
  }
}

const getVoucherActions = (voucher) => {
  const actions = [
    { label: 'View', onClick: () => openDetailOverlay(voucher), class: 'text-cyan-700 bg-cyan-50 hover:bg-cyan-100' }
  ]
  if (voucher.status === 'unused') {
    actions.push({ label: 'Revoke', onClick: () => handleRevoke(voucher), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  }
  return actions
}

// Lifecycle
onMounted(() => {
  void fetchVouchers().catch(() => {})
  setupWebSocketListeners()

  requestAnimationFrame(() => {
    void fetchPackages().catch(() => {})
    void fetchStats().catch(() => {})
  })
})

onUnmounted(() => {
  cleanupWebSocketListeners()
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
