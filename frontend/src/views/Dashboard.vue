<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Dashboard Overview</h2>
    <p class="mb-4">Welcome to your dashboard! This is the main overview page.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-white p-4 rounded shadow">
        <h3 class="text-lg font-semibold">Quick Stats</h3>
        <p>Active Users: 150</p>
        <p>Total Payments: $5,000</p>
      </div>
      <div class="bg-white p-4 rounded shadow">
        <h3 class="text-lg font-semibold">Recent Activity</h3>
        <p>Logged in at 05:02 PM EAT, July 28, 2025</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

// Simulate fetching data on mount (for demonstration)
onMounted(() => {
  console.log('Dashboard mounted')
})
</script>

<style scoped>
/* Add any custom styles if needed */
</style>
