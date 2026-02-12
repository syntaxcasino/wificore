<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Tenant Management</h1>
        <p class="text-xs sm:text-sm text-gray-500 mt-1">Manage all tenants on the platform</p>
      </div>
      <button
        @click="showCreateModal = true"
        class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-blue-600 text-white text-xs sm:text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors self-start sm:self-auto"
      >
        <Plus class="w-4 h-4" />
        Add Tenant
      </button>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-sm text-gray-500">Total Tenants</div>
        <div class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-sm text-gray-500">Active</div>
        <div class="text-2xl font-bold text-green-600 mt-1">{{ stats.active }}</div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-sm text-gray-500">Suspended</div>
        <div class="text-2xl font-bold text-red-600 mt-1">{{ stats.suspended }}</div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-sm text-gray-500">On Trial</div>
        <div class="text-2xl font-bold text-yellow-600 mt-1">{{ stats.trial }}</div>
      </div>
    </div>

    <!-- Search & Filter -->
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1 relative">
          <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search tenants by name, slug, or email..."
            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            @input="debouncedFetch"
          />
        </div>
        <select
          v-model="statusFilter"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
          @change="fetchTenants"
        >
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
      <div v-if="loading" class="p-8 text-center text-gray-500">
        <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
        Loading tenants...
      </div>
      <div v-else-if="error" class="p-8 text-center text-red-500">
        {{ error }}
        <button @click="fetchTenants" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
      </div>
      <table v-else class="w-full min-w-[580px]">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 uppercase">Tenant</th>
            <th class="text-left px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">Domain</th>
            <th class="text-center px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 uppercase">Users</th>
            <th class="text-center px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 uppercase">Routers</th>
            <th class="text-center px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 uppercase">Status</th>
            <th class="text-right px-3 sm:px-4 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="tenant in tenants" :key="tenant.id" class="hover:bg-gray-50 transition-colors">
            <td class="px-3 sm:px-4 py-3">
              <div class="font-medium text-gray-900 text-sm">{{ tenant.name }}</div>
              <div class="text-xs text-gray-500">{{ tenant.slug }}</div>
            </td>
            <td class="px-3 sm:px-4 py-3 text-sm text-gray-600 hidden sm:table-cell">{{ tenant.domain || '-' }}</td>
            <td class="px-3 sm:px-4 py-3 text-sm text-center text-gray-600">{{ tenant.users_count ?? 0 }}</td>
            <td class="px-3 sm:px-4 py-3 text-sm text-center text-gray-600">{{ tenant.routers_count ?? 0 }}</td>
            <td class="px-3 sm:px-4 py-3 text-center">
              <span
                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                :class="tenant.suspended_at ? 'bg-red-100 text-red-700' : tenant.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'"
              >
                {{ tenant.suspended_at ? 'Suspended' : tenant.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-3 sm:px-4 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <button
                  v-if="!tenant.suspended_at && tenant.is_active"
                  @click="suspendTenant(tenant)"
                  class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors"
                  title="Suspend"
                >
                  <Ban class="w-4 h-4" />
                </button>
                <button
                  v-if="tenant.suspended_at"
                  @click="activateTenant(tenant)"
                  class="p-1.5 text-green-500 hover:bg-green-50 rounded-md transition-colors"
                  title="Activate"
                >
                  <CheckCircle class="w-4 h-4" />
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="tenants.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">No tenants found</td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="pagination.lastPage > 1" class="flex flex-col sm:flex-row items-center justify-between gap-2 px-3 sm:px-4 py-3 border-t border-gray-200 bg-gray-50">
        <div class="text-xs text-gray-500">
          Showing {{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}
        </div>
        <div class="flex flex-wrap gap-1">
          <button
            v-for="page in pagination.lastPage"
            :key="page"
            @click="currentPage = page; fetchTenants()"
            class="px-3 py-1 text-xs rounded-md transition-colors"
            :class="page === currentPage ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50'"
          >
            {{ page }}
          </button>
        </div>
      </div>
    </div>

    <!-- Create Tenant Overlay -->
    <SlideOverlay
      v-model="showCreateModal"
      title="Add New Tenant"
      subtitle="Create a new tenant on the platform"
      icon="Building2"
      width="40%"
      @close="showCreateModal = false"
    >
      <form @submit.prevent="createTenant" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
          <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Domain</label>
          <input v-model="form.domain" type="text" placeholder="e.g. client.wificore.com" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
          <input v-model="form.phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
        </div>
        <div v-if="formError" class="text-sm text-red-600">{{ formError }}</div>
      </form>

      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
          <button @click="createTenant" :disabled="creating" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
            {{ creating ? 'Creating...' : 'Create Tenant' }}
          </button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ban, CheckCircle } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const tenants = ref([])
const loading = ref(true)
const error = ref(null)
const searchQuery = ref('')
const statusFilter = ref('')
const currentPage = ref(1)
const showCreateModal = ref(false)
const creating = ref(false)
const formError = ref(null)

const form = reactive({
  name: '',
  domain: '',
  email: '',
  phone: ''
})

const stats = computed(() => {
  const all = tenants.value
  return {
    total: pagination.value.total || all.length,
    active: all.filter(t => t.is_active && !t.suspended_at).length,
    suspended: all.filter(t => t.suspended_at).length,
    trial: all.filter(t => t.trial_ends_at && !t.suspended_at).length
  }
})

const pagination = ref({ total: 0, from: 0, to: 0, lastPage: 1 })

let debounceTimer = null
const debouncedFetch = () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => { currentPage.value = 1; fetchTenants() }, 300)
}

const fetchTenants = async () => {
  try {
    loading.value = true
    error.value = null
    const params = { page: currentPage.value }
    if (searchQuery.value) params.search = searchQuery.value
    if (statusFilter.value === 'active') params.is_active = true
    if (statusFilter.value === 'inactive') params.is_active = false
    if (statusFilter.value === 'suspended') params.suspended = true

    const res = await axios.get('/system/tenants', { params })
    const data = res.data.tenants
    tenants.value = data.data || []
    pagination.value = {
      total: data.total || 0,
      from: data.from || 0,
      to: data.to || 0,
      lastPage: data.last_page || 1
    }
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load tenants'
  } finally {
    loading.value = false
  }
}

const createTenant = async () => {
  try {
    creating.value = true
    formError.value = null
    await axios.post('/system/tenants', form)
    showCreateModal.value = false
    Object.assign(form, { name: '', domain: '', email: '', phone: '' })
    await fetchTenants()
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to create tenant'
  } finally {
    creating.value = false
  }
}

const suspendTenant = async (tenant) => {
  if (!confirm(`Suspend ${tenant.name}?`)) return
  try {
    await axios.post(`/system/tenants/${tenant.id}/suspend`)
    await fetchTenants()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to suspend tenant')
  }
}

const activateTenant = async (tenant) => {
  try {
    await axios.post(`/system/tenants/${tenant.id}/activate`)
    await fetchTenants()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to activate tenant')
  }
}

onMounted(() => fetchTenants())
</script>
