<template>
  <SlideOverlay
    :model-value="modelValue"
    title="Todo Details"
    :subtitle="todoDetails?.title || 'Task information'"
    icon="checklist"
    width="480px"
    @update:model-value="$emit('update:modelValue', $event)"
    @close="$emit('close')"
  >
    <!-- Loading State -->
    <div v-if="loading" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div class="relative">
        <div class="w-12 h-12 border-[3px] border-blue-100 rounded-full"></div>
        <div class="w-12 h-12 border-[3px] border-t-blue-500 border-r-transparent border-b-blue-500 border-l-blue-500 rounded-full animate-spin absolute top-0"></div>
      </div>
      <p class="text-gray-500 font-medium">Loading todo details...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div class="p-3 bg-red-100 rounded-full">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <p class="text-center text-gray-700 font-medium max-w-md">{{ error }}</p>
    </div>

    <!-- Main Content -->
    <div v-else class="p-4 overflow-y-auto flex-1 bg-gray-50">
      <!-- Status Indicator -->
      <div class="flex items-center justify-between mb-6 p-4 bg-white rounded-xl shadow-sm">
        <div class="flex items-center">
          <div :class="statusDotClass" class="w-3 h-3 rounded-full mr-3"></div>
          <span class="text-sm font-medium capitalize">{{ todoDetails?.status || 'unknown' }}</span>
        </div>
        <EntityStatusBadge :status="todoDetails?.status === 'completed' ? 'completed' : 'pending'" size="sm" />
      </div>

      <!-- Todo Details -->
      <div class="space-y-4">
        <!-- Basic Info Card -->
        <div class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Basic Information
          </h4>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Title</label>
              <p class="text-gray-900 font-medium">{{ todoDetails?.title || 'N/A' }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
              <p class="text-gray-900 text-sm">{{ todoDetails?.description || 'No description provided' }}</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Priority</label>
                <span :class="priorityBadgeClass" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">
                  {{ todoDetails?.priority || 'medium' }}
                </span>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                <span :class="statusBadgeClass" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">
                  {{ todoDetails?.status || 'pending' }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Due Date Card -->
        <div v-if="todoDetails?.due_date" class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Due Date
          </h4>
          <div class="flex items-center gap-3">
            <div class="bg-gradient-to-br from-amber-50 to-orange-50 p-3 rounded-lg border border-amber-200">
              <div class="text-[11px] text-amber-600 font-medium">Date</div>
              <div class="text-amber-900 font-bold">{{ formatDate(todoDetails.due_date) }}</div>
            </div>
            <div v-if="isOverdue" class="px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded-full">
              Overdue
            </div>
          </div>
        </div>

        <!-- Timestamps Card -->
        <div class="bg-white p-5 rounded-xl shadow-sm">
          <h4 class="text-sm font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Timestamps
          </h4>
          <div class="grid grid-cols-1 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Created</label>
              <p class="text-gray-900 text-sm">{{ formatDate(todoDetails?.created_at) }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Last Updated</label>
              <p class="text-gray-900 text-sm">{{ formatDate(todoDetails?.updated_at) }}</p>
            </div>
            <div v-if="todoDetails?.completed_at">
              <label class="block text-xs font-medium text-gray-500 mb-1">Completed</label>
              <p class="text-gray-900 text-sm">{{ formatDate(todoDetails.completed_at) }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer Actions -->
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="$emit('close')"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Close
        </button>
        <button
          v-if="todoDetails?.status !== 'completed'"
          @click="$emit('complete')"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors"
        >
          Mark as Complete
        </button>
        <button
          v-if="todoDetails?.status === 'completed'"
          @click="$emit('reopen')"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
        >
          Reopen
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed } from 'vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  todoDetails: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'close', 'complete', 'reopen'])

const statusDotClass = computed(() => {
  const status = props.todoDetails?.status
  if (status === 'completed') return 'bg-emerald-500'
  if (status === 'in_progress') return 'bg-blue-500'
  return 'bg-amber-500'
})

const priorityBadgeClass = computed(() => {
  const priority = props.todoDetails?.priority
  const classes = {
    high: 'bg-red-100 text-red-800',
    medium: 'bg-yellow-100 text-yellow-800',
    low: 'bg-slate-100 text-slate-800'
  }
  return classes[priority] || classes.medium
})

const statusBadgeClass = computed(() => {
  const status = props.todoDetails?.status
  const classes = {
    pending: 'bg-amber-100 text-amber-800',
    in_progress: 'bg-blue-100 text-blue-800',
    completed: 'bg-emerald-100 text-emerald-800'
  }
  return classes[status] || classes.pending
})

const isOverdue = computed(() => {
  if (!props.todoDetails?.due_date) return false
  const due = new Date(props.todoDetails.due_date)
  const today = new Date()
  return due < today && props.todoDetails.status !== 'completed'
})

const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}
</script>
