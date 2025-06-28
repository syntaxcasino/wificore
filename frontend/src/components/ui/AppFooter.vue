<template>
  <footer class="bg-gray-800 text-white py-4">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm sm:text-base">
      &copy; {{ new Date().getFullYear() }} WiFi Hotspot. All rights reserved.
    </div>
  </footer>
</template>

<script>
export default {
  name: 'AppFooter',
}
</script>

<!-- Optional style section -->
<style scoped>
/* Add any footer-specific styles here */
</style>
