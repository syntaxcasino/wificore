<template>
  <SlideOverlay
    :model-value="modelValue"
    :title="isEditing ? 'Edit Access Point' : 'Add Access Point'"
    :subtitle="isEditing ? 'Update access point details' : 'Create a new access point'"
    icon="wifi"
    width="480px"
    @update:model-value="$emit('update:modelValue', $event)"
    @close="$emit('close')"
  >
    <div class="p-6 space-y-4">
      <!-- Router -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Router <span class="text-red-500">*</span></label>
        <select
          v-model="formData.router_id"
          required
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white"
        >
          <option value="">Select a router</option>
          <option v-for="router in availableRouters" :key="router.id" :value="router.id">
            {{ router.name }} ({{ router.ip_address }})
          </option>
        </select>
      </div>

      <!-- Name -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input
          v-model="formData.name"
          type="text"
          required
          placeholder="Access Point Name"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
        />
      </div>

      <!-- Vendor -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Vendor</label>
        <select
          v-model="formData.vendor"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white"
        >
          <option v-for="v in vendors" :key="v.value" :value="v.value">{{ v.label }}</option>
        </select>
      </div>

      <!-- Model -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Model</label>
        <input
          v-model="formData.model"
          type="text"
          placeholder="Model (e.g., UniFi AP AC Pro)"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
        />
      </div>

      <!-- IP Address -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">IP Address <span class="text-red-500">*</span></label>
        <input
          v-model="formData.ip_address"
          type="text"
          required
          placeholder="192.168.1.100"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-mono"
        />
      </div>

      <!-- MAC Address -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">MAC Address</label>
        <input
          v-model="formData.mac_address"
          type="text"
          placeholder="AA:BB:CC:DD:EE:FF"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-mono"
        />
      </div>

      <!-- Serial Number -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Serial Number</label>
        <input
          v-model="formData.serial_number"
          type="text"
          placeholder="Required for Zero-Touch"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
        />
      </div>

      <!-- Location -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Location</label>
        <input
          v-model="formData.location"
          type="text"
          placeholder="Building A, Floor 2"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
        />
      </div>

      <!-- Error Message -->
      <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">
        {{ error }}
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="$emit('close')"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Cancel
        </button>
        <button
          @click="handleSubmit"
          :disabled="submitting || !formData.name.trim() || !formData.router_id || !formData.ip_address.trim()"
          class="flex-1 px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          :class="isEditing ? 'bg-blue-600 hover:bg-blue-700' : 'bg-emerald-600 hover:bg-emerald-700'"
        >
          <span v-if="submitting">{{ isEditing ? 'Saving...' : 'Adding...' }}</span>
          <span v-else>{{ isEditing ? 'Save Changes' : 'Add Access Point' }}</span>
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, watch } from 'vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  isEditing: { type: Boolean, default: false },
  ap: { type: Object, default: null },
  availableRouters: { type: Array, default: () => [] },
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'close', 'submit'])

const vendors = [
  { value: 'ubiquiti', label: 'Ubiquiti Networks' },
  { value: 'mikrotik', label: 'MikroTik' },
  { value: 'tp-link', label: 'TP-Link' },
  { value: 'cisco', label: 'Cisco' },
  { value: 'ruckus', label: 'Ruckus' },
  { value: 'aruba', label: 'Aruba' },
  { value: 'other', label: 'Other' }
]

const formData = ref({
  router_id: '',
  name: '',
  vendor: 'other',
  model: '',
  ip_address: '',
  mac_address: '',
  serial_number: '',
  location: ''
})

// Watch for ap changes (when editing)
watch(() => props.ap, (newAp) => {
  if (newAp && props.isEditing) {
    formData.value = {
      router_id: newAp.router_id || '',
      name: newAp.name || '',
      vendor: newAp.vendor || 'other',
      model: newAp.model || '',
      ip_address: newAp.ip_address || '',
      mac_address: newAp.mac_address || '',
      serial_number: newAp.serial_number || '',
      location: newAp.location || ''
    }
  } else if (!props.isEditing) {
    // Reset form for new ap
    formData.value = {
      router_id: '',
      name: '',
      vendor: 'other',
      model: '',
      ip_address: '',
      mac_address: '',
      serial_number: '',
      location: ''
    }
  }
}, { immediate: true })

const handleSubmit = () => {
  if (!formData.value.name.trim() || !formData.value.router_id || !formData.value.ip_address.trim()) return
  emit('submit', { ...formData.value })
}
</script>
