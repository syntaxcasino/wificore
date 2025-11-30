<template>
  <div class="chart-container">
    <h3>Active Users</h3>
    <p class="subtitle">Average of users</p>

    <div class="user-stats">
      <p>
        Average ({{ userStats.averageUsers }}) users, Peak ({{ userStats.peakUsers }}) users this
        week
      </p>
    </div>

    <div class="chart">
      <div class="y-axis">
        <div v-for="(tick, index) in yAxisTicks" :key="index" class="tick">
          {{ tick }}
        </div>
      </div>

      <div class="bars-container">
        <div v-for="(value, index) in activeUsersData.data" :key="index" class="bar-wrapper">
          <div class="bar" :style="{ height: `${(value / maxValue) * 100}%` }"></div>
          <div class="x-axis-label">{{ activeUsersData.labels[index] }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useDashboardData } from '@/composables/useDashboardData'

const { dashboardData } = useDashboardData()

const activeUsersData = computed(() => dashboardData.value.activeUsers)
const userStats = computed(() => dashboardData.value.userStats)

const maxValue = computed(() => Math.max(...activeUsersData.value.data) * 1.1)

const yAxisTicks = computed(() => {
  const ticks = []
  for (let i = 20; i >= 0; i -= 2) {
    ticks.push(i)
  }
  return ticks
})
</script>

<style scoped>
.chart-container {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.subtitle {
  margin: 0 0 5px 0;
  color: #888;
  font-size: 0.9rem;
}

.user-stats {
  margin-bottom: 20px;
}

.user-stats p {
  margin: 0;
  font-size: 0.9rem;
  color: #666;
}

.chart {
  display: flex;
  height: 250px;
}

.y-axis {
  display: flex;
  flex-direction: column-reverse;
  justify-content: space-between;
  margin-right: 10px;
  font-size: 0.8rem;
  color: #666;
}

.bars-container {
  display: flex;
  flex-grow: 1;
  align-items: flex-end;
  gap: 15px;
}

.bar-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-grow: 1;
  height: 100%;
}

.bar {
  width: 100%;
  background-color: #50b83c;
  border-radius: 4px 4px 0 0;
  transition: height 0.3s ease;
}

.x-axis-label {
  margin-top: 5px;
  font-size: 0.8rem;
  color: #666;
}
</style>
