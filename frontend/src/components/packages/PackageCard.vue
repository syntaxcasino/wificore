<!-- components/packages/PackageCard.vue -->
<template>
  <div
    class="border-2 rounded-xl p-6 transition-all duration-200 cursor-pointer"
    :class="{
      'border-blue-500 shadow-lg scale-[1.02]': selected,
      'border-gray-200 hover:border-blue-300 hover:shadow-md': !selected,
    }"
    @click="$emit('select')"
  >
    <div class="flex justify-between items-start">
      <h3 class="font-bold text-xl text-gray-800">{{ pkg.name }}</h3>
      <span v-if="selected" class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full">
        Selected
      </span>
    </div>
    <p class="text-gray-600 mt-2">{{ pkg.description }}</p>
    <div class="mt-4">
      <p class="text-2xl font-bold text-blue-600">KSH {{ pkg.price }}</p>
      <p class="text-sm text-gray-500">
        {{ pkg.duration_hours }} hour{{ pkg.duration_hours !== 1 ? 's' : '' }}
      </p>
    </div>
  </div>
</template>

<script setup>
defineProps({
  pkg: {
    type: Object,
    required: true,
  },
  selected: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['select'])
</script>
