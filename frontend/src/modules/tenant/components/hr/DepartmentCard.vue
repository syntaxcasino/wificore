<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-all duration-300">
    <div class="flex items-start gap-4">
      <div class="flex-1">
        <div class="flex items-start justify-between mb-3">
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">
              {{ department.name }}
            </h3>
            <p class="text-sm text-gray-600 line-clamp-2">{{ department.description || 'No description' }}</p>
          </div>
          <div class="flex items-center gap-2 ml-4">
            <span 
              class="px-3 py-1 rounded-full text-xs font-semibold"
              :class="getStatusClass(department.status)"
            >
              {{ department.status }}
            </span>
          </div>
        </div>

        <!-- Meta Information -->
        <div class="flex items-center gap-4 mb-4 text-xs text-gray-500">
          <span class="flex items-center gap-1">
            <Building2 class="w-3 h-3" />
            Code: {{ department.code }}
          </span>
          <span v-if="department.location" class="flex items-center gap-1">
            <MapPin class="w-3 h-3" />
            {{ department.location }}
          </span>
          <span v-if="department.employee_count !== undefined" class="flex items-center gap-1">
            <Users class="w-3 h-3" />
            {{ department.employee_count }} employees
          </span>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center gap-2">
          <button
            v-if="department.status === 'pending_approval'"
            @click="$emit('approve', department)"
            class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium flex items-center justify-center gap-2"
          >
            <CheckCircle2 class="w-4 h-4" />
            Approve
          </button>
          
          <button
            @click="$emit('edit', department)"
            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium flex items-center gap-2"
          >
            <Edit2 class="w-4 h-4" />
            Edit
          </button>
        </div>
      </div>

      <!-- Actions Menu -->
      <div class="flex flex-col gap-1">
        <button
          @click="$emit('delete', department)"
          class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
          title="Delete"
        >
          <Trash2 class="w-4 h-4" />
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Building2, MapPin, Users, CheckCircle2, Edit2, Trash2 } from 'lucide-vue-next'

defineProps({
  department: {
    type: Object,
    required: true
  }
})

defineEmits(['edit', 'delete', 'approve'])

const getStatusClass = (status) => {
  const classes = {
    'active': 'bg-green-100 text-green-800',
    'pending_approval': 'bg-orange-100 text-orange-800',
    'inactive': 'bg-gray-100 text-gray-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}
</script>
