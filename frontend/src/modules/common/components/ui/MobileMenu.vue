<template>
  <nav class="sm:hidden bg-blue-700 px-4 py-2" aria-label="Mobile navigation">
    <router-link
      v-for="link in links"
      :key="link.path"
      :to="link.path"
      class="block py-2 hover:underline transition-colors duration-200"
      active-class="font-bold"
      @click="$emit('close')"
    >
      {{ link.name }}
    </router-link>
  </nav>
</template>

<script setup>
defineProps({
  links: {
    type: Array,
    required: true,
    validator: (links) => links.every((link) => 'path' in link && 'name' in link),
  },
})

defineEmits(['close'])
</script>
