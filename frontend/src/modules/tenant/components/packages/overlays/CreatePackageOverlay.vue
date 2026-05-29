<template>
  <SlideOverlay
    v-model="isOpen"
    :title="isEditing ? 'Edit Package' : 'Create New Package'"
    :subtitle="isEditing ? 'Update package details' : 'Add a new internet package'"
    icon="Package"
    width="70%"
    gradient
    no-padding
    :close-on-backdrop="!formSubmitting"
    @close="$emit('close-form')"
  >
    <!-- Main Content -->
    <div class="flex flex-col h-full overflow-hidden bg-slate-50">
      <!-- Header strip -->
      <div class="flex-shrink-0 bg-gradient-to-r px-6 py-4"
        :class="formData?.type === 'hotspot' ? 'from-purple-700 to-indigo-700' : 'from-cyan-600 to-teal-600'"
      >
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white text-xl font-bold shadow-lg flex-shrink-0">
            {{ (formData?.name || 'P').charAt(0).toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-lg font-bold text-white truncate">{{ formData?.name || (isEditing ? 'Edit Package' : 'New Package') }}</div>
            <div class="text-sm text-white/70 font-mono mt-0.5">KES {{ formatMoney(formData?.price || 0) }} / {{ formData?.validity || formData?.duration || '—' }}</div>
            <div class="flex items-center gap-2 mt-1.5">
              <span class="text-xs text-white/70 bg-white/10 px-2 py-0.5 rounded-full capitalize">{{ formData?.type || 'hotspot' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto min-h-0 p-6">
        <form @submit.prevent="handleSubmit" class="space-y-6">
          <!-- Package Type -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
              Package Type
            </h4>
            <div class="grid grid-cols-2 gap-3">
              <button
                type="button"
                @click="formData.type = 'hotspot'"
                :class="[
                  'p-4 rounded-xl border-2 transition-all',
                  formData.type === 'hotspot'
                    ? 'border-purple-500 bg-purple-50'
                    : 'border-slate-200 bg-white hover:border-slate-300'
                ]"
              >
                <div class="flex flex-col items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" :class="formData.type === 'hotspot' ? 'text-purple-600' : 'text-slate-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                  </svg>
                  <span class="text-sm font-medium" :class="formData.type === 'hotspot' ? 'text-purple-700' : 'text-slate-600'">Hotspot</span>
                </div>
              </button>
              <button
                type="button"
                @click="formData.type = 'pppoe'"
                :class="[
                  'p-4 rounded-xl border-2 transition-all',
                  formData.type === 'pppoe'
                    ? 'border-cyan-500 bg-cyan-50'
                    : 'border-slate-200 bg-white hover:border-slate-300'
                ]"
              >
                <div class="flex flex-col items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" :class="formData.type === 'pppoe' ? 'text-cyan-600' : 'text-slate-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                  </svg>
                  <span class="text-sm font-medium" :class="formData.type === 'pppoe' ? 'text-cyan-700' : 'text-slate-600'">PPPoE</span>
                </div>
              </button>
            </div>
          </div>

          <!-- Basic Information -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              Basic Information
            </h4>
            <div class="space-y-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Package Name <span class="text-red-500">*</span></label>
                <input
                  v-model="formData.name"
                  type="text"
                  required
                  class="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="e.g., 1 Hour - 5GB"
                />
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Description</label>
                <textarea
                  v-model="formData.description"
                  rows="2"
                  class="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Brief description of the package"
                ></textarea>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-slate-500 mb-1">Price (KES) <span class="text-red-500">*</span></label>
                  <input
                    v-model.number="formData.price"
                    type="number"
                    required
                    min="0"
                    step="0.01"
                    class="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="0.00"
                  />
                </div>

                <div>
                  <label class="block text-xs font-medium text-slate-500 mb-1">Max Devices <span class="text-red-500">*</span></label>
                  <input
                    v-model.number="formData.devices"
                    type="number"
                    required
                    min="1"
                    class="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="1"
                  />
                </div>
              </div>
            </div>
          </div>

          <!-- Speed & Data -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
              Speed & Data Limits
            </h4>
            <div class="space-y-4">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-slate-500 mb-1">Download Speed <span class="text-red-500">*</span></label>
                  <div class="flex items-center gap-2">
                    <input
                      v-model="downloadSpeedValue"
                      type="number"
                      min="0"
                      step="0.01"
                      required
                      class="flex-1 px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="e.g., 10"
                    />
                    <select
                      v-model="downloadSpeedUnit"
                      class="w-24 px-2 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    >
                      <option v-for="unit in speedUnits" :key="unit" :value="unit">{{ unit }}</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-medium text-slate-500 mb-1">Upload Speed <span class="text-red-500">*</span></label>
                  <div class="flex items-center gap-2">
                    <input
                      v-model="uploadSpeedValue"
                      type="number"
                      min="0"
                      step="0.01"
                      required
                      class="flex-1 px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="e.g., 5"
                    />
                    <select
                      v-model="uploadSpeedUnit"
                      class="w-24 px-2 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    >
                      <option v-for="unit in speedUnits" :key="unit" :value="unit">{{ unit }}</option>
                    </select>
                  </div>
                </div>
              </div>

              <p class="text-xs text-slate-500">Download speed is used as the package speed label.</p>

              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Data Limit</label>
                <div class="flex items-center gap-2">
                  <input
                    v-model="dataLimitValue"
                    type="number"
                    min="0"
                    step="0.01"
                    class="flex-1 px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., 50 (leave empty for unlimited)"
                  />
                  <select
                    v-model="dataLimitUnit"
                    class="w-24 px-2 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    <option v-for="unit in dataUnits" :key="unit" :value="unit">{{ unit }}</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Duration & Validity -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              Duration & Validity
            </h4>
            <div class="space-y-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Duration <span class="text-red-500">*</span></label>
                <div class="flex items-center gap-2">
                  <input
                    v-model="durationValue"
                    type="number"
                    min="0"
                    step="1"
                    required
                    class="flex-1 px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., 1"
                  />
                  <select
                    v-model="durationUnit"
                    class="w-28 px-2 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    <option v-for="unit in durationUnits" :key="unit" :value="unit">{{ unit }}</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Validity</label>
                <input
                  v-model="formData.validity"
                  type="text"
                  class="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Defaults to duration if left empty"
                />
                <p class="text-xs text-slate-500 mt-1">Leave empty to use duration as validity.</p>
              </div>
            </div>
          </div>

          <!-- Advanced Options -->
          <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-700 mb-4 flex items-center">
              <svg class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              Advanced Options
            </h4>
            <div class="space-y-3">
              <label class="flex items-center gap-3 cursor-pointer">
                <input
                  v-model="formData.enable_burst"
                  type="checkbox"
                  class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
                />
                <span class="text-sm text-slate-700">Enable Burst</span>
              </label>

              <label class="flex items-center gap-3 cursor-pointer">
                <input
                  v-model="formData.enable_schedule"
                  type="checkbox"
                  class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
                />
                <span class="text-sm text-slate-700">Enable Schedule</span>
              </label>

              <!-- Schedule Time Picker -->
              <div v-if="formData.enable_schedule" class="ml-7 mt-2 space-y-2">
                <label class="block text-xs font-medium text-slate-500 mb-1">
                  Activation Time <span class="text-red-500">*</span>
                </label>
                <input
                  v-model="formData.scheduled_activation_time"
                  type="datetime-local"
                  class="w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  :min="minDateTime"
                />
                <p class="text-xs text-slate-500">
                  Package will be activated at the specified time
                </p>
              </div>

              <label class="flex items-center gap-3 cursor-pointer">
                <input
                  v-model="formData.hide_from_client"
                  type="checkbox"
                  class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
                />
                <span class="text-sm text-slate-700">Hide from Client</span>
              </label>
            </div>
          </div>

          <!-- Message Display -->
          <div v-if="formMessage.text" :class="[
            'p-4 rounded-xl text-sm',
            formMessage.type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200'
          ]">
            {{ formMessage.text }}
          </div>
        </form>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          @click="$emit('close-form')"
          :disabled="formSubmitting"
          class="flex-1 px-4 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          Cancel
        </button>
        <button
          type="button"
          @click="handleSubmit"
          :disabled="formSubmitting"
          class="flex-1 px-4 py-2.5 text-sm font-medium text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          :class="formData?.type === 'hotspot' ? 'bg-purple-600 hover:bg-purple-700' : 'bg-cyan-600 hover:bg-cyan-700'"
        >
          {{ formSubmitting ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create Package') }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const props = defineProps({
  showFormOverlay: Boolean,
  formData: Object,
  formSubmitting: Boolean,
  formMessage: Object,
  isEditing: Boolean
})

const emit = defineEmits(['close-form', 'submit'])

const isOpen = computed({
  get: () => props.showFormOverlay,
  set: (val) => { if (!val) emit('close-form') }
})

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const speedUnits = ['Mbps', 'Gbps']
const dataUnits = ['MB', 'GB', 'TB']
const durationUnits = ['Hours', 'Days', 'Months', 'Years']

const uploadSpeedValue = ref('')
const uploadSpeedUnit = ref('Mbps')
const downloadSpeedValue = ref('')
const downloadSpeedUnit = ref('Mbps')
const dataLimitValue = ref('')
const dataLimitUnit = ref('GB')
const durationValue = ref('')
const durationUnit = ref('Hours')

const parseValueUnit = (input, allowedUnits, defaultUnit) => {
  const raw = String(input || '').trim()
  if (!raw) return { value: '', unit: defaultUnit }
  const m = raw.match(/^\s*([0-9]+(?:\.[0-9]+)?)\s*([a-zA-Z]+)\s*$/)
  if (!m) return { value: raw, unit: defaultUnit }
  const value = m[1]
  const unit = m[2]
  const normalized = allowedUnits.find((u) => u.toLowerCase() === unit.toLowerCase())
  return { value, unit: normalized || defaultUnit }
}

const parseDurationValueUnit = (input) => {
  const raw = String(input || '').trim()
  if (!raw) return { value: '', unit: 'Hours' }
  const m = raw.match(/^\s*(\d+)\s*([a-zA-Z]+)\s*$/)
  if (!m) return { value: raw, unit: 'Hours' }
  const value = m[1]
  const unitRaw = m[2].toLowerCase()
  const unitMap = {
    hour: 'Hours',
    hours: 'Hours',
    day: 'Days',
    days: 'Days',
    month: 'Months',
    months: 'Months',
    year: 'Years',
    years: 'Years',
  }
  return { value, unit: unitMap[unitRaw] || 'Hours' }
}

const toDurationString = (value, unit) => {
  const v = String(value || '').trim()
  if (!v) return ''
  const u = String(unit || '').trim()
  const n = Number(v)
  const isOne = Number.isFinite(n) && n === 1
  const map = {
    Hours: isOne ? 'hour' : 'hours',
    Days: isOne ? 'day' : 'days',
    Months: isOne ? 'month' : 'months',
    Years: isOne ? 'year' : 'years',
  }
  return `${v} ${map[u] || (isOne ? 'hour' : 'hours')}`
}

const toValueUnitString = (value, unit) => {
  const v = String(value || '').trim()
  if (!v) return ''
  return `${v} ${unit}`
}

const syncFromFormData = () => {
  const upParsed = parseValueUnit(props.formData?.upload_speed, speedUnits, 'Mbps')
  uploadSpeedValue.value = upParsed.value
  uploadSpeedUnit.value = upParsed.unit

  const downParsed = parseValueUnit(props.formData?.download_speed, speedUnits, 'Mbps')
  downloadSpeedValue.value = downParsed.value
  downloadSpeedUnit.value = downParsed.unit

  const dataParsed = parseValueUnit(props.formData?.data_limit, dataUnits, 'GB')
  dataLimitValue.value = dataParsed.value
  dataLimitUnit.value = dataParsed.unit

  const durationParsed = parseDurationValueUnit(props.formData?.duration)
  durationValue.value = durationParsed.value
  durationUnit.value = durationParsed.unit
}

watch(
  () => props.showFormOverlay,
  (show) => {
    if (show) {
      syncFromFormData()
    }
  }
)

watch([uploadSpeedValue, uploadSpeedUnit], () => {
  props.formData.upload_speed = toValueUnitString(uploadSpeedValue.value, uploadSpeedUnit.value)
})

watch([downloadSpeedValue, downloadSpeedUnit], () => {
  const speed = toValueUnitString(downloadSpeedValue.value, downloadSpeedUnit.value)
  props.formData.download_speed = speed
  props.formData.speed = speed
})

watch([dataLimitValue, dataLimitUnit], () => {
  props.formData.data_limit = toValueUnitString(dataLimitValue.value, dataLimitUnit.value)
})

watch([durationValue, durationUnit], () => {
  props.formData.duration = toDurationString(durationValue.value, durationUnit.value)
})

// Minimum datetime (current time)
const minDateTime = computed(() => {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  const hours = String(now.getHours()).padStart(2, '0')
  const minutes = String(now.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
})

const handleSubmit = () => {
  if (props.formData?.download_speed) {
    props.formData.speed = props.formData.download_speed
  }
  if (!props.isEditing && !props.formData?.validity) {
    props.formData.validity = props.formData.duration
  }
  emit('submit')
}
</script>
