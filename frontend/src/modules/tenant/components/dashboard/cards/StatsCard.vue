<template>
  <div class="stats-card">
    <h3>{{ title }}</h3>
    <div class="value">{{ value }}</div>
    <div v-if="description" class="description">{{ description }}</div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  value: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    default: '',
  },
})
</script>

<style scoped>
.stats-card {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.stats-card h3 {
  margin: 0 0 10px 0;
  font-size: 1rem;
  color: #666;
}

.stats-card .value {
  font-size: 1.5rem;
  font-weight: bold;
  margin-bottom: 5px;
}

.stats-card .description {
  font-size: 0.8rem;
  color: #888;
}
</style>
