<template>
  <div class="flex flex-col items-center justify-center" :class="containerClasses">
    <!-- Icon -->
    <div class="relative mb-6">
      <div v-if="!noBackground" class="absolute inset-0 bg-blue-100 rounded-full blur-3xl opacity-30"></div>
      <div class="relative" :class="iconContainerClasses">
        <component v-if="icon" :is="iconComponent" :class="iconClasses" />
        <svg v-else class="w-16 h-16 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
        </svg>
      </div>
    </div>
    
    <!-- Content -->
    <div class="space-y-2 text-center max-w-md">
      <h3 class="text-2xl font-bold text-slate-900">{{ title }}</h3>
      <p class="text-slate-600">{{ description }}</p>
    </div>
    
    <!-- Action Button -->
    <div v-if="$slots.action || actionText" class="mt-6">
      <slot name="action">
        <button
          v-if="actionText"
          @click="handleAction"
          class="px-5 py-2.5 text-sm font-medium text-white bg-gradient-to-r from-blue-600 to-indigo-600 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5 flex items-center gap-2"
        >
          <component v-if="actionIcon" :is="actionIconComponent" class="w-5 h-5" />
          {{ actionText }}
        </button>
      </slot>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import * as LucideIcons from 'lucide-vue-next'

const props = defineProps({
  title: {
    type: String,
    default: 'No Data Found'
  },
  description: {
    type: String,
    default: 'There is no data to display at the moment.'
  },
  icon: String,
  actionText: String,
  actionIcon: String,
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  },
  noBackground: Boolean
})

const emit = defineEmits(['action'])

const containerClasses = computed(() => {
  const padding = {
    sm: 'p-8',
    md: 'p-12',
    lg: 'p-16'
  }
  return padding[props.size]
})

const iconContainerClasses = computed(() => {
  if (props.noBackground) {
    return ''
  }
  return 'w-32 h-32 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl flex items-center justify-center shadow-xl'
})

const iconClasses = computed(() => {
  if (props.noBackground) {
    return 'w-16 h-16 text-slate-400'
  }
  return 'w-16 h-16 text-white'
})

const iconComponent = computed(() => {
  return props.icon ? LucideIcons[props.icon] : null
})

const actionIconComponent = computed(() => {
  return props.actionIcon ? LucideIcons[props.actionIcon] : null
})

const handleAction = () => {
  emit('action')
}
</script>
