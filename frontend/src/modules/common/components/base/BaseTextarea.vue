<template>
  <div class="w-full">
    <label v-if="label" :for="textareaId" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>
    
    <textarea
      :id="textareaId"
      :value="modelValue"
      :placeholder="placeholder"
      :disabled="disabled"
      :required="required"
      :readonly="readonly"
      :rows="rows"
      :class="textareaClasses"
      @input="handleInput"
      @blur="handleBlur"
      @focus="handleFocus"
    />
    
    <p v-if="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ hint }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  id: String,
  modelValue: [String, Number],
  label: String,
  placeholder: String,
  error: String,
  hint: String,
  required: Boolean,
  disabled: Boolean,
  readonly: Boolean,
  rows: {
    type: Number,
    default: 4
  }
})

const emit = defineEmits(['update:modelValue', 'blur', 'focus'])

const textareaId = computed(() => props.id || `textarea-${Math.random().toString(36).substr(2, 9)}`)

const textareaClasses = computed(() => {
  const base = 'block w-full rounded-lg border transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-0 text-sm resize-y'
  const padding = 'px-3 py-2'
  const state = props.error
    ? 'border-red-300 dark:border-red-600 text-red-900 dark:text-red-300 placeholder-red-300 dark:placeholder-red-500 focus:ring-red-500 focus:border-red-500'
    : 'border-slate-300 dark:border-slate-600 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-blue-500 focus:border-blue-500 dark:focus:border-blue-400'
  const disabled = props.disabled ? 'bg-slate-100 dark:bg-slate-700 cursor-not-allowed' : 'bg-white dark:bg-slate-800'
  const readonly = props.readonly ? 'bg-slate-50 dark:bg-slate-700/50' : ''
  
  return [base, padding, state, disabled, readonly].filter(Boolean).join(' ')
})

const handleInput = (event) => {
  emit('update:modelValue', event.target.value)
}

const handleBlur = (event) => {
  emit('blur', event)
}

const handleFocus = (event) => {
  emit('focus', event)
}
</script>
