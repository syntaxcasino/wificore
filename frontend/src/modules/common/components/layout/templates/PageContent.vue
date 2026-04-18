<template>
  <div class="flex-1 bg-white dark:bg-slate-800/50">
    <div :class="contentClasses">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  padding: {
    type: Boolean,
    default: true
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg', 'none'].includes(value)
  }
})

const contentClasses = computed(() => {
  if (!props.padding || props.size === 'none') {
    return ''
  }

  const sizes = {
    sm: 'p-3',
    md: 'p-4 md:p-6',
    lg: 'p-6 md:p-8'
  }

  return sizes[props.size]
})
</script>
