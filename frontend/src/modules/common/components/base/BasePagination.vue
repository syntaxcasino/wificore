<template>
  <div class="flex items-center gap-3">
    <!-- Items per page selector -->
    <div v-if="showItemsPerPage" class="flex items-center gap-2">
      <label class="text-xs text-slate-600">Show:</label>
      <select 
        :value="itemsPerPage" 
        @change="handleItemsPerPageChange"
        class="text-xs border border-slate-300 rounded px-2 py-1 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
      >
        <option v-for="option in itemsPerPageOptions" :key="option" :value="option">{{ option }}</option>
      </select>
    </div>
    
    <!-- Pagination controls -->
    <div class="flex items-center gap-2">
      <button 
        @click="goToFirst" 
        :disabled="isFirstPage"
        class="px-2 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
      >
        First
      </button>
      
      <button 
        @click="goToPrevious" 
        :disabled="isFirstPage"
        class="px-2 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
      >
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
      </button>
      
      <span class="px-3 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded">
        {{ modelValue }} / {{ totalPages }}
      </span>
      
      <button 
        @click="goToNext" 
        :disabled="isLastPage"
        class="px-2 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
      >
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
      </button>
      
      <button 
        @click="goToLast" 
        :disabled="isLastPage"
        class="px-2 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
      >
        Last
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Number,
    required: true
  },
  totalPages: {
    type: Number,
    required: true
  },
  itemsPerPage: {
    type: Number,
    default: 10
  },
  itemsPerPageOptions: {
    type: Array,
    default: () => [10, 25, 50, 100]
  },
  showItemsPerPage: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits(['update:modelValue', 'update:itemsPerPage'])

const isFirstPage = computed(() => props.modelValue === 1)
const isLastPage = computed(() => props.modelValue === props.totalPages)

const goToFirst = () => {
  if (!isFirstPage.value) {
    emit('update:modelValue', 1)
  }
}

const goToPrevious = () => {
  if (!isFirstPage.value) {
    emit('update:modelValue', props.modelValue - 1)
  }
}

const goToNext = () => {
  if (!isLastPage.value) {
    emit('update:modelValue', props.modelValue + 1)
  }
}

const goToLast = () => {
  if (!isLastPage.value) {
    emit('update:modelValue', props.totalPages)
  }
}

const handleItemsPerPageChange = (event) => {
  emit('update:itemsPerPage', Number(event.target.value))
  emit('update:modelValue', 1) // Reset to first page
}
</script>
