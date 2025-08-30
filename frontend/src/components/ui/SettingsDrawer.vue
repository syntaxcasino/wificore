<template>
  <div v-if="show" class="fixed inset-0 z-50 bg-black bg-opacity-40 flex justify-end">
    <div class="w-64 bg-white p-4 h-full shadow-lg">
      <h2 class="text-lg font-bold mb-4 text-gray-800">Settings</h2>
      <p class="text-sm text-gray-600">No configurable settings available at the moment.</p>
      <button @click="$emit('close')" class="mt-6 px-3 py-1 bg-gray-600 text-white rounded">
        Close
      </button>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  show: Boolean,
})
</script>
