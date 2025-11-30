<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Add Package"
      subtitle="Create a new internet service package"
      icon="Package"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="$router.back()" variant="ghost">
          <ArrowLeft class="w-4 h-4 mr-1" />
          Back
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Content -->
    <PageContent>
      <div class="max-w-4xl mx-auto">
        <form @submit.prevent="handleSubmit" class="space-y-6">
          <!-- Basic Information -->
          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Basic Information</h3>
              
              <div class="space-y-4">
                <!-- Package Name -->
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Package Name *
                  </label>
                  <input
                    v-model="formData.name"
                    type="text"
                    required
                    class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., Home Basic 10 Mbps"
                  />
                </div>

                <!-- Description -->
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Description
                  </label>
                  <textarea
                    v-model="formData.description"
                    rows="3"
                    class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Brief description of the package..."
                  ></textarea>
                </div>

                <!-- Package Type -->
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Package Type *
                  </label>
                  <div class="grid grid-cols-2 gap-4">
                    <div
                      @click="formData.type = 'hotspot'"
                      class="relative border-2 rounded-lg p-4 cursor-pointer transition-all"
                      :class="formData.type === 'hotspot' 
                        ? 'border-purple-500 bg-purple-50' 
                        : 'border-slate-200 hover:border-purple-300'"
                    >
                      <div class="flex items-center gap-3">
                        <div class="p-3 rounded-lg" :class="formData.type === 'hotspot' ? 'bg-purple-100' : 'bg-slate-100'">
                          <Wifi class="w-6 h-6" :class="formData.type === 'hotspot' ? 'text-purple-600' : 'text-slate-600'" />
                        </div>
                        <div>
                          <div class="font-semibold text-slate-900">Hotspot</div>
                          <div class="text-xs text-slate-500">Voucher-based access</div>
                        </div>
                      </div>
                      <div v-if="formData.type === 'hotspot'" class="absolute top-2 right-2">
                        <CheckCircle class="w-5 h-5 text-purple-600" />
                      </div>
                    </div>

                    <div
                      @click="formData.type = 'pppoe'"
                      class="relative border-2 rounded-lg p-4 cursor-pointer transition-all"
                      :class="formData.type === 'pppoe' 
                        ? 'border-cyan-500 bg-cyan-50' 
                        : 'border-slate-200 hover:border-cyan-300'"
                    >
                      <div class="flex items-center gap-3">
                        <div class="p-3 rounded-lg" :class="formData.type === 'pppoe' ? 'bg-cyan-100' : 'bg-slate-100'">
                          <Network class="w-6 h-6" :class="formData.type === 'pppoe' ? 'text-cyan-600' : 'text-slate-600'" />
                        </div>
                        <div>
                          <div class="font-semibold text-slate-900">PPPoE</div>
                          <div class="text-xs text-slate-500">Direct connection</div>
                        </div>
                      </div>
                      <div v-if="formData.type === 'pppoe'" class="absolute top-2 right-2">
                        <CheckCircle class="w-5 h-5 text-cyan-600" />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </BaseCard>

          <!-- Pricing & Validity -->
          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Pricing & Validity</h3>
              
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Price -->
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Price (KES) *
                  </label>
                  <input
                    v-model.number="formData.price"
                    type="number"
                    min="0"
                    step="1"
                    required
                    class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., 2000"
                  />
                </div>

                <!-- Validity -->
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Validity Period *
                  </label>
                  <BaseSelect v-model="formData.validity" required class="w-full">
                    <option value="">Select validity...</option>
                    <option value="1 hour">1 Hour</option>
                    <option value="3 hours">3 Hours</option>
                    <option value="6 hours">6 Hours</option>
                    <option value="12 hours">12 Hours</option>
                    <option value="24 hours">1 Day (24 Hours)</option>
                    <option value="3 days">3 Days</option>
                    <option value="7 days">1 Week (7 Days)</option>
                    <option value="30 days">1 Month (30 Days)</option>
                    <option value="90 days">3 Months (90 Days)</option>
                    <option value="365 days">1 Year (365 Days)</option>
                  </BaseSelect>
                </div>
              </div>
            </div>
          </BaseCard>

          <!-- Speed & Data Limits -->
          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Speed & Data Limits</h3>
              
              <div class="space-y-4">
                <!-- Speed Configuration -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                      Download Speed (Mbps) *
                    </label>
                    <input
                      v-model.number="formData.download_speed"
                      type="number"
                      min="1"
                      step="1"
                      required
                      class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="e.g., 10"
                    />
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                      Upload Speed (Mbps) *
                    </label>
                    <input
                      v-model.number="formData.upload_speed"
                      type="number"
                      min="1"
                      step="1"
                      required
                      class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="e.g., 10"
                    />
                  </div>
                </div>

                <!-- Data Limit -->
                <div>
                  <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-slate-700">
                      Data Limit
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input
                        v-model="formData.unlimited_data"
                        type="checkbox"
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm text-slate-600">Unlimited</span>
                    </label>
                  </div>
                  <div v-if="!formData.unlimited_data" class="grid grid-cols-2 gap-4">
                    <input
                      v-model.number="formData.data_limit_value"
                      type="number"
                      min="1"
                      step="1"
                      class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="e.g., 50"
                    />
                    <BaseSelect v-model="formData.data_limit_unit" class="w-full">
                      <option value="MB">MB</option>
                      <option value="GB">GB</option>
                      <option value="TB">TB</option>
                    </BaseSelect>
                  </div>
                  <div v-else class="text-sm text-slate-500 italic">
                    No data limit will be applied
                  </div>
                </div>

                <!-- Burst Limit (Optional) -->
                <div v-if="formData.type === 'pppoe'">
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Burst Speed (Optional)
                  </label>
                  <div class="grid grid-cols-2 gap-4">
                    <input
                      v-model.number="formData.burst_download"
                      type="number"
                      min="0"
                      step="1"
                      class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="Download burst (Mbps)"
                    />
                    <input
                      v-model.number="formData.burst_upload"
                      type="number"
                      min="0"
                      step="1"
                      class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="Upload burst (Mbps)"
                    />
                  </div>
                  <p class="mt-1 text-xs text-slate-500">Temporary speed boost when bandwidth is available</p>
                </div>
              </div>
            </div>
          </BaseCard>

          <!-- Additional Settings -->
          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Additional Settings</h3>
              
              <div class="space-y-4">
                <!-- Status -->
                <div class="flex items-center justify-between p-4 bg-slate-50 rounded-lg">
                  <div>
                    <div class="font-medium text-slate-900">Package Status</div>
                    <div class="text-sm text-slate-500">Enable this package for new subscriptions</div>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input
                      v-model="formData.is_active"
                      type="checkbox"
                      class="sr-only peer"
                    />
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                  </label>
                </div>

                <!-- Featured Package -->
                <div class="flex items-center justify-between p-4 bg-slate-50 rounded-lg">
                  <div>
                    <div class="font-medium text-slate-900">Featured Package</div>
                    <div class="text-sm text-slate-500">Highlight this package on the public page</div>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input
                      v-model="formData.is_featured"
                      type="checkbox"
                      class="sr-only peer"
                    />
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                  </label>
                </div>

                <!-- Display Order -->
                <div>
                  <label class="block text-sm font-medium text-slate-700 mb-2">
                    Display Order
                  </label>
                  <input
                    v-model.number="formData.display_order"
                    type="number"
                    min="0"
                    step="1"
                    class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., 1"
                  />
                  <p class="mt-1 text-xs text-slate-500">Lower numbers appear first</p>
                </div>
              </div>
            </div>
          </BaseCard>

          <!-- Package Preview -->
          <BaseCard v-if="formData.name">
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Package Preview</h3>
              
              <div class="max-w-sm">
                <div class="bg-white rounded-xl border-2 border-slate-200 overflow-hidden">
                  <!-- Header -->
                  <div class="p-6 bg-gradient-to-br" :class="formData.type === 'hotspot' ? 'from-purple-500 to-indigo-600' : 'from-cyan-500 to-blue-600'">
                    <div class="flex items-start justify-between mb-4">
                      <div class="p-3 bg-white/90 rounded-lg">
                        <component :is="formData.type === 'hotspot' ? Wifi : Network" class="w-6 h-6" :class="formData.type === 'hotspot' ? 'text-purple-600' : 'text-cyan-600'" />
                      </div>
                      <BaseBadge :variant="formData.is_active ? 'success' : 'secondary'" size="sm">
                        {{ formData.is_active ? 'Active' : 'Inactive' }}
                      </BaseBadge>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-1">{{ formData.name }}</h3>
                    <p class="text-white/80 text-sm">{{ formData.description || 'No description' }}</p>
                  </div>

                  <!-- Details -->
                  <div class="p-6 space-y-4">
                    <div class="flex items-baseline justify-between">
                      <div>
                        <div class="text-3xl font-bold text-slate-900">KES {{ formatMoney(formData.price || 0) }}</div>
                        <div class="text-xs text-slate-500">per {{ formData.validity || 'period' }}</div>
                      </div>
                      <BaseBadge :variant="formData.type === 'hotspot' ? 'purple' : 'info'">
                        {{ formData.type || 'Type' }}
                      </BaseBadge>
                    </div>

                    <div class="space-y-2">
                      <div class="flex items-center gap-2 text-sm text-slate-700">
                        <Zap class="w-4 h-4 text-blue-600" />
                        <span class="font-medium">{{ formData.download_speed || 0 }} Mbps / {{ formData.upload_speed || 0 }} Mbps</span>
                      </div>
                      <div class="flex items-center gap-2 text-sm text-slate-700">
                        <HardDrive class="w-4 h-4 text-green-600" />
                        <span class="font-medium">{{ getDataLimitDisplay() }}</span>
                      </div>
                      <div class="flex items-center gap-2 text-sm text-slate-700">
                        <Clock class="w-4 h-4 text-amber-600" />
                        <span class="font-medium">{{ formData.validity || 'Not set' }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </BaseCard>

          <!-- Form Actions -->
          <div class="flex items-center justify-end gap-3 pt-6 border-t border-slate-200">
            <BaseButton @click="$router.back()" variant="ghost" type="button">
              Cancel
            </BaseButton>
            <BaseButton @click="handleSaveAndNew" variant="ghost" type="button" :loading="saving">
              Save & Add Another
            </BaseButton>
            <BaseButton type="submit" variant="primary" :loading="saving">
              <Save class="w-4 h-4 mr-1" />
              Create Package
            </BaseButton>
          </div>
        </form>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { 
  Package, ArrowLeft, Save, Wifi, Network, CheckCircle,
  Zap, HardDrive, Clock
} from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const router = useRouter()

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Packages', to: '/dashboard/packages' },
  { label: 'Add Package' }
]

// State
const saving = ref(false)

const formData = ref({
  name: '',
  description: '',
  type: 'hotspot',
  price: null,
  validity: '',
  download_speed: null,
  upload_speed: null,
  data_limit_value: null,
  data_limit_unit: 'GB',
  unlimited_data: false,
  burst_download: null,
  burst_upload: null,
  is_active: true,
  is_featured: false,
  display_order: 0
})

// Methods
const getDataLimitDisplay = () => {
  if (formData.value.unlimited_data) {
    return 'Unlimited'
  }
  if (formData.value.data_limit_value) {
    return `${formData.value.data_limit_value} ${formData.value.data_limit_unit}`
  }
  return 'Not set'
}

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const handleSubmit = async () => {
  saving.value = true
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 1000))
    
    console.log('Creating package:', formData.value)
    alert('Package created successfully!')
    router.push('/dashboard/packages')
  } catch (err) {
    console.error('Error creating package:', err)
    alert('Failed to create package')
  } finally {
    saving.value = false
  }
}

const handleSaveAndNew = async () => {
  saving.value = true
  
  try {
    await new Promise(resolve => setTimeout(resolve, 1000))
    
    console.log('Creating package:', formData.value)
    alert('Package created! Add another one.')
    
    // Reset form
    formData.value = {
      name: '',
      description: '',
      type: 'hotspot',
      price: null,
      validity: '',
      download_speed: null,
      upload_speed: null,
      data_limit_value: null,
      data_limit_unit: 'GB',
      unlimited_data: false,
      burst_download: null,
      burst_upload: null,
      is_active: true,
      is_featured: false,
      display_order: 0
    }
  } catch (err) {
    console.error('Error creating package:', err)
    alert('Failed to create package')
  } finally {
    saving.value = false
  }
}
</script>
