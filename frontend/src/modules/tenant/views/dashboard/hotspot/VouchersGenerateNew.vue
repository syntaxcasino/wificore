<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Generate Vouchers"
      subtitle="Create hotspot vouchers for customers"
      icon="Ticket"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="$router.push('/dashboard/hotspot/users')" variant="ghost">
          <Users class="w-4 h-4 mr-1" />
          View Users
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Content -->
    <PageContent>
      <div class="max-w-4xl mx-auto space-y-6">
        <!-- Generation Form -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Voucher Configuration</h3>
            
            <form @submit.prevent="generateVouchers" class="space-y-6">
              <!-- Package Selection -->
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">
                  Select Package *
                </label>
                <BaseSelect v-model="formData.package_id" required class="w-full">
                  <option value="">Choose a package...</option>
                  <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">
                    {{ pkg.name }} - {{ pkg.speed }} ({{ pkg.validity }})
                  </option>
                </BaseSelect>
                <p v-if="selectedPackage" class="mt-2 text-sm text-slate-600">
                  Price: KES {{ selectedPackage.price }} | Speed: {{ selectedPackage.speed }} | Validity: {{ selectedPackage.validity }}
                </p>
              </div>

              <!-- Quantity -->
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">
                  Number of Vouchers *
                </label>
                <input
                  v-model.number="formData.quantity"
                  type="number"
                  min="1"
                  max="100"
                  required
                  class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Enter quantity (1-100)"
                />
                <p class="mt-1 text-xs text-slate-500">Maximum 100 vouchers per generation</p>
              </div>

              <!-- Prefix (Optional) -->
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">
                  Voucher Prefix (Optional)
                </label>
                <input
                  v-model="formData.prefix"
                  type="text"
                  maxlength="10"
                  class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="e.g., WIFI, HOT, etc."
                />
                <p class="mt-1 text-xs text-slate-500">Add a custom prefix to voucher codes (optional)</p>
              </div>

              <!-- Expiry Date (Optional) -->
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">
                  Expiry Date (Optional)
                </label>
                <input
                  v-model="formData.expiry_date"
                  type="date"
                  :min="minDate"
                  class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                <p class="mt-1 text-xs text-slate-500">Leave empty for no expiry date</p>
              </div>

              <!-- Notes (Optional) -->
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">
                  Notes (Optional)
                </label>
                <textarea
                  v-model="formData.notes"
                  rows="3"
                  class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Add any notes or comments..."
                ></textarea>
              </div>

              <!-- Summary -->
              <div v-if="formData.package_id && formData.quantity" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-blue-900 mb-2">Generation Summary</h4>
                <div class="grid grid-cols-2 gap-3 text-sm">
                  <div>
                    <span class="text-blue-600">Package:</span>
                    <span class="ml-2 font-medium text-blue-900">{{ selectedPackage?.name }}</span>
                  </div>
                  <div>
                    <span class="text-blue-600">Quantity:</span>
                    <span class="ml-2 font-medium text-blue-900">{{ formData.quantity }} vouchers</span>
                  </div>
                  <div>
                    <span class="text-blue-600">Total Value:</span>
                    <span class="ml-2 font-medium text-blue-900">KES {{ totalValue }}</span>
                  </div>
                  <div>
                    <span class="text-blue-600">Prefix:</span>
                    <span class="ml-2 font-medium text-blue-900">{{ formData.prefix || 'None' }}</span>
                  </div>
                </div>
              </div>

              <!-- Action Buttons -->
              <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                <BaseButton @click="resetForm" variant="ghost" type="button">
                  <X class="w-4 h-4 mr-1" />
                  Reset
                </BaseButton>
                <BaseButton 
                  type="submit" 
                  variant="primary" 
                  :loading="generating"
                  :disabled="!formData.package_id || !formData.quantity"
                >
                  <Ticket class="w-4 h-4 mr-1" />
                  Generate {{ formData.quantity || 0 }} Voucher{{ formData.quantity !== 1 ? 's' : '' }}
                </BaseButton>
              </div>
            </form>
          </div>
        </BaseCard>

        <!-- Success Message -->
        <BaseAlert v-if="successMessage" variant="success" :title="successMessage" dismissible @dismiss="successMessage = null">
          <div class="mt-2 flex items-center gap-2">
            <BaseButton @click="downloadVouchers" variant="success" size="sm">
              <Download class="w-4 h-4 mr-1" />
              Download PDF
            </BaseButton>
            <BaseButton @click="printVouchers" variant="ghost" size="sm">
              <Printer class="w-4 h-4 mr-1" />
              Print
            </BaseButton>
          </div>
        </BaseAlert>

        <!-- Generated Vouchers Preview -->
        <BaseCard v-if="generatedVouchers.length">
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-semibold text-slate-900">Generated Vouchers</h3>
              <div class="flex items-center gap-2">
                <BaseButton @click="downloadVouchers" variant="ghost" size="sm">
                  <Download class="w-4 h-4 mr-1" />
                  Download
                </BaseButton>
                <BaseButton @click="printVouchers" variant="ghost" size="sm">
                  <Printer class="w-4 h-4 mr-1" />
                  Print
                </BaseButton>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div 
                v-for="voucher in generatedVouchers" 
                :key="voucher.code"
                class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4"
              >
                <div class="flex items-center justify-between mb-2">
                  <Ticket class="w-5 h-5 text-blue-600" />
                  <BaseBadge variant="success" size="sm">New</BaseBadge>
                </div>
                <div class="font-mono text-lg font-bold text-blue-900 mb-1">{{ voucher.code }}</div>
                <div class="text-sm text-blue-700">{{ voucher.package }}</div>
                <div class="text-xs text-blue-600 mt-2">Valid until: {{ voucher.expiry || 'No expiry' }}</div>
              </div>
            </div>
          </div>
        </BaseCard>

        <!-- Recent Generations -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Recent Generations</h3>
            
            <div v-if="recentGenerations.length" class="space-y-3">
              <div 
                v-for="gen in recentGenerations" 
                :key="gen.id"
                class="flex items-center justify-between p-3 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <div class="flex items-center gap-3">
                  <div class="p-2 bg-blue-100 rounded-lg">
                    <Ticket class="w-4 h-4 text-blue-600" />
                  </div>
                  <div>
                    <div class="font-medium text-slate-900">{{ gen.quantity }} vouchers - {{ gen.package }}</div>
                    <div class="text-sm text-slate-600">{{ formatDateTime(gen.created_at) }}</div>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <BaseBadge :variant="gen.status === 'active' ? 'success' : 'secondary'">
                    {{ gen.status }}
                  </BaseBadge>
                  <BaseButton @click="viewGeneration(gen)" variant="ghost" size="sm">
                    <Eye class="w-4 h-4" />
                  </BaseButton>
                </div>
              </div>
            </div>

            <BaseEmpty 
              v-else
              title="No recent generations"
              description="Your voucher generation history will appear here"
              icon="Ticket"
              :compact="true"
            />
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Ticket, Users, X, Download, Printer, Eye } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Hotspot', to: '/dashboard/hotspot' },
  { label: 'Generate Vouchers' }
]

// State
const generating = ref(false)
const successMessage = ref(null)
const generatedVouchers = ref([])
const recentGenerations = ref([])

const formData = ref({
  package_id: '',
  quantity: 10,
  prefix: '',
  expiry_date: '',
  notes: ''
})

// Mock packages data
const packages = ref([
  { id: 1, name: '1 Hour - 5GB', speed: '10 Mbps', validity: '1 hour', price: 50 },
  { id: 2, name: '3 Hours - 10GB', speed: '10 Mbps', validity: '3 hours', price: 100 },
  { id: 3, name: '1 Day - 20GB', speed: '10 Mbps', validity: '24 hours', price: 200 },
  { id: 4, name: '1 Week - 50GB', speed: '10 Mbps', validity: '7 days', price: 500 },
  { id: 5, name: '1 Month - 100GB', speed: '10 Mbps', validity: '30 days', price: 1000 }
])

// Mock recent generations
const mockRecentGenerations = [
  { id: 1, quantity: 50, package: '1 Hour - 5GB', status: 'active', created_at: new Date().toISOString() },
  { id: 2, quantity: 25, package: '1 Day - 20GB', status: 'active', created_at: new Date(Date.now() - 86400000).toISOString() },
  { id: 3, quantity: 10, package: '1 Week - 50GB', status: 'used', created_at: new Date(Date.now() - 172800000).toISOString() }
]

// Computed
const selectedPackage = computed(() => {
  return packages.value.find(p => p.id === formData.value.package_id)
})

const totalValue = computed(() => {
  if (!selectedPackage.value || !formData.value.quantity) return 0
  return selectedPackage.value.price * formData.value.quantity
})

const minDate = computed(() => {
  const today = new Date()
  return today.toISOString().split('T')[0]
})

// Methods
const generateVouchers = async () => {
  generating.value = true
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 1500))
    
    // Generate mock vouchers
    const vouchers = []
    for (let i = 0; i < formData.value.quantity; i++) {
      const code = generateVoucherCode(formData.value.prefix)
      vouchers.push({
        code,
        package: selectedPackage.value.name,
        expiry: formData.value.expiry_date || null
      })
    }
    
    generatedVouchers.value = vouchers
    successMessage.value = `Successfully generated ${formData.value.quantity} voucher${formData.value.quantity !== 1 ? 's' : ''}!`
    
    // Add to recent generations
    recentGenerations.value.unshift({
      id: Date.now(),
      quantity: formData.value.quantity,
      package: selectedPackage.value.name,
      status: 'active',
      created_at: new Date().toISOString()
    })
    
  } catch (err) {
    console.error('Error generating vouchers:', err)
    alert('Failed to generate vouchers. Please try again.')
  } finally {
    generating.value = false
  }
}

const generateVoucherCode = (prefix = '') => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  let code = prefix ? `${prefix}-` : ''
  for (let i = 0; i < 12; i++) {
    if (i > 0 && i % 4 === 0) code += '-'
    code += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  return code
}

const resetForm = () => {
  formData.value = {
    package_id: '',
    quantity: 10,
    prefix: '',
    expiry_date: '',
    notes: ''
  }
  generatedVouchers.value = []
  successMessage.value = null
}

const downloadVouchers = () => {
  console.log('Downloading vouchers as PDF...')
  // TODO: Implement PDF download
  alert('PDF download feature coming soon!')
}

const printVouchers = () => {
  console.log('Printing vouchers...')
  // TODO: Implement print functionality
  window.print()
}

const viewGeneration = (generation) => {
  console.log('Viewing generation:', generation)
  // TODO: Implement view generation details
}

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

const fetchRecentGenerations = async () => {
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    recentGenerations.value = mockRecentGenerations
  } catch (err) {
    console.error('Error fetching recent generations:', err)
  }
}

// Lifecycle
onMounted(() => {
  fetchRecentGenerations()
})
</script>
