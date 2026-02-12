<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Billing Metrics</h1>
        <p class="text-xs sm:text-sm text-gray-500 mt-1">Aggregate billing and subscription metrics across all tenants</p>
      </div>
      <button @click="fetchAll" :disabled="loading" class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 text-gray-700 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 disabled:opacity-50 transition-colors self-start sm:self-auto">
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading metrics...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchAll" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Aggregate Metrics Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900">Aggregate Metrics</h2>
        </div>
        <table class="w-full min-w-[360px]">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Metric</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Value</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(value, key) in metrics" :key="key" class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 capitalize">{{ formatLabel(key) }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right">{{ formatValue(value) }}</td>
            </tr>
            <tr v-if="!Object.keys(metrics).length">
              <td colspan="2" class="px-6 py-8 text-center text-gray-400 text-sm">No metrics available</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tenant Counts Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900">Tenant Counts</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Category</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Count</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(value, key) in tenantCounts" :key="key" class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 capitalize">{{ formatLabel(key) }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">{{ value }}</td>
            </tr>
            <tr v-if="!Object.keys(tenantCounts).length">
              <td colspan="2" class="px-6 py-8 text-center text-gray-400 text-sm">No tenant counts available</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Expiring Soon Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">Expiring Soon</h2>
          <span v-if="expiring.length" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700">{{ expiring.length }}</span>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Tenant</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Slug</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Expires</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="tenant in expiring" :key="tenant.id" class="hover:bg-gray-50 transition-colors cursor-pointer" @click="openTenantDetail(tenant, 'expiring')">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ tenant.name }}</td>
              <td class="px-6 py-4 text-sm text-gray-500 font-mono">{{ tenant.slug }}</td>
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">
                  {{ tenant.subscription_ends_at ? new Date(tenant.subscription_ends_at).toLocaleDateString() : 'N/A' }}
                </span>
              </td>
              <td class="px-6 py-4 text-right">
                <button @click.stop="openTenantDetail(tenant, 'expiring')" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View Details">
                  <Eye class="w-4 h-4" />
                </button>
              </td>
            </tr>
            <tr v-if="expiring.length === 0">
              <td colspan="4" class="px-6 py-8 text-center text-gray-400 text-sm">No tenants expiring soon</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Suspended Tenants Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">Suspended Tenants</h2>
          <span v-if="suspended.length" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">{{ suspended.length }}</span>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Tenant</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Slug</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Suspended</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="tenant in suspended" :key="tenant.id" class="hover:bg-gray-50 transition-colors cursor-pointer" @click="openTenantDetail(tenant, 'suspended')">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ tenant.name }}</td>
              <td class="px-6 py-4 text-sm text-gray-500 font-mono">{{ tenant.slug }}</td>
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                  {{ tenant.suspended_at ? new Date(tenant.suspended_at).toLocaleDateString() : 'N/A' }}
                </span>
              </td>
              <td class="px-6 py-4 text-right">
                <button @click.stop="openTenantDetail(tenant, 'suspended')" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View Details">
                  <Eye class="w-4 h-4" />
                </button>
              </td>
            </tr>
            <tr v-if="suspended.length === 0">
              <td colspan="4" class="px-6 py-8 text-center text-gray-400 text-sm">No suspended tenants</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Tenant Detail Overlay -->
    <SlideOverlay v-model="showTenantOverlay" :title="selectedTenant?.name || 'Tenant Details'" :subtitle="selectedTenantType === 'suspended' ? 'Suspended tenant details' : 'Expiring tenant details'" icon="Building2" width="40%" @close="showTenantOverlay = false">
      <div v-if="selectedTenant" class="space-y-4">
        <div class="space-y-3">
          <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Name</span>
            <span class="text-sm font-semibold text-gray-900">{{ selectedTenant.name }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Slug</span>
            <span class="text-sm font-mono text-gray-900">{{ selectedTenant.slug }}</span>
          </div>
          <div v-if="selectedTenant.email" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Email</span>
            <span class="text-sm text-gray-900">{{ selectedTenant.email }}</span>
          </div>
          <div v-if="selectedTenant.phone" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Phone</span>
            <span class="text-sm text-gray-900">{{ selectedTenant.phone }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Status</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="selectedTenantType === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'">
              {{ selectedTenantType === 'suspended' ? 'Suspended' : 'Expiring' }}
            </span>
          </div>
          <div v-if="selectedTenant.subscription_ends_at" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Subscription Ends</span>
            <span class="text-sm text-gray-900">{{ new Date(selectedTenant.subscription_ends_at).toLocaleDateString() }}</span>
          </div>
          <div v-if="selectedTenant.suspended_at" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Suspended At</span>
            <span class="text-sm text-gray-900">{{ new Date(selectedTenant.suspended_at).toLocaleDateString() }}</span>
          </div>
          <div v-if="selectedTenant.created_at" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Created</span>
            <span class="text-sm text-gray-900">{{ new Date(selectedTenant.created_at).toLocaleDateString() }}</span>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showTenantOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
          <button v-if="selectedTenantType === 'suspended'" @click="reactivate(selectedTenant)" class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-colors">Reactivate</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const metrics = ref({})
const tenantCounts = ref({})
const expiring = ref([])
const suspended = ref([])
const loading = ref(true)
const error = ref(null)
const showTenantOverlay = ref(false)
const selectedTenant = ref(null)
const selectedTenantType = ref('')

const formatLabel = (key) => key.replace(/_/g, ' ')
const formatValue = (val) => {
  if (typeof val === 'number') return val.toLocaleString()
  return val
}

const openTenantDetail = (tenant, type) => {
  selectedTenant.value = tenant
  selectedTenantType.value = type
  showTenantOverlay.value = true
}

const fetchAll = async () => {
  try {
    loading.value = true
    error.value = null
    const [metricsRes, countsRes, expiringRes, suspendedRes] = await Promise.all([
      axios.get('/system/landlord/metrics'),
      axios.get('/system/landlord/tenant-counts'),
      axios.get('/system/landlord/expiring'),
      axios.get('/system/landlord/suspended'),
    ])
    metrics.value = metricsRes.data.data || metricsRes.data.metrics || {}
    tenantCounts.value = countsRes.data.data || countsRes.data.counts || {}
    expiring.value = expiringRes.data.data || expiringRes.data.tenants || []
    suspended.value = suspendedRes.data.data || suspendedRes.data.tenants || []
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load billing metrics'
  } finally {
    loading.value = false
  }
}

const reactivate = async (tenant) => {
  try {
    await axios.post(`/system/landlord/tenants/${tenant.id}/reactivate`)
    showTenantOverlay.value = false
    await fetchAll()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to reactivate tenant')
  }
}

onMounted(() => fetchAll())
</script>
