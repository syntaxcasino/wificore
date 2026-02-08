<template>
  <form @submit.prevent="handleSubmit" class="space-y-6">
    <!-- Name -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Department Name <span class="text-red-500">*</span>
      </label>
      <input
        v-model="formData.name"
        type="text"
        required
        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors"
        placeholder="Enter department name"
      />
    </div>

    <!-- Code -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Department Code <span class="text-red-500">*</span>
      </label>
      <input
        v-model="formData.code"
        type="text"
        required
        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors"
        placeholder="e.g., HR, IT, FIN"
      />
    </div>

    <!-- Description -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Description
      </label>
      <textarea
        v-model="formData.description"
        rows="4"
        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors resize-none"
        placeholder="Enter description (optional)"
      ></textarea>
    </div>

    <!-- Location and Budget -->
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Location
        </label>
        <input
          v-model="formData.location"
          type="text"
          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors"
          placeholder="e.g., Building A, Floor 2"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Budget
        </label>
        <input
          v-model="formData.budget"
          type="number"
          step="0.01"
          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-colors"
          placeholder="0.00"
        />
      </div>
    </div>

    <!-- Form Actions -->
    <div class="flex items-center gap-3 pt-6 border-t border-gray-200">
      <button
        type="submit"
        class="flex-1 px-6 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-lg hover:shadow-lg transition-all duration-300 font-semibold"
      >
        {{ isEdit ? 'Update Department' : 'Create Department' }}
      </button>
      <button
        type="button"
        @click="$emit('cancel')"
        class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-semibold"
      >
        Cancel
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  department: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['submit', 'cancel'])

const isEdit = ref(!!props.department)

const formData = ref({
  name: '',
  code: '',
  description: '',
  location: '',
  budget: null
})

// Watch for department prop changes
watch(() => props.department, (newDepartment) => {
  if (newDepartment) {
    isEdit.value = true
    formData.value = {
      name: newDepartment.name || '',
      code: newDepartment.code || '',
      description: newDepartment.description || '',
      location: newDepartment.location || '',
      budget: newDepartment.budget || null
    }
  } else {
    isEdit.value = false
    formData.value = {
      name: '',
      code: '',
      description: '',
      location: '',
      budget: null
    }
  }
}, { immediate: true })

const handleSubmit = () => {
  emit('submit', formData.value)
}
</script>
