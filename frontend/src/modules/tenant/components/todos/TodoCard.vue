<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-all duration-300">
    <div class="flex items-start gap-4">
      <div class="flex-1">
        <div class="flex items-start justify-between mb-3">
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">
              {{ todo.title }}
            </h3>
            <p class="text-sm text-gray-600 line-clamp-2">{{ todo.description || 'No description' }}</p>
          </div>
          <div class="flex items-center gap-2 ml-4">
            <span 
              class="px-3 py-1 rounded-full text-xs font-semibold"
              :class="getPriorityClass(todo.priority)"
            >
              {{ todo.priority }}
            </span>
            <span 
              class="px-3 py-1 rounded-full text-xs font-semibold"
              :class="getStatusClass(todo.status)"
            >
              {{ todo.status }}
            </span>
          </div>
        </div>

        <!-- Meta Information -->
        <div class="flex items-center gap-4 mb-4 text-xs text-gray-500">
          <span v-if="todo.due_date" class="flex items-center gap-1">
            <Calendar class="w-3 h-3" />
            Due: {{ formatDate(todo.due_date) }}
          </span>
          <span class="flex items-center gap-1">
            <Clock class="w-3 h-3" />
            Created: {{ formatDate(todo.created_at) }}
          </span>
          <span v-if="todo.user" class="flex items-center gap-1">
            <User class="w-3 h-3" />
            {{ todo.user.name }}
          </span>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center gap-2">
          <button
            v-if="todo.status === 'pending'"
            @click="$emit('start', todo)"
            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium flex items-center justify-center gap-2"
          >
            <PlayCircle class="w-4 h-4" />
            Start Task
          </button>
          
          <button
            v-if="todo.status === 'in_progress'"
            @click="$emit('complete', todo)"
            class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium flex items-center justify-center gap-2"
          >
            <CheckCircle2 class="w-4 h-4" />
            Mark Complete
          </button>
          
          <button
            @click="$emit('view', todo)"
            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium flex items-center gap-2"
          >
            <Eye class="w-4 h-4" />
            View
          </button>
        </div>
      </div>

      <!-- Actions Menu -->
      <div class="flex flex-col gap-1">
        <button
          v-if="todo.status !== 'completed'"
          @click="$emit('edit', todo)"
          class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
          title="Edit"
        >
          <Edit2 class="w-4 h-4" />
        </button>
        <button
          v-if="todo.status !== 'completed'"
          @click="$emit('delete', todo)"
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
import { Calendar, Clock, User, PlayCircle, CheckCircle2, Eye, Edit2, Trash2 } from 'lucide-vue-next'

defineProps({
  todo: {
    type: Object,
    required: true
  }
})

defineEmits(['edit', 'delete', 'view', 'start', 'complete'])

const getPriorityClass = (priority) => {
  const classes = {
    low: 'bg-gray-100 text-gray-700',
    medium: 'bg-yellow-100 text-yellow-700',
    high: 'bg-red-100 text-red-700'
  }
  return classes[priority] || 'bg-gray-100 text-gray-700'
}

const getStatusClass = (status) => {
  const classes = {
    pending: 'bg-orange-100 text-orange-700',
    in_progress: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700'
  }
  return classes[status] || 'bg-gray-100 text-gray-700'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}
</script>
