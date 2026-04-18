<template>
  <div class="bg-white dark:bg-slate-800 rounded-b-lg rounded-t-none border border-slate-200 dark:border-slate-700 shadow-sm px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <!-- Info Text -->
    <div class="text-sm text-slate-600 dark:text-slate-400">
      <slot name="info">
        Showing {{ start }} to {{ end }} of {{ total }} {{ itemName }}
      </slot>
    </div>
    
    <!-- Controls -->
    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
      <!-- Items per page -->
      <div v-if="showItemsPerPage" class="flex items-center gap-2">
        <span class="text-sm text-slate-600 dark:text-slate-400">Show:</span>
        <select 
          :value="itemsPerPage" 
          @change="handleItemsPerPageChange"
          class="h-9 px-2.5 text-sm bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:outline-none"
        >
          <option v-for="opt in itemsPerPageOptions" :key="opt" :value="opt">{{ opt }}</option>
        </select>
      </div>
      
      <!-- Pagination -->
      <div class="flex items-center gap-1.5">
        <button 
          @click="goToFirst" 
          :disabled="currentPage <= 1"
          class="h-9 px-3 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 disabled:opacity-50 transition-colors"
        >
          First
        </button>
        <button 
          @click="goToPrevious" 
          :disabled="currentPage <= 1"
          class="h-9 px-3 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 disabled:opacity-50 transition-colors"
        >
          &lt;
        </button>
        <div class="h-9 px-3 flex items-center text-sm text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-lg">
          {{ currentPage }} / {{ totalPages || 1 }}
        </div>
        <button 
          @click="goToNext" 
          :disabled="currentPage >= totalPages"
          class="h-9 px-3 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 disabled:opacity-50 transition-colors"
        >
          &gt;
        </button>
        <button 
          @click="goToLast" 
          :disabled="currentPage >= totalPages"
          class="h-9 px-3 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 disabled:opacity-50 transition-colors"
        >
          Last
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  currentPage: { type: Number, required: true },
  totalPages: { type: Number, required: true },
  totalItems: { type: Number, required: true },
  itemsPerPage: { type: Number, default: 10 },
  itemsPerPageOptions: { type: Array, default: () => [10, 25, 50, 100] },
  showItemsPerPage: { type: Boolean, default: true },
  itemName: { type: String, default: 'items' }
})

const emit = defineEmits(['update:currentPage', 'update:itemsPerPage', 'pageChange'])

const start = computed(() => {
  if (props.totalItems === 0) return 0
  return (props.currentPage - 1) * props.itemsPerPage + 1
})

const end = computed(() => {
  if (props.totalItems === 0) return 0
  return Math.min(start.value + props.itemsPerPage - 1, props.totalItems)
})

const total = computed(() => props.totalItems)

const goToFirst = () => {
  if (props.currentPage > 1) {
    emit('update:currentPage', 1)
    emit('pageChange', 1)
  }
}

const goToPrevious = () => {
  const newPage = Math.max(1, props.currentPage - 1)
  if (newPage !== props.currentPage) {
    emit('update:currentPage', newPage)
    emit('pageChange', newPage)
  }
}

const goToNext = () => {
  const newPage = Math.min(props.totalPages || 1, props.currentPage + 1)
  if (newPage !== props.currentPage) {
    emit('update:currentPage', newPage)
    emit('pageChange', newPage)
  }
}

const goToLast = () => {
  const lastPage = props.totalPages || 1
  if (props.currentPage < lastPage) {
    emit('update:currentPage', lastPage)
    emit('pageChange', lastPage)
  }
}

const handleItemsPerPageChange = (event) => {
  emit('update:itemsPerPage', Number(event.target.value))
  emit('update:currentPage', 1)
  emit('pageChange', 1)
}
</script>
