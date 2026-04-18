<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-slate-100">Tenant IP Pools</h1>
        <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-1">Manage IP address pool allocations across tenants</p>
      </div>
      <button
        @click="showCreateModal = true"
        class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-blue-600 text-white text-xs sm:text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors self-start sm:self-auto"
      >
        <Plus class="w-4 h-4" />
        Add Pool
      </button>
    </div>

    <!-- Stats -->
    <div v-if="poolStats" class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
      <div v-for="(value, key) in poolStats" :key="key" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
        <div class="text-sm text-gray-500 dark:text-slate-400 capitalize">{{ key.replace(/_/g, ' ') }}</div>
        <div class="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-1">{{ value }}</div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden overflow-x-auto">
      <div v-if="loading" class="p-8 text-center text-gray-500">
        <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
        Loading IP pools...
      </div>
      <div v-else-if="error" class="p-8 text-center text-red-500">
        {{ error }}
        <button @click="fetchPools" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
      </div>
      <table v-else class="w-full min-w-[520px]">
        <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
          <tr>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Pool Name</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Network</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Tenant</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Used / Total</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
          <tr v-for="pool in pools" :key="pool.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-slate-100">{{ pool.name }}</td>
            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ pool.network || pool.subnet }}</td>
            <td class="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">{{ pool.tenant?.name || '-' }}</td>
            <td class="px-4 py-3 text-sm text-center text-gray-600">{{ pool.used_count ?? 0 }} / {{ pool.total_count ?? pool.size ?? 0 }}</td>
            <td class="px-4 py-3 text-right">
              <button
                @click="deletePool(pool)"
                class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors"
                title="Delete"
              >
                <Trash2 class="w-4 h-4" />
              </button>
            </td>
          </tr>
          <tr v-if="pools.length === 0">
            <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No IP pools configured</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create IP Pool Overlay -->
    <SlideOverlay
      v-model="showCreateModal"
      title="Add IP Pool"
      subtitle="Allocate a new IP address pool for tenants"
      icon="Network"
      width="50%"
      @close="showCreateModal = false"
    >
      <form @submit.prevent="createPool" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Pool Name *</label>
          <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Network (CIDR) *</label>
          <input v-model="form.network" type="text" required placeholder="e.g. 10.0.0.0/24" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm font-mono text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div v-if="formError" class="text-sm text-red-600">{{ formError }}</div>
      </form>

      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Cancel</button>
          <button @click="createPool" :disabled="creating" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
            {{ creating ? 'Creating...' : 'Create Pool' }}
          </button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { Plus, Trash2 } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useConfirmStore } from '@/stores/confirm'
import { useToast } from '@/modules/common/composables/useToast.js'

const confirmStore = useConfirmStore()
const { error: showError } = useToast()

const pools = ref([])
const poolStats = ref(null)
const loading = ref(true)
const error = ref(null)
const showCreateModal = ref(false)
const creating = ref(false)
const formError = ref(null)
const form = reactive({ name: '', network: '' })

const fetchPools = async () => {
  try {
    loading.value = true
    error.value = null
    const [poolsRes, statsRes] = await Promise.all([
      axios.get('/system/tenant/ip-pools'),
      axios.get('/system/tenant/ip-pools/stats').catch(() => ({ data: {} }))
    ])
    pools.value = poolsRes.data.data || poolsRes.data.pools || []
    poolStats.value = statsRes.data.data || statsRes.data.stats || null
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load IP pools'
  } finally {
    loading.value = false
  }
}

const createPool = async () => {
  try {
    creating.value = true
    formError.value = null
    await axios.post('/system/tenant/ip-pools', form)
    showCreateModal.value = false
    Object.assign(form, { name: '', network: '' })
    await fetchPools()
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to create pool'
  } finally {
    creating.value = false
  }
}

const deletePool = async (pool) => {
  const confirmed = await confirmStore.open({ title: 'Delete IP Pool', message: `Delete IP pool "${pool.name}"?`, confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger' })
  if (!confirmed) return
  try {
    await axios.delete(`/system/tenant/ip-pools/${pool.id}`)
    await fetchPools()
  } catch (err) {
    showError(err.response?.data?.message || 'Failed to delete pool')
  }
}

onMounted(() => fetchPools())
</script>
