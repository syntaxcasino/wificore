<template>
  <!-- Slide-in Overlay Panel (Right to Left) -->
  <div v-if="showFormOverlay" class="fixed inset-y-0 right-0 z-[9999] w-full sm:w-2/3 lg:w-1/2 xl:w-2/5 bg-white shadow-2xl flex flex-col transition-transform duration-300 ease-out"
       :class="showFormOverlay ? 'translate-x-0' : 'translate-x-full'">
    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex-shrink-0">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-blue-100 rounded-lg">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-800">{{ isEditing ? 'Edit Package' : 'Create New Package' }}</h3>
          <p class="text-xs text-gray-500">{{ isEditing ? 'Update package details' : 'Add a new internet package' }}</p>
        </div>
      </div>
      <button
        type="button"
        @click="$emit('close-form')"
        :disabled="formSubmitting"
        class="p-2 rounded-lg hover:bg-white transition-colors text-gray-500 hover:text-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>

    <!-- Main Content - Scrollable -->
    <div class="flex-1 overflow-y-auto p-6 bg-gray-50">
      <form @submit.prevent="handleSubmit" class="space-y-6">
        <!-- Package Type -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Package Type</label>
          <div class="grid grid-cols-2 gap-3">
            <button
              type="button"
              @click="formData.type = 'hotspot'"
              :class="[
                'p-4 rounded-lg border-2 transition-all',
                formData.type === 'hotspot'
                  ? 'border-purple-500 bg-purple-50'
                  : 'border-gray-200 bg-white hover:border-gray-300'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" :class="formData.type === 'hotspot' ? 'text-purple-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                </svg>
                <span class="text-sm font-medium" :class="formData.type === 'hotspot' ? 'text-purple-700' : 'text-gray-600'">Hotspot</span>
              </div>
            </button>
            <button
              type="button"
              @click="formData.type = 'pppoe'"
              :class="[
                'p-4 rounded-lg border-2 transition-all',
                formData.type === 'pppoe'
                  ? 'border-cyan-500 bg-cyan-50'
                  : 'border-gray-200 bg-white hover:border-gray-300'
              ]"
            >
              <div class="flex flex-col items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" :class="formData.type === 'pppoe' ? 'text-cyan-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                </svg>
                <span class="text-sm font-medium" :class="formData.type === 'pppoe' ? 'text-cyan-700' : 'text-gray-600'">PPPoE</span>
              </div>
            </button>
          </div>
        </div>

        <!-- Basic Information -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Basic Information</h4>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Package Name *</label>
              <input
                v-model="formData.name"
                type="text"
                required
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="e.g., 1 Hour - 5GB"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
              <textarea
                v-model="formData.description"
                rows="2"
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="Brief description of the package"
              ></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Price (KES) *</label>
                <input
                  v-model.number="formData.price"
                  type="number"
                  required
                  min="0"
                  step="0.01"
                  class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="0.00"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Devices *</label>
                <input
                  v-model.number="formData.devices"
                  type="number"
                  required
                  min="1"
                  class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="1"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Speed & Data -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Speed & Data Limits</h4>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Speed *</label>
              <input
                v-model="formData.speed"
                type="text"
                required
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="e.g., 10 Mbps"
              />
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Upload Speed *</label>
                <input
                  v-model="formData.upload_speed"
                  type="text"
                  required
                  class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="e.g., 5 Mbps"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Download Speed *</label>
                <input
                  v-model="formData.download_speed"
                  type="text"
                  required
                  class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="e.g., 10 Mbps"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Data Limit</label>
              <input
                v-model="formData.data_limit"
                type="text"
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="e.g., 50 GB (leave empty for unlimited)"
              />
            </div>
          </div>
        </div>

        <!-- Duration & Validity -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Duration & Validity</h4>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Duration *</label>
              <input
                v-model="formData.duration"
                type="text"
                required
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="e.g., 1 hour, 1 day, 30 days"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Validity *</label>
              <input
                v-model="formData.validity"
                type="text"
                required
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="e.g., 1 hour, 24 hours, 30 days"
              />
            </div>
          </div>
        </div>

        <!-- Advanced Options -->
        <div class="bg-white p-4 rounded-lg border border-gray-200">
          <h4 class="text-sm font-semibold text-gray-800 mb-4">Advanced Options</h4>
          <div class="space-y-3">
            <label class="flex items-center gap-3 cursor-pointer">
              <input
                v-model="formData.enable_burst"
                type="checkbox"
                class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-gray-700">Enable Burst</span>
            </label>

            <label class="flex items-center gap-3 cursor-pointer">
              <input
                v-model="formData.enable_schedule"
                type="checkbox"
                class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-gray-700">Enable Schedule</span>
            </label>

            <!-- Schedule Time Picker (shown when enable_schedule is checked) -->
            <div v-if="formData.enable_schedule" class="ml-7 mt-2 space-y-2">
              <label class="block text-xs font-medium text-gray-700">
                Activation Time <span class="text-red-500">*</span>
              </label>
              <input
                v-model="formData.scheduled_activation_time"
                type="datetime-local"
                class="w-full px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                :min="minDateTime"
              />
              <p class="text-xs text-gray-500">
                Package will be activated at the specified time
              </p>
            </div>

            <label class="flex items-center gap-3 cursor-pointer">
              <input
                v-model="formData.hide_from_client"
                type="checkbox"
                class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-gray-700">Hide from Client</span>
            </label>
          </div>
        </div>

        <!-- Message Display -->
        <div v-if="formMessage.text" :class="[
          'p-4 rounded-lg text-sm',
          formMessage.type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'
        ]">
          {{ formMessage.text }}
        </div>
      </form>
    </div>

    <!-- Footer -->
    <div class="flex-shrink-0 px-6 py-4 border-t border-gray-200 bg-white">
      <div class="flex items-center justify-end gap-3">
        <button
          type="button"
          @click="$emit('close-form')"
          :disabled="formSubmitting"
          class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          Cancel
        </button>
        <button
          type="submit"
          @click="handleSubmit"
          :disabled="formSubmitting"
          class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-md hover:shadow-lg flex items-center gap-2"
        >
          <svg v-if="formSubmitting" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>{{ formSubmitting ? 'Saving...' : (isEditing ? 'Update Package' : 'Create Package') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  showFormOverlay: Boolean,
  formData: Object,
  formSubmitting: Boolean,
  formMessage: Object,
  isEditing: Boolean
})

const emit = defineEmits(['close-form', 'submit'])

// Minimum datetime (current time)
const minDateTime = computed(() => {
  const now = new Date()
  // Format: YYYY-MM-DDTHH:MM
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  const hours = String(now.getHours()).padStart(2, '0')
  const minutes = String(now.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
})

const handleSubmit = () => {
  emit('submit')
}
</script>
