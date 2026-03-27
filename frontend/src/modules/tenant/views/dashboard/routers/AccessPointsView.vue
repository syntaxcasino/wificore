<template>
  <DataViewContainer
    title="Access Points"
    subtitle="Manage wireless access points and hotspots"
    color-theme="indigo"
    v-model:search-model="searchQuery"
    search-placeholder="Search access points..."
    :stats="[
      { color: 'bg-emerald-500', value: onlineCount },
      { color: 'bg-slate-500', value: offlineCount },
      { color: 'bg-blue-500', value: accessPoints.length },
      { color: 'bg-amber-500', value: totalActiveUsers }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchAccessPoints"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <WifiIcon class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary" size="sm" class="shrink-0">
        <Plus class="w-4 h-4 mr-1" /> Add Access Point
      </BaseButton>
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
      <AlertCircle class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchAccessPoints" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="ap in paginatedData"
          :key="ap.id"
          :title="ap.name"
          :subtitle="ap.location || 'No Location'"
          :meta-lines="[{ text: ap.ip_address || 'No IP' }, { text: `${ap.active_users || 0} active users`, class: 'text-slate-600' }]"
          :status="ap.status"
          :actions="getAPActions(ap)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Name</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">IP Address</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Router</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Active Users</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Vendor</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="ap in paginatedData" :key="ap.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 font-bold text-sm">
                      {{ ap.name.charAt(0).toUpperCase() }}
                    </div>
                    <div>
                      <p class="text-sm font-medium text-slate-900">{{ ap.name }}</p>
                      <p class="text-xs text-slate-500">{{ ap.location || 'No Location' }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <p class="text-sm text-slate-600 font-mono">{{ ap.ip_address || '—' }}</p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm text-slate-900">{{ getRouterName(ap.router_id) }}</p>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="ap.status" size="sm" />
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-medium text-slate-700">{{ ap.active_users || 0 }}</p>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <p class="text-sm text-slate-600 capitalize">{{ ap.vendor || '—' }}</p>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="syncStatus(ap)" :disabled="syncingIds.has(ap.id)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors disabled:opacity-50 inline-flex items-center gap-1">
                      <RefreshCw class="w-3 h-3" :class="{ 'animate-spin': syncingIds.has(ap.id) }" />
                      Sync
                    </button>
                    <button @click="openEditOverlay(ap)" class="px-2 py-1 text-xs font-medium text-indigo-700 bg-indigo-50 rounded hover:bg-indigo-100 transition-colors inline-flex items-center gap-1">
                      <Edit class="w-3 h-3" />
                      Edit
                    </button>
                    <button @click="deleteAP(ap)" class="px-2 py-1 text-xs font-medium text-red-700 bg-red-50 rounded hover:bg-red-100 transition-colors inline-flex items-center gap-1">
                      <Trash2 class="w-3 h-3" />
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="access points" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Access Points Found' : 'No Access Points'"
      :description="searchQuery ? 'No access points match your search criteria.' : 'Get started by adding your first access point.'"
      icon="wifi"
      color-theme="indigo"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      @clear="searchQuery = ''"
    >
      <template #action>
        <BaseButton @click="openCreateOverlay" variant="primary" size="sm">
          <Plus class="w-4 h-4 mr-1" /> Add Access Point
        </BaseButton>
      </template>
    </DataEmptyState>
  </DataViewContainer>

  <!-- Form SlideOverlay -->
  <SlideOverlay
    v-model="showFormOverlay"
    :title="isEditing ? 'Edit Access Point' : 'Add Access Point'"
    subtitle="Configure access point settings"
    icon="WifiIcon"
    width="480px"
    @close="closeFormOverlay"
  >
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Router</label>
        <BaseSelect v-model="formData.router_id" placeholder="Select a router">
          <option value="">Select a router</option>
          <option v-for="router in availableRouters" :key="router.id" :value="router.id">{{ router.name }} ({{ router.ip_address }})</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
        <BaseInput v-model="formData.name" placeholder="Access Point Name" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Vendor</label>
        <BaseSelect v-model="formData.vendor" placeholder="Select vendor">
          <option v-for="v in vendors" :key="v.value" :value="v.value">{{ v.label }}</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Model</label>
        <BaseInput v-model="formData.model" placeholder="Model (e.g., UniFi AP AC Pro)" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">IP Address</label>
        <BaseInput v-model="formData.ip_address" placeholder="192.168.1.100" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">MAC Address</label>
        <BaseInput v-model="formData.mac_address" placeholder="AA:BB:CC:DD:EE:FF" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Serial Number</label>
        <BaseInput v-model="formData.serial_number" placeholder="Required for Zero-Touch" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Location</label>
        <BaseInput v-model="formData.location" placeholder="Building A, Floor 2" />
      </div>
      <div v-if="formMessage.text" :class="formMessage.type === 'error' ? 'text-red-600' : 'text-green-600'" class="text-sm">
        {{ formMessage.text }}
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="closeFormOverlay"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Cancel
        </button>
        <button
          @click="submitForm"
          :disabled="formSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors"
          :class="isEditing ? 'bg-blue-600 hover:bg-blue-700' : 'bg-emerald-600 hover:bg-emerald-700'"
        >
          <span v-if="formSubmitting">{{ isEditing ? 'Saving...' : 'Adding...' }}</span>
          <span v-else>{{ isEditing ? 'Save Changes' : 'Add Access Point' }}</span>
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { WifiIcon, Plus, AlertCircle, RefreshCw, Edit, Trash2 } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

const accessPoints = ref([])
const loading = ref(false)
const error = ref(null)
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showFormOverlay = ref(false)
const isEditing = ref(false)
const formSubmitting = ref(false)
const formMessage = ref({ type: '', text: '' })
const syncingIds = ref(new Set())

const availableRouters = ref([
  { id: 1, name: 'Main Router', ip_address: '192.168.1.1' },
  { id: 2, name: 'Branch Router', ip_address: '192.168.2.1' },
])

const vendors = [
  { value: 'ubiquiti', label: 'Ubiquiti Networks' },
  { value: 'mikrotik', label: 'MikroTik' },
  { value: 'tp-link', label: 'TP-Link' },
  { value: 'cisco', label: 'Cisco' },
  { value: 'ruckus', label: 'Ruckus' },
  { value: 'aruba', label: 'Aruba' },
  { value: 'other', label: 'Other' },
]

const filters = ref({ status: '', router_id: '' })

const formData = ref({
  router_id: '',
  name: '',
  vendor: '',
  model: '',
  ip_address: '',
  mac_address: '',
  serial_number: '',
  location: ''
})

// Computed
const stats = computed(() => {
  const online = accessPoints.value.filter(ap => ap.status === 'online').length
  const offline = accessPoints.value.filter(ap => ap.status === 'offline').length
  const totalUsers = accessPoints.value.reduce((sum, ap) => sum + (ap.active_users || 0), 0)
  return { online, offline, total: accessPoints.value.length, totalUsers }
})

const onlineCount = computed(() => stats.value.online)
const offlineCount = computed(() => stats.value.offline)
const totalActiveUsers = computed(() => stats.value.totalUsers)

const filteredData = computed(() => {
  let data = accessPoints.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(ap =>
      ap.name.toLowerCase().includes(query) ||
      (ap.ip_address && ap.ip_address.includes(query)) ||
      (ap.mac_address && ap.mac_address.toLowerCase().includes(query)) ||
      (ap.serial_number && ap.serial_number.toLowerCase().includes(query))
    )
  }
  if (filters.value.status) {
    data = data.filter(ap => ap.status === filters.value.status)
  }
  if (filters.value.router_id) {
    data = data.filter(ap => ap.router_id === filters.value.router_id)
  }
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.router_id || searchQuery.value)

// Helpers
const getRouterName = (routerId) => {
  const router = availableRouters.value.find(r => r.id === routerId)
  return router ? router.name : 'Unknown'
}

const getAPActions = (ap) => [
  { label: 'Sync', onClick: () => syncStatus(ap), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100', disabled: syncingIds.value.has(ap.id) },
  { label: 'Edit', onClick: () => openEditOverlay(ap), class: 'text-indigo-700 bg-indigo-50 hover:bg-indigo-100' },
  { label: 'Delete', onClick: () => deleteAP(ap), class: 'text-red-700 bg-red-50 hover:bg-red-100' }
]

// Actions
const fetchAccessPoints = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/network/access-points')
    const data = response.data?.access_points?.data || response.data?.access_points || response.data?.data || []
    accessPoints.value = data.map(ap => ({
      id: ap.id,
      router_id: ap.router_id || null,
      name: ap.name || 'Unnamed',
      vendor: ap.vendor || 'other',
      model: ap.model || '',
      ip_address: ap.ip_address || null,
      mac_address: ap.mac_address || null,
      serial_number: ap.serial_number || null,
      location: ap.location || null,
      status: ap.status || 'unknown',
      active_users: ap.active_users || 0
    }))
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load access points'
    console.error('fetchAccessPoints error:', err)
  } finally {
    loading.value = false
  }
}

const syncStatus = async (ap) => {
  syncingIds.value.add(ap.id)
  try {
    await axios.post(`/network/access-points/${ap.id}/sync`)
    await fetchAccessPoints()
  } catch (err) {
    console.error('Sync error:', err)
    alert(err.response?.data?.message || 'Failed to sync access point')
  } finally {
    syncingIds.value.delete(ap.id)
  }
}

const openCreateOverlay = () => {
  formData.value = { router_id: '', name: '', vendor: '', model: '', ip_address: '', mac_address: '', serial_number: '', location: '' }
  isEditing.value = false
  formMessage.value = { type: '', text: '' }
  showFormOverlay.value = true
}

const openEditOverlay = (ap) => {
  formData.value = { ...ap }
  isEditing.value = true
  formMessage.value = { type: '', text: '' }
  showFormOverlay.value = true
}

const closeFormOverlay = () => {
  showFormOverlay.value = false
  formMessage.value = { type: '', text: '' }
}

const submitForm = async () => {
  if (!formData.value.name || !formData.value.router_id) {
    formMessage.value = { type: 'error', text: 'Name and Router are required.' }
    return
  }
  formSubmitting.value = true
  try {
    if (isEditing.value) {
      await axios.put(`/network/access-points/${formData.value.id}`, formData.value)
    } else {
      await axios.post('/network/access-points', formData.value)
    }
    closeFormOverlay()
    await fetchAccessPoints()
  } catch (err) {
    formMessage.value = { type: 'error', text: err.response?.data?.message || 'Failed to save access point' }
  } finally {
    formSubmitting.value = false
  }
}

const deleteAP = async (ap) => {
  const confirmed = await confirmStore.confirm(`Delete access point ${ap.name}?`)
  if (!confirmed) return
  try {
    await axios.delete(`/network/access-points/${ap.id}`)
    await fetchAccessPoints()
  } catch (err) {
    console.error('Delete error:', err)
    alert(err.response?.data?.message || 'Failed to delete access point')
  }
}

onMounted(() => { fetchAccessPoints() })
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
