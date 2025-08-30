<!-- components/packages/PackageCard.vue -->
<template>
  <div
    class="border-2 rounded-xl p-8 transition-all duration-300 cursor-pointer flex flex-col items-center text-center"
    :class="{
      'border-blue-500 shadow-lg scale-[1.02] bg-blue-50': selected,
      'border-gray-200 hover:border-blue-300 hover:shadow-md bg-white': !selected,
    }"
    @click="$emit('select')"
  >
    <div class="w-full flex justify-between items-center mb-4">
      <h3 class="font-bold text-xl text-gray-800 w-full text-center">{{ pkg.name }}</h3>
      <span
        v-if="selected"
        class="bg-blue-500 text-white text-xs px-3 py-1 rounded-full absolute top-3 right-3"
      >
        Selected
      </span>
    </div>

    <p class="text-gray-600 mb-6 px-2">{{ pkg.description }}</p>

    <div class="mt-auto">
      <p class="text-2xl font-bold text-blue-600">KSH {{ pkg.price }}</p>
      <p class="text-sm text-gray-500 mt-1">
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
