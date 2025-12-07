<template>
  <div class="space-y-4">
    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-8">
      <div class="w-8 h-8 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
    </div>

    <!-- Empty State -->
    <div v-else-if="activities.length === 0" class="text-center py-8">
      <p class="text-sm text-gray-500">No activity yet</p>
    </div>

    <!-- Activity Timeline -->
    <div v-else class="relative">
      <!-- Timeline Line -->
      <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200"></div>

      <!-- Activity Items -->
      <div
        v-for="(activity, index) in displayedActivities"
        :key="activity.id"
        class="relative pl-10 pb-6 last:pb-0"
      >
        <!-- Timeline Dot -->
        <div 
          class="absolute left-0 w-8 h-8 rounded-full flex items-center justify-center"
          :class="getActivityColor(activity.action)"
        >
          <component :is="getActivityIcon(activity.action)" class="w-4 h-4 text-white" />
        </div>

        <!-- Activity Content -->
        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
          <div class="flex items-start justify-between mb-2">
            <div>
              <p class="text-sm font-semibold text-gray-900">
                {{ activity.description || getDefaultDescription(activity.action) }}
              </p>
              <p class="text-xs text-gray-500 mt-1">
                by {{ activity.user?.name || 'Unknown' }}
              </p>
            </div>
            <span class="text-xs text-gray-500">
              {{ formatTimeAgo(activity.created_at) }}
            </span>
          </div>

          <!-- Show changes if available -->
          <div v-if="activity.old_value && activity.new_value" class="mt-3 pt-3 border-t border-gray-200">
            <p class="text-xs font-medium text-gray-600 mb-2">Changes:</p>
            <div class="space-y-1">
              <div v-for="(value, key) in getChanges(activity)" :key="key" class="text-xs">
                <span class="font-medium text-gray-700">{{ formatKey(key) }}:</span>
                <span class="text-gray-500 line-through ml-1">{{ formatValue(activity.old_value[key]) }}</span>
                <span class="text-green-600 ml-1">→ {{ formatValue(activity.new_value[key]) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Show More Button -->
      <div v-if="limit && activities.length > limit" class="text-center pt-4">
        <button
          @click="showAll = !showAll"
          class="text-sm text-blue-600 hover:text-blue-700 font-medium"
        >
          {{ showAll ? 'Show Less' : `Show ${activities.length - limit} More` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { CheckCircle2, Edit, Trash2, UserPlus, Plus } from 'lucide-vue-next'
import { useTodos } from '@/composables/useTodos'

const props = defineProps({
  todoId: {
    type: String,
    required: true
  },
  limit: {
    type: Number,
    default: null
  }
})

const { fetchActivities } = useTodos()

const activities = ref([])
const loading = ref(false)
const showAll = ref(false)

const displayedActivities = computed(() => {
  if (!props.limit || showAll.value) {
    return activities.value
  }
  return activities.value.slice(0, props.limit)
})

const getActivityIcon = (action) => {
  const icons = {
    created: Plus,
    updated: Edit,
    completed: CheckCircle2,
    assigned: UserPlus,
    deleted: Trash2
  }
  return icons[action] || Edit
}

const getActivityColor = (action) => {
  const colors = {
    created: 'bg-blue-500',
    updated: 'bg-yellow-500',
    completed: 'bg-green-500',
    assigned: 'bg-purple-500',
    deleted: 'bg-red-500'
  }
  return colors[action] || 'bg-gray-500'
}

const getDefaultDescription = (action) => {
  const descriptions = {
    created: 'Todo created',
    updated: 'Todo updated',
    completed: 'Todo marked as completed',
    assigned: 'Todo assigned',
    deleted: 'Todo deleted'
  }
  return descriptions[action] || 'Action performed'
}

const getChanges = (activity) => {
  if (!activity.old_value || !activity.new_value) return {}
  
  const changes = {}
  const keys = ['title', 'description', 'priority', 'status', 'due_date']
  
  keys.forEach(key => {
    if (activity.old_value[key] !== activity.new_value[key]) {
      changes[key] = true
    }
  })
  
  return changes
}

const formatKey = (key) => {
  return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatValue = (value) => {
  if (value === null || value === undefined) return 'None'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

const formatTimeAgo = (date) => {
  if (!date) return ''
  
  const now = new Date()
  const past = new Date(date)
  const diffInSeconds = Math.floor((now - past) / 1000)
  
  if (diffInSeconds < 60) return 'just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`
  
  return past.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const loadActivities = async () => {
  loading.value = true
  try {
    activities.value = await fetchActivities(props.todoId)
  } catch (error) {
    console.error('Failed to load activities:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadActivities()
})
</script>
