<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Billing Overrides</h1>
        <p class="text-xs sm:text-sm text-gray-500 mt-1">Tenants with custom billing rates overriding the default configuration</p>
      </div>
      <button @click="fetchOverrides" :disabled="loading" class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 text-gray-700 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 disabled:opacity-50 transition-colors self-start sm:self-auto">
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading overrides...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchOverrides" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
        <div class="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900">Override List</h2>
          <span v-if="overrides.length" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{{ overrides.length }} override{{ overrides.length !== 1 ? 's' : '' }}</span>
        </div>
        <table class="w-full min-w-[480px]">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">Tenant</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">Slug</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">Override Summary</th>
              <th class="text-right px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in overrides" :key="item.id" class="hover:bg-gray-50 transition-colors cursor-pointer" @click="openOverrideDetail(item)">
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-sm font-medium text-gray-900">{{ item.name || item.tenant?.name || 'Unknown' }}</td>
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-sm text-gray-500 font-mono hidden sm:table-cell">{{ item.slug || item.tenant?.slug || '' }}</td>
              <td class="px-3 sm:px-6 py-3 sm:py-4 text-sm text-gray-600">
                <div class="flex flex-wrap gap-1.5">
                  <span v-for="(val, prop) in getOverrideData(item)" :key="prop" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">{{ prop }}: {{ val }}</span>
                  <span v-if="!Object.keys(getOverrideData(item)).length" class="text-gray-400 text-xs">No details</span>
                </div>
              </td>
              <td class="px-6 py-4 text-right">
                <div class="flex items-center justify-end gap-1">
                  <button @click.stop="openOverrideDetail(item)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View Details">
                    <Eye class="w-4 h-4" />
                  </button>
                  <button @click.stop="removeOverride(item)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors" title="Remove Override">
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="overrides.length === 0">
              <td colspan="4" class="px-6 py-8 text-center text-gray-400 text-sm">No billing overrides configured</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Override Detail Overlay -->
    <SlideOverlay v-model="showDetailOverlay" :title="selectedItem?.name || selectedItem?.tenant?.name || 'Override Details'" subtitle="Custom billing rate configuration" icon="FileText" width="40%" @close="showDetailOverlay = false">
      <div v-if="selectedItem" class="space-y-4">
        <div class="space-y-3">
          <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Tenant</span>
            <span class="text-sm font-semibold text-gray-900">{{ selectedItem.name || selectedItem.tenant?.name || 'Unknown' }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">Slug</span>
            <span class="text-sm font-mono text-gray-900">{{ selectedItem.slug || selectedItem.tenant?.slug || '' }}</span>
          </div>
        </div>
        <div>
          <h3 class="text-sm font-semibold text-gray-900 mb-3">Override Values</h3>
          <div class="space-y-2">
            <div v-for="(val, prop) in getOverrideData(selectedItem)" :key="prop" class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
              <span class="text-sm font-medium text-gray-700 capitalize">{{ String(prop).replace(/_/g, ' ') }}</span>
              <span class="text-sm font-semibold text-blue-700">{{ val }}</span>
            </div>
            <div v-if="!Object.keys(getOverrideData(selectedItem)).length" class="p-3 bg-gray-50 rounded-lg text-sm text-gray-400 text-center">No override values found</div>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showDetailOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
          <button @click="removeOverride(selectedItem)" class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">Remove Override</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Eye, Trash2 } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const overrides = ref([])
const loading = ref(true)
const error = ref(null)
const showDetailOverlay = ref(false)
const selectedItem = ref(null)

const getOverrideData = (item) => {
  const data = item.billing_override || item.override || {}
  if (typeof data === 'object' && data !== null) return data
  return {}
}

const openOverrideDetail = (item) => {
  selectedItem.value = item
  showDetailOverlay.value = true
}

const fetchOverrides = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/landlord/overrides')
    overrides.value = res.data.data || res.data.tenants || []
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load overrides'
  } finally {
    loading.value = false
  }
}

const removeOverride = async (item) => {
  const tenantId = item.id || item.tenant_id
  if (!confirm('Remove billing override for this tenant? They will revert to default rates.')) return
  try {
    await axios.delete(`/system/landlord/tenants/${tenantId}/override`)
    showDetailOverlay.value = false
    await fetchOverrides()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to remove override')
  }
}

onMounted(() => fetchOverrides())
</script>
