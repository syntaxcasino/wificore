<template>
  <SlideOverlay
    :model-value="modelValue"
    :title="isEditing ? 'Edit Todo' : 'Add Todo'"
    :subtitle="isEditing ? 'Update task details' : 'Create a new task'"
    icon="checklist"
    width="50%"
    @update:model-value="$emit('update:modelValue', $event)"
    @close="$emit('close')"
  >
    <div class="p-6 space-y-4">
      <!-- Title -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Title <span class="text-red-500">*</span></label>
        <input
          v-model="formData.title"
          type="text"
          required
          placeholder="Enter task title..."
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
        />
      </div>

      <!-- Description -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
        <textarea
          v-model="formData.description"
          rows="3"
          placeholder="Enter task description..."
          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-none"
        />
      </div>

      <!-- Priority and Status -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Priority</label>
          <select
            v-model="formData.priority"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white"
          >
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white"
          >
            <option value="pending">Pending</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>

      <!-- Due Date -->
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Due Date</label>
        <input
          v-model="formData.due_date"
          type="date"
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
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
        >
          Cancel
        </button>
        <button
          @click="handleSubmit"
          :disabled="submitting || !formData.title.trim()"
          class="flex-1 px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          :class="isEditing ? 'bg-blue-600 hover:bg-blue-700' : 'bg-emerald-600 hover:bg-emerald-700'"
        >
          <span v-if="submitting">{{ isEditing ? 'Updating...' : 'Creating...' }}</span>
          <span v-else>{{ isEditing ? 'Update Todo' : 'Create Todo' }}</span>
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
  todo: { type: Object, default: null },
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'close', 'submit'])

const formData = ref({
  title: '',
  description: '',
  priority: 'medium',
  due_date: '',
  status: 'pending'
})

// Watch for todo changes (when editing)
watch(() => props.todo, (newTodo) => {
  if (newTodo && props.isEditing) {
    formData.value = {
      title: newTodo.title || '',
      description: newTodo.description || '',
      priority: newTodo.priority || 'medium',
      due_date: newTodo.due_date ? newTodo.due_date.split('T')[0] : '',
      status: newTodo.status || 'pending'
    }
  } else if (!props.isEditing) {
    // Reset form for new todo
    formData.value = {
      title: '',
      description: '',
      priority: 'medium',
      due_date: '',
      status: 'pending'
    }
  }
}, { immediate: true })

const handleSubmit = () => {
  if (!formData.value.title.trim()) return
  emit('submit', { ...formData.value })
}
</script>
