<template>
  <div class="flex flex-col h-full bg-gradient-to-br from-slate-50 via-gray-50 to-blue-50/30 rounded-lg shadow-lg overflow-hidden">
    <!-- Header -->
    <div class="flex-shrink-0 bg-white border-b border-slate-200 shadow-sm">
      <div class="px-4 md:px-6 py-3 md:py-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 md:gap-6">
          <!-- Left: Title & Icon -->
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 md:w-11 md:h-11 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <Ticket class="h-5 w-5 md:h-6 md:w-6 text-white" />
            </div>
            <div>
              <h2 class="text-lg md:text-xl font-bold text-slate-900">Voucher Management</h2>
              <p class="text-xs text-slate-500 mt-0.5 hidden md:block">Generate and manage hotspot vouchers</p>
            </div>
          </div>
          
          <!-- Right: Actions -->
          <div class="flex items-center gap-2 md:gap-3">
            <button @click="fetchVouchers" :disabled="loading" class="inline-flex items-center gap-1.5 px-2 md:px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
              <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
              <span class="hidden md:inline">Refresh</span>
            </button>
            <button @click="openCreateOverlay" class="inline-flex items-center gap-1.5 px-3 md:px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg">
              <Plus class="w-4 h-4" />
              <span class="hidden sm:inline">Create Voucher</span>
              <span class="sm:hidden">Create</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Area - Scrollable -->
    <div class="flex-1 min-h-0 overflow-y-auto">
      <div class="p-4 md:p-6 space-y-4">
        <!-- Stats Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 md:gap-4">
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 md:p-4 text-center">
            <div class="text-xl md:text-2xl font-bold text-slate-900">{{ stats.total || 0 }}</div>
            <div class="text-xs text-slate-500 mt-1">Total</div>
          </div>
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 md:p-4 text-center">
            <div class="text-xl md:text-2xl font-bold text-green-600">{{ stats.unused || 0 }}</div>
            <div class="text-xs text-slate-500 mt-1">Unused</div>
          </div>
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 md:p-4 text-center">
            <div class="text-xl md:text-2xl font-bold text-blue-600">{{ stats.used || 0 }}</div>
            <div class="text-xs text-slate-500 mt-1">Used</div>
          </div>
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 md:p-4 text-center">
            <div class="text-xl md:text-2xl font-bold text-yellow-600">{{ stats.expired || 0 }}</div>
            <div class="text-xs text-slate-500 mt-1">Expired</div>
          </div>
          <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 md:p-4 text-center">
            <div class="text-xl md:text-2xl font-bold text-red-600">{{ stats.revoked || 0 }}</div>
            <div class="text-xs text-slate-500 mt-1">Revoked</div>
          </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 md:p-4">
          <div class="flex flex-wrap items-center gap-2 md:gap-3">
            <input v-model="searchQuery" type="text" placeholder="Search by code..." class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full md:w-64" @input="debouncedFetch" />
            <select v-model="filterStatus" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 flex-1 md:flex-initial" @change="fetchVouchers">
              <option value="">All Statuses</option>
              <option value="unused">Unused</option>
              <option value="used">Used</option>
              <option value="expired">Expired</option>
              <option value="revoked">Revoked</option>
            </select>
            <select v-model="filterPackage" class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 flex-1 md:flex-initial" @change="fetchVouchers">
              <option value="">All Packages</option>
              <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
            </select>
          </div>
        </div>

        <!-- Loading -->
        <div v-if="loading && !vouchers.length" class="bg-white rounded-xl border border-slate-200 shadow-sm p-8 text-center text-slate-500">
          <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
          Loading vouchers...
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-white rounded-xl border border-slate-200 shadow-sm p-8 text-center text-red-500">
          {{ error }}
          <button @click="fetchVouchers" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
        </div>

        <!-- Vouchers Table -->
        <div v-else class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
                <tr>
                  <th class="text-left px-6 py-3 text-xs font-semibold text-slate-700 uppercase tracking-wider">Code</th>
                  <th class="text-left px-6 py-3 text-xs font-semibold text-slate-700 uppercase tracking-wider hidden md:table-cell">Package</th>
                  <th class="text-left px-6 py-3 text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="text-left px-6 py-3 text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Expires</th>
                  <th class="text-left px-6 py-3 text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Created</th>
                  <th class="text-right px-6 py-3 text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="voucher in vouchers" :key="voucher.id" class="hover:bg-blue-50/50 transition-colors cursor-pointer group" @click="openDetailOverlay(voucher)">
                  <td class="px-6 py-4 text-sm font-mono font-semibold text-blue-700">{{ voucher.code }}</td>
                  <td class="px-6 py-4 text-sm text-slate-900 hidden md:table-cell">{{ voucher.package?.name || '-' }}</td>
                  <td class="px-6 py-4">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize" :class="statusClass(voucher.status)">{{ voucher.status }}</span>
                  </td>
                  <td class="px-6 py-4 text-xs text-slate-500 hidden lg:table-cell">{{ voucher.expires_at ? formatDate(voucher.expires_at) : 'No expiry' }}</td>
                  <td class="px-6 py-4 text-xs text-slate-500 hidden lg:table-cell">{{ formatDate(voucher.created_at) }}</td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1 relative">
                      <button @click.stop="openDetailOverlay(voucher)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View Details">
                        <Eye class="w-4 h-4" />
                      </button>
                      <button v-if="voucher.status === 'unused'" @click.stop="revokeVoucher(voucher)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors" title="Revoke Voucher">
                        <Ban class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!vouchers.length">
                  <td colspan="6" class="px-6 py-8 text-center text-slate-400 text-sm">No vouchers found. Click "Create Voucher" to generate some.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div v-if="pagination.lastPage > 1" class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 md:px-6 py-3 border-t border-slate-200 bg-slate-50">
            <span class="text-xs text-slate-600">Showing {{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</span>
            <div class="flex items-center gap-1">
              <button @click="goToPage(pagination.currentPage - 1)" :disabled="pagination.currentPage <= 1" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">Prev</button>
              <span class="px-2 text-xs text-slate-600">Page {{ pagination.currentPage }} of {{ pagination.lastPage }}</span>
              <button @click="goToPage(pagination.currentPage + 1)" :disabled="pagination.currentPage >= pagination.lastPage" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">Next</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Create Voucher Overlay -->
    <SlideOverlay v-model="showCreateOverlay" title="Create Voucher" subtitle="Generate new hotspot vouchers" icon="Ticket" width="480px" @close="showCreateOverlay = false">
      <form @submit.prevent="generateVouchers" class="space-y-5">
        <!-- Package Selection -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Select Package *</label>
          <select v-model="formData.package_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="">Choose a package...</option>
            <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
          </select>
          <p v-if="selectedPackage" class="mt-1.5 text-xs text-gray-500">
            Price: KES {{ selectedPackage.price }} | Speed: {{ selectedPackage.download_speed || '-' }} | Validity: {{ selectedPackage.validity || '-' }}
          </p>
        </div>

        <!-- Quantity -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Number of Vouchers *</label>
          <input v-model.number="formData.quantity" type="number" min="1" max="100" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="1-100" />
          <p class="mt-1 text-xs text-gray-400">Maximum 100 vouchers per batch</p>
        </div>

        <!-- Prefix -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Voucher Prefix (Optional)</label>
          <input v-model="formData.prefix" type="text" maxlength="10" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g., WIFI, HOT" />
        </div>

        <!-- Expiry Date -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date (Optional)</label>
          <input v-model="formData.expires_at" type="date" :min="minDate" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>

        <!-- Notes -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
          <textarea v-model="formData.notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Any notes..."></textarea>
        </div>

        <!-- Summary -->
        <div v-if="formData.package_id && formData.quantity" class="bg-blue-50 border border-blue-200 rounded-lg p-3">
          <h4 class="text-xs font-semibold text-blue-900 mb-2">Summary</h4>
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div><span class="text-blue-600">Package:</span> <span class="font-medium text-blue-900">{{ selectedPackage?.name }}</span></div>
            <div><span class="text-blue-600">Quantity:</span> <span class="font-medium text-blue-900">{{ formData.quantity }}</span></div>
            <div><span class="text-blue-600">Total Value:</span> <span class="font-medium text-blue-900">KES {{ totalValue }}</span></div>
            <div><span class="text-blue-600">Prefix:</span> <span class="font-medium text-blue-900">{{ formData.prefix || 'None' }}</span></div>
          </div>
        </div>

        <!-- Error -->
        <div v-if="generateError" class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{{ generateError }}</div>
      </form>

      <template #footer>
        <div class="flex gap-3">
          <button
            type="button"
            @click="showCreateOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
          >
            Cancel
          </button>
          <button
            @click="generateVouchers"
            :disabled="generating || !formData.package_id || !formData.quantity"
            class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition-colors disabled:opacity-50"
          >
            <Ticket class="w-4 h-4" />
            {{ generating ? 'Generating...' : `Generate ${formData.quantity || 0} Voucher${formData.quantity !== 1 ? 's' : ''}` }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Voucher Detail Overlay -->
    <SlideOverlay v-model="showDetailOverlay" title="Voucher Details" subtitle="View voucher information" icon="Ticket" width="480px" @close="showDetailOverlay = false">
      <div v-if="selectedVoucher" class="space-y-3">
        <div class="flex items-center justify-center p-4 bg-blue-50 rounded-lg">
          <span class="font-mono text-xl font-bold text-blue-700">{{ selectedVoucher.code }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Status</span>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(selectedVoucher.status)">{{ selectedVoucher.status }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Package</span>
          <span class="text-sm font-semibold text-gray-900">{{ selectedVoucher.package?.name || '-' }}</span>
        </div>
        <div v-if="selectedVoucher.package?.price" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Price</span>
          <span class="text-sm font-semibold text-gray-900">KES {{ selectedVoucher.package.price }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Router</span>
          <span class="text-sm text-gray-900">{{ selectedVoucher.router?.name || 'Any' }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Expires</span>
          <span class="text-sm text-gray-900">{{ selectedVoucher.expires_at ? formatDate(selectedVoucher.expires_at) : 'No expiry' }}</span>
        </div>
        <div v-if="selectedVoucher.used_at" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Used At</span>
          <span class="text-sm text-gray-900">{{ formatDate(selectedVoucher.used_at) }}</span>
        </div>
        <div v-if="selectedVoucher.notes" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Notes</span>
          <span class="text-sm text-gray-900 text-right max-w-[60%]">{{ selectedVoucher.notes }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Created</span>
          <span class="text-sm text-gray-900">{{ formatDate(selectedVoucher.created_at) }}</span>
        </div>
        <div v-if="selectedVoucher.batch_id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Batch ID</span>
          <span class="text-xs font-mono text-gray-500">{{ selectedVoucher.batch_id }}</span>
        </div>
      </div>
      <template #footer>
        <div class="flex gap-3">
          <button
            type="button"
            @click="showDetailOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
          >
            Close
          </button>
          <button
            v-if="selectedVoucher?.status === 'unused'"
            @click="revokeVoucher(selectedVoucher)"
            class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors"
          >
            <Ban class="w-4 h-4" />
            Revoke
          </button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Plus, Eye, Ban, Ticket } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

// State
const loading = ref(false)
const error = ref(null)
const vouchers = ref([])
const packages = ref([])
const stats = ref({})
const generating = ref(false)
const generateError = ref(null)
const searchQuery = ref('')
const filterStatus = ref('')
const filterPackage = ref('')

// Pagination
const pagination = ref({ currentPage: 1, lastPage: 1, from: 0, to: 0, total: 0 })

// Overlays
const showCreateOverlay = ref(false)
const showDetailOverlay = ref(false)
const selectedVoucher = ref(null)

// Form
const formData = ref({
  package_id: '',
  quantity: 10,
  prefix: '',
  expires_at: '',
  notes: ''
})

// Computed
const selectedPackage = computed(() => packages.value.find(p => p.id === formData.value.package_id))
const totalValue = computed(() => {
  if (!selectedPackage.value || !formData.value.quantity) return 0
  return (selectedPackage.value.price || 0) * formData.value.quantity
})
const minDate = computed(() => new Date().toISOString().split('T')[0])

// Status badge classes
const statusClass = (status) => {
  const map = {
    unused: 'bg-green-100 text-green-700',
    used: 'bg-blue-100 text-blue-700',
    expired: 'bg-yellow-100 text-yellow-700',
    revoked: 'bg-red-100 text-red-700',
  }
  return map[status] || 'bg-gray-100 text-gray-700'
}

const formatDate = (d) => d ? new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''

// Debounce search
let searchTimeout = null
const debouncedFetch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchVouchers(), 400)
}

// API calls
const fetchPackages = async () => {
  try {
    const res = await axios.get('/packages')
    const data = res.data.data || res.data
    packages.value = Array.isArray(data) ? data : (data.data || [])
  } catch (err) {
    console.error('Failed to fetch packages:', err)
  }
}

const fetchStats = async () => {
  try {
    const res = await axios.get('/vouchers/stats')
    stats.value = res.data.data || {}
  } catch (err) {
    console.error('Failed to fetch voucher stats:', err)
  }
}

const fetchVouchers = async (page = 1) => {
  try {
    loading.value = true
    error.value = null
    const params = { page, per_page: 25 }
    if (searchQuery.value) params.search = searchQuery.value
    if (filterStatus.value) params.status = filterStatus.value
    if (filterPackage.value) params.package_id = filterPackage.value

    const res = await axios.get('/vouchers', { params })
    const data = res.data.data || res.data

    if (data.data) {
      vouchers.value = data.data
      pagination.value = {
        currentPage: data.current_page || 1,
        lastPage: data.last_page || 1,
        from: data.from || 0,
        to: data.to || 0,
        total: data.total || 0,
      }
    } else if (Array.isArray(data)) {
      vouchers.value = data
    }
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load vouchers'
  } finally {
    loading.value = false
  }
}

const goToPage = (page) => {
  if (page < 1 || page > pagination.value.lastPage) return
  fetchVouchers(page)
}

const openCreateOverlay = () => {
  formData.value = { package_id: '', quantity: 10, prefix: '', expires_at: '', notes: '' }
  generateError.value = null
  showCreateOverlay.value = true
}

const openDetailOverlay = (voucher) => {
  selectedVoucher.value = voucher
  showDetailOverlay.value = true
}

const generateVouchers = async () => {
  if (!formData.value.package_id || !formData.value.quantity) return
  generating.value = true
  generateError.value = null

  try {
    const payload = {
      package_id: formData.value.package_id,
      quantity: formData.value.quantity,
    }
    if (formData.value.prefix) payload.prefix = formData.value.prefix
    if (formData.value.expires_at) payload.expires_at = formData.value.expires_at
    if (formData.value.notes) payload.notes = formData.value.notes

    await axios.post('/vouchers/generate', payload)
    showCreateOverlay.value = false
    fetchVouchers()
    fetchStats()
  } catch (err) {
    generateError.value = err.response?.data?.message || 'Failed to generate vouchers'
  } finally {
    generating.value = false
  }
}

const revokeVoucher = async (voucher) => {
  if (!confirm(`Revoke voucher ${voucher.code}?`)) return
  try {
    await axios.post(`/vouchers/${voucher.id}/revoke`)
    fetchVouchers(pagination.value.currentPage)
    fetchStats()
    if (showDetailOverlay.value) showDetailOverlay.value = false
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to revoke voucher')
  }
}

// Lifecycle
onMounted(() => {
  fetchPackages()
  fetchVouchers()
  fetchStats()
})
</script>
