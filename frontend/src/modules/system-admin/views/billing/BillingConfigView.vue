<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-slate-100">Billing Configuration</h1>
        <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-1">Manage SaaS billing rates, plans, and default payment settings</p>
      </div>
      <button @click="fetchConfig" :disabled="loading" class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 disabled:opacity-50 transition-colors self-start sm:self-auto">
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div v-if="loading" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading configuration...
    </div>
    <div v-else-if="error" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchConfig" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Billing Rates Table -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden overflow-x-auto">
        <div class="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-slate-100">Current Billing Rates</h2>
        </div>
        <table class="w-full min-w-[400px]">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Rate</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Value</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Description</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">PPPoE Rate</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ config.pppoe_rate || 'N/A' }}</td>
              <td class="px-6 py-4 text-sm text-gray-500 dark:text-slate-400">Per user/month</td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Hotspot Revenue %</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ config.hotspot_revenue_pct || 'N/A' }}%</td>
              <td class="px-6 py-4 text-sm text-gray-500 dark:text-slate-400">Of hotspot revenue</td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Minimum Subscription</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600">{{ config.minimum_subscription || 'N/A' }}</td>
              <td class="px-6 py-4 text-sm text-gray-500 dark:text-slate-400">KES/month</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Subscription Plans Table -->
      <div v-if="config.plans && Object.keys(config.plans).length" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Subscription Plans</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Plan</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Details</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr v-for="(plan, key) in config.plans" :key="key" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 capitalize">{{ key }}</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400">
                <div v-if="typeof plan === 'object'" class="flex flex-wrap gap-1.5">
                  <span v-for="(val, prop) in plan" :key="prop" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">{{ prop }}: {{ val }}</span>
                </div>
                <span v-else>{{ plan }}</span>
              </td>
              <td class="px-6 py-4 text-right">
                <button @click="openPlanDetail(key, plan)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="View">
                  <Eye class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paybill Settings Table -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Default Paybill Settings</h2>
          <button @click="showPaybillOverlay = true" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
            <Pencil class="w-3.5 h-3.5" />
            Edit Paybill
          </button>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Setting</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Value</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Paybill Number</td><td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ config.default_paybill || 'Not configured' }}</td></tr>
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Paybill Name</td><td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400">{{ config.default_paybill_name || 'Not configured' }}</td></tr>
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Environment</td><td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="paybillForm.environment === 'production' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'">{{ paybillForm.environment }}</span></td></tr>
            <tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Credentials</td><td class="px-6 py-4 text-sm text-gray-500 dark:text-slate-400">{{ config.default_paybill ? 'Configured (hidden)' : 'Not set' }}</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Edit Paybill Overlay -->
    <SlideOverlay v-model="showPaybillOverlay" title="Edit Paybill Settings" subtitle="Update M-Pesa paybill credentials" icon="CreditCard" width="50%" @close="showPaybillOverlay = false">
      <form @submit.prevent="updatePaybill" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Paybill Number</label>
          <input v-model="paybillForm.paybill" type="text" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Paybill Name</label>
          <input v-model="paybillForm.paybill_name" type="text" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Consumer Key</label>
          <input v-model="paybillForm.consumer_key" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Consumer Secret</label>
          <input v-model="paybillForm.consumer_secret" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Passkey</label>
          <input v-model="paybillForm.passkey" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Environment</label>
          <select v-model="paybillForm.environment" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg text-sm text-gray-900 dark:text-slate-100 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-blue-500">
            <option value="sandbox">Sandbox</option>
            <option value="production">Production</option>
          </select>
        </div>
        <div v-if="saveMessage" class="text-sm" :class="saveError ? 'text-red-600' : 'text-green-600'">{{ saveMessage }}</div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-3">
          <button type="button" @click="showPaybillOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Cancel</button>
          <button @click="updatePaybill" :disabled="saving" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">{{ saving ? 'Saving...' : 'Update Paybill' }}</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Plan Detail Overlay -->
    <SlideOverlay v-model="showPlanOverlay" :title="selectedPlanKey" subtitle="Plan configuration details" icon="FileText" width="50%" @close="showPlanOverlay = false">
      <div v-if="selectedPlan && typeof selectedPlan === 'object'" class="space-y-3">
        <div v-for="(val, prop) in selectedPlan" :key="prop" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-700 dark:text-slate-300 capitalize">{{ String(prop).replace(/_/g, ' ') }}</span>
          <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ val }}</span>
        </div>
      </div>
      <div v-else class="text-sm text-gray-500 dark:text-slate-400">{{ selectedPlan }}</div>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Pencil, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const config = ref({})
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const saveMessage = ref('')
const saveError = ref(false)
const showPaybillOverlay = ref(false)
const showPlanOverlay = ref(false)
const selectedPlanKey = ref('')
const selectedPlan = ref(null)

const paybillForm = reactive({
  paybill: '',
  paybill_name: '',
  consumer_key: '',
  consumer_secret: '',
  passkey: '',
  environment: 'sandbox'
})

const openPlanDetail = (key, plan) => {
  selectedPlanKey.value = key.charAt(0).toUpperCase() + key.slice(1) + ' Plan'
  selectedPlan.value = plan
  showPlanOverlay.value = true
}

const fetchConfig = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/landlord/configuration')
    config.value = res.data.configuration || {}
    paybillForm.paybill = config.value.default_paybill || ''
    paybillForm.paybill_name = config.value.default_paybill_name || ''
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load configuration'
  } finally {
    loading.value = false
  }
}

const updatePaybill = async () => {
  try {
    saving.value = true
    saveMessage.value = ''
    saveError.value = false
    const payload = { ...paybillForm }
    if (!payload.consumer_key) delete payload.consumer_key
    if (!payload.consumer_secret) delete payload.consumer_secret
    if (!payload.passkey) delete payload.passkey
    await axios.put('/system/landlord/paybill', payload)
    saveMessage.value = 'Paybill updated successfully'
    paybillForm.consumer_key = ''
    paybillForm.consumer_secret = ''
    paybillForm.passkey = ''
    await fetchConfig()
  } catch (err) {
    saveError.value = true
    saveMessage.value = err.response?.data?.message || 'Failed to update paybill'
  } finally {
    saving.value = false
  }
}

onMounted(() => fetchConfig())
</script>
