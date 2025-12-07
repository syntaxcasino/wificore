<template>
  <form @submit.prevent="handleSubmit" class="space-y-6">
    <!-- Title -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Title <span class="text-red-500">*</span>
      </label>
      <input
        v-model="formData.title"
        type="text"
        required
        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
        placeholder="Enter todo title"
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
        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none"
        placeholder="Enter description (optional)"
      ></textarea>
    </div>

    <!-- Priority and Status -->
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Priority <span class="text-red-500">*</span>
        </label>
        <select
          v-model="formData.priority"
          required
          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
        >
          <option value="low">Low</option>
          <option value="medium">Medium</option>
          <option value="high">High</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Status
        </label>
        <select
          v-model="formData.status"
          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
        >
          <option value="pending">Pending</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
        </select>
      </div>
    </div>

    <!-- Due Date -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Due Date
      </label>
      <input
        v-model="formData.due_date"
        type="date"
        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
      />
    </div>

    <!-- Activity Log (only for editing) -->
    <div v-if="isEdit && todo" class="pt-6 border-t border-gray-200">
      <h3 class="text-sm font-medium text-gray-700 mb-4">Recent Activity</h3>
      <TodoActivityLog :todo-id="todo.id" :limit="3" />
    </div>

    <!-- Form Actions -->
    <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-200">
      <button
        type="button"
        @click="$emit('cancel')"
        class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium"
        :disabled="loading"
      >
        Cancel
      </button>
      <button
        type="submit"
        class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:shadow-lg transition-all duration-300 font-medium flex items-center gap-2"
        :disabled="loading"
      >
        <span v-if="loading" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
        <Save v-else class="w-4 h-4" />
        {{ isEdit ? 'Update' : 'Create' }} Todo
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Save } from 'lucide-vue-next'
import TodoActivityLog from './TodoActivityLog.vue'

const props = defineProps({
  todo: {
    type: Object,
    default: null
  },
  isEdit: {
    type: Boolean,
    default: false
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['submit', 'cancel'])

const formData = ref({
  title: '',
  description: '',
  priority: 'medium',
  status: 'pending',
  due_date: ''
})

// Watch for todo changes (when editing)
watch(() => props.todo, (newTodo) => {
  if (newTodo) {
    formData.value = {
      title: newTodo.title || '',
      description: newTodo.description || '',
      priority: newTodo.priority || 'medium',
      status: newTodo.status || 'pending',
      due_date: newTodo.due_date || ''
    }
  } else {
    // Reset form for new todo
    formData.value = {
      title: '',
      description: '',
      priority: 'medium',
      status: 'pending',
      due_date: ''
    }
  }
}, { immediate: true })

const handleSubmit = () => {
  emit('submit', formData.value)
}
</script>
