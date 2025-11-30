<template>
  <div class="relative">
    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
      <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
      </svg>
    </div>
    
    <input 
      :value="modelValue"
      type="text" 
      :placeholder="placeholder"
      :class="inputClasses"
      @input="handleInput"
      @keydown.esc="handleClear"
    >
    
    <div v-if="modelValue" class="absolute inset-y-0 right-0 flex items-center pr-3">
      <button 
        @click="handleClear" 
        class="text-slate-400 hover:text-slate-600 transition-colors p-1 hover:bg-slate-100 rounded"
        type="button"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: 'Search...'
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  }
})

const emit = defineEmits(['update:modelValue', 'clear'])

const inputClasses = computed(() => {
  const base = 'w-full pl-10 pr-10 text-slate-700 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white focus:outline-none transition-all placeholder:text-slate-400'
  
  const sizes = {
    sm: 'py-1.5 text-xs',
    md: 'py-2.5 text-sm',
    lg: 'py-3 text-base'
  }
  
  return [base, sizes[props.size]].join(' ')
})

const handleInput = (event) => {
  emit('update:modelValue', event.target.value)
}

const handleClear = () => {
  emit('update:modelValue', '')
  emit('clear')
}
</script>
