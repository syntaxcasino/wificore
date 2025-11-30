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

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { CreditCard, RefreshCw, Plus, CheckCircle, Smartphone, TrendingUp, Edit2 } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/dashboard/billing' },
  { label: 'Payment Methods' }
]

const loading = ref(false)
const refreshing = ref(false)

const methods = ref([
  {
    id: 1,
    name: 'M-Pesa',
    description: 'Mobile money payment',
    icon: Smartphone,
    iconColor: 'text-green-600',
    gradient: 'from-green-500 to-emerald-600',
    is_active: true,
    transactions: 1247,
    total_amount: 2500000,
    success_rate: 98
  },
  {
    id: 2,
    name: 'Cash',
    description: 'Cash payment',
    icon: CreditCard,
    iconColor: 'text-amber-600',
    gradient: 'from-amber-500 to-yellow-600',
    is_active: true,
    transactions: 456,
    total_amount: 450000,
    success_rate: 100
  },
  {
    id: 3,
    name: 'Bank Transfer',
    description: 'Direct bank transfer',
    icon: CreditCard,
    iconColor: 'text-blue-600',
    gradient: 'from-blue-500 to-indigo-600',
    is_active: true,
    transactions: 234,
    total_amount: 890000,
    success_rate: 95
  }
])

const stats = computed(() => ({
  total: methods.value.length,
  active: methods.value.filter(m => m.is_active).length,
  thisMonth: methods.value.reduce((sum, m) => sum + m.transactions, 0)
}))

const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)

const refreshMethods = async () => {
  refreshing.value = true
  await new Promise(resolve => setTimeout(resolve, 500))
  refreshing.value = false
}

const openAddModal = () => alert('Add payment method modal coming soon!')
const editMethod = (method) => console.log('Edit method:', method)
const toggleStatus = (method) => {
  method.is_active = !method.is_active
}

onMounted(() => { loading.value = false })
</script>
