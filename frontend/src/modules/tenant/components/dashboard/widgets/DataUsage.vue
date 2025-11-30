<template>
  <div class="data-usage-container">
    <h3>Data Usage</h3>
    <p>Data usage trend for PPMG and Hotspot users.</p>
    <!-- In a real app, you would implement a chart or visualization here -->
    <div class="placeholder-chart">
      <p>Data usage visualization would go here</p>
    </div>
  </div>
</template>

<script setup></script>

<style scoped>
.data-usage-container {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.data-usage-container p {
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
