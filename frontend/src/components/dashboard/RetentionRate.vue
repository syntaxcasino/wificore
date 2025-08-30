<template>
  <div class="retention-container">
    <h3>Customer retention rate (6 months)</h3>
    <p>{{ retentionRate.description }}</p>
    <!-- In a real app, you would implement a chart or visualization here -->
    <div class="placeholder-chart">
      <p>Retention chart visualization would go here</p>
    </div>
  </div>
</template>

<script setup>
import { useDashboardData } from '@/composables/useDashboardData'

const { dashboardData } = useDashboardData()

const retentionRate = computed(() => dashboardData.value.retentionRate)
</script>

<style scoped>
.retention-container {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.retention-container p {
  color: #888;
  font-size: 0.9rem;
  margin: 0 0 20px 0;
}

.placeholder-chart {
  height: 200px;
  background-color: #f5f5f5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #999;
}
</style>
