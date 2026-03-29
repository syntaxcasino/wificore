<template>
  <div class="service-slider-container">
    <label v-if="label" class="slider-label">{{ label }}</label>
    <div class="service-slider">
      <div class="slider-track">
        <div 
          class="slider-indicator" 
          :style="indicatorStyle"
        ></div>
      </div>
      <div class="slider-options">
        <button
          v-for="option in options"
          :key="option.value"
          type="button"
          :class="['slider-option', { active: modelValue === option.value }]"
          :disabled="disabled"
          @click="selectOption(option.value)"
        >
          <span class="option-icon">{{ option.icon }}</span>
          <span class="option-label">{{ option.label }}</span>
          <span v-if="option.badge" class="option-badge">{{ option.badge }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: 'none'
  },
  label: {
    type: String,
    default: ''
  },
  disabled: {
    type: Boolean,
    default: false
  },
  options: {
    type: Array,
    default: () => [
      { value: 'none', label: 'None', icon: '⭕' },
      { value: 'hotspot', label: 'Hotspot', icon: '📶' },
      { value: 'pppoe', label: 'PPPoE', icon: '🔌' },
      { value: 'hybrid', label: 'Hybrid', icon: '🔀', badge: 'VLAN' }
    ]
  }
})

const emit = defineEmits(['update:modelValue', 'change'])

const selectOption = (value) => {
  if (!props.disabled && value !== props.modelValue) {
    emit('update:modelValue', value)
    emit('change', value)
  }
}

const indicatorStyle = computed(() => {
  const index = props.options.findIndex(opt => opt.value === props.modelValue)
  if (index === -1) return { left: '0%', width: '0%' }
  
  const width = 100 / props.options.length
  const left = width * index
  
  return {
    left: `${left}%`,
    width: `${width}%`
  }
})
</script>

<style scoped>
.service-slider-container {
  width: 100%;
}

.slider-label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
  margin-bottom: 0.5rem;
}

.service-slider {
  position: relative;
  width: 100%;
}

.slider-track {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 100%;
  background: #f3f4f6;
  border-radius: 0.5rem;
  overflow: hidden;
  pointer-events: none;
}

.slider-indicator {
  position: absolute;
  top: 0;
  height: 100%;
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
  border-radius: 0.5rem;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
}

.slider-options {
  position: relative;
  display: flex;
  gap: 0;
  padding: 0.25rem;
  background: #f3f4f6;
  border-radius: 0.5rem;
}

.slider-option {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 0.75rem 0.5rem;
  background: transparent;
  border: none;
  border-radius: 0.375rem;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  z-index: 1;
  min-height: 4rem;
}

.slider-option:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.slider-option:not(:disabled):hover {
  background: rgba(255, 255, 255, 0.5);
}

.slider-option.active {
  color: white;
}

.slider-option.active .option-icon {
  transform: scale(1.1);
}

.option-icon {
  font-size: 1.5rem;
  margin-bottom: 0.25rem;
  transition: transform 0.2s;
}

.option-label {
  font-size: 0.75rem;
  font-weight: 500;
  text-align: center;
  line-height: 1.2;
}

.option-badge {
  position: absolute;
  top: 0.25rem;
  right: 0.25rem;
  font-size: 0.625rem;
  font-weight: 600;
  padding: 0.125rem 0.375rem;
  background: #10b981;
  color: white;
  border-radius: 0.25rem;
  text-transform: uppercase;
}

.slider-option.active .option-badge {
  background: rgba(255, 255, 255, 0.3);
}

/* Responsive adjustments */
@media (max-width: 640px) {
  .slider-option {
    padding: 0.5rem 0.25rem;
    min-height: 3.5rem;
  }
  
  .option-icon {
    font-size: 1.25rem;
  }
  
  .option-label {
    font-size: 0.625rem;
  }
}

/* Animation for smooth transitions */
@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.service-slider-container {
  animation: slideIn 0.3s ease-out;
}
</style>
