<template>
  <PageContainer>
    <PageHeader title="Payment Methods" subtitle="Configure available payment methods" icon="CreditCard" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshMethods" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="openAddModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Method
        </BaseButton>
      </template>
    </PageHeader>

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Methods</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <CreditCard class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Active</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.active }}</div>
            </div>
            <CheckCircle class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Most Used</div>
              <div class="text-xl font-bold text-purple-900">M-Pesa</div>
            </div>
            <Smartphone class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">This Month</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.thisMonth }}</div>
            </div>
            <TrendingUp class="w-6 h-6 text-amber-600" />
          </div>
        </div>
      </div>
    </div>

    <PageContent>
      <div v-if="loading">
        <BaseLoading type="grid" :items="6" />
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="method in methods" :key="method.id" class="bg-white rounded-xl border-2 border-slate-200 hover:border-blue-400 hover:shadow-lg transition-all duration-200 overflow-hidden">
          <div class="p-6 bg-gradient-to-br" :class="method.gradient">
            <div class="flex items-start justify-between mb-4">
              <div class="p-3 bg-white/90 rounded-lg">
                <component :is="method.icon" class="w-6 h-6" :class="method.iconColor" />
              </div>
              <BaseBadge :variant="method.is_active ? 'success' : 'secondary'" size="sm">
                {{ method.is_active ? 'Active' : 'Inactive' }}
              </BaseBadge>
            </div>
            
            <h3 class="text-xl font-bold text-white mb-1">{{ method.name }}</h3>
            <p class="text-white/80 text-sm">{{ method.description }}</p>
          </div>

          <div class="p-6 space-y-4">
            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-600">Transactions</span>
              <span class="font-semibold text-slate-900">{{ method.transactions }}</span>
            </div>

            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-600">Total Amount</span>
              <span class="font-semibold text-slate-900">KES {{ formatMoney(method.total_amount) }}</span>
            </div>

            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-600">Success Rate</span>
              <span class="font-semibold text-green-600">{{ method.success_rate }}%</span>
            </div>

            <div class="pt-4 border-t border-slate-200 flex items-center gap-2">
              <BaseButton @click="editMethod(method)" variant="ghost" size="sm" class="flex-1">
                <Edit2 class="w-3 h-3 mr-1" />
                Edit
              </BaseButton>
              <BaseButton @click="toggleStatus(method)" :variant="method.is_active ? 'warning' : 'success'" size="sm" class="flex-1">
                {{ method.is_active ? 'Disable' : 'Enable' }}
              </BaseButton>
            </div>
          </div>
        </div>
      </div>
    </PageContent>

    <!-- Edit Method Overlay -->
    <SlideOverlay v-model="showEditOverlay" title="Edit Payment Method" :subtitle="selectedMethod?.name" icon="CreditCard" width="md">
      <div v-if="selectedMethod" class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input v-model="editForm.name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <input v-model="editForm.description" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div class="flex items-center gap-2">
          <input v-model="editForm.is_active" type="checkbox" id="method-active" class="rounded border-slate-300" />
          <label for="method-active" class="text-sm text-slate-700">Active</label>
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton @click="saveMethod" variant="primary" :loading="saving">Save Changes</BaseButton>
          <BaseButton @click="showEditOverlay = false" variant="ghost">Cancel</BaseButton>
        </div>
      </template>
    </SlideOverlay>

    <!-- Add Method Overlay -->
    <SlideOverlay v-model="showAddOverlay" title="Add Payment Method" subtitle="Configure a new payment method" icon="Plus" width="md">
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input v-model="addForm.name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. M-Pesa, Cash, Bank Transfer" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <input v-model="addForm.description" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Short description" />
        </div>
        <div class="flex items-center gap-2">
          <input v-model="addForm.is_active" type="checkbox" id="new-method-active" class="rounded border-slate-300" />
          <label for="new-method-active" class="text-sm text-slate-700">Active</label>
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton @click="createMethod" variant="primary" :loading="saving">Add Method</BaseButton>
          <BaseButton @click="showAddOverlay = false" variant="ghost">Cancel</BaseButton>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { CreditCard, RefreshCw, Plus, CheckCircle, Smartphone, TrendingUp, Edit2 } from 'lucide-vue-next'
import axios from 'axios'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/dashboard/billing' },
  { label: 'Payment Methods' }
]

const loading = ref(false)
const refreshing = ref(false)
const showEditOverlay = ref(false)
const showAddOverlay = ref(false)
const selectedMethod = ref(null)
const saving = ref(false)

const editForm = ref({ name: '', description: '', is_active: true })
const addForm = ref({ name: '', description: '', is_active: true })

const methods = ref([])

const iconMap = {
  'mpesa': { icon: Smartphone, iconColor: 'text-green-600', gradient: 'from-green-500 to-emerald-600' },
  'm-pesa': { icon: Smartphone, iconColor: 'text-green-600', gradient: 'from-green-500 to-emerald-600' },
  'cash': { icon: CreditCard, iconColor: 'text-amber-600', gradient: 'from-amber-500 to-yellow-600' },
  'bank': { icon: CreditCard, iconColor: 'text-blue-600', gradient: 'from-blue-500 to-indigo-600' },
  'bank transfer': { icon: CreditCard, iconColor: 'text-blue-600', gradient: 'from-blue-500 to-indigo-600' },
}

const getMethodStyle = (name) => {
  const key = (name || '').toLowerCase()
  for (const [k, v] of Object.entries(iconMap)) {
    if (key.includes(k)) return v
  }
  return { icon: CreditCard, iconColor: 'text-slate-600', gradient: 'from-slate-500 to-slate-600' }
}

const stats = computed(() => ({
  total: methods.value.length,
  active: methods.value.filter(m => m.is_active).length,
  thisMonth: methods.value.reduce((sum, m) => sum + m.transactions, 0)
}))

const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)

const fetchMethods = async () => {
  const isInitial = methods.value.length === 0
  if (isInitial) loading.value = true
  try {
    const response = await axios.get('/billing/payment-methods')
    const data = response.data?.methods || response.data?.data || []
    methods.value = data.map(m => {
      const style = getMethodStyle(m.name)
      return {
        id: m.id,
        name: m.name,
        description: m.description || '',
        icon: style.icon,
        iconColor: style.iconColor,
        gradient: style.gradient,
        is_active: m.is_active ?? true,
        transactions: m.transactions_count || m.transactions || 0,
        total_amount: Number(m.total_amount || 0),
        success_rate: m.success_rate || 0
      }
    })
  } catch (err) {
    console.error('fetchMethods error:', err)
  } finally {
    loading.value = false
  }
}

const refreshMethods = async () => {
  refreshing.value = true
  await fetchMethods()
  refreshing.value = false
}

const openAddModal = () => {
  addForm.value = { name: '', description: '', is_active: true }
  showAddOverlay.value = true
}

const editMethod = (method) => {
  selectedMethod.value = method
  editForm.value = { name: method.name, description: method.description, is_active: method.is_active }
  showEditOverlay.value = true
}

const saveMethod = async () => {
  if (!selectedMethod.value) return
  saving.value = true
  try {
    await axios.patch(`/billing/payment-methods/${selectedMethod.value.id}`, editForm.value)
    showEditOverlay.value = false
    await fetchMethods()
  } catch (err) {
    console.error('saveMethod error:', err)
    alert(err.response?.data?.message || 'Failed to update payment method')
  } finally {
    saving.value = false
  }
}

const createMethod = async () => {
  if (!addForm.value.name) {
    alert('Method name is required.')
    return
  }
  saving.value = true
  try {
    await axios.post('/billing/payment-methods', addForm.value)
    showAddOverlay.value = false
    await fetchMethods()
  } catch (err) {
    console.error('createMethod error:', err)
    alert(err.response?.data?.message || 'Failed to create payment method')
  } finally {
    saving.value = false
  }
}

const toggleStatus = async (method) => {
  try {
    await axios.patch(`/billing/payment-methods/${method.id}`, { is_active: !method.is_active })
    method.is_active = !method.is_active
  } catch (err) {
    console.error('toggleStatus error:', err)
    alert(err.response?.data?.message || 'Failed to toggle status')
  }
}

onMounted(() => {
  fetchMethods()
})
</script>
