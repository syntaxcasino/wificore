<template>
  <div class="not-found">
    <h1 class="text-3xl font-bold text-center mt-12">404 - Page Not Found</h1>
    <p class="text-center mt-4">
      The page you're looking for doesn't exist.
      <router-link to="/" class="text-blue-600 hover:underline">Return home</router-link>
    </p>
  </div>
</template>

<script>
export default {
  name: 'NotFoundView',
}
</script>

<style scoped>
.not-found {
  min-height: 60vh;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
</style>
