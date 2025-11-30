<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 shadow-lg w-full max-w-md">
      <slot />
    </div>
  </div>
</template>

<script>
export default {
  name: 'Modal',
}
</script>
