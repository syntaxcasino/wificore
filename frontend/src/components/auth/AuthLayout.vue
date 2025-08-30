<template>
  <div class="auth-layout">
    <div class="auth-container">
      <div class="auth-card">
        <div class="auth-brand">
          <h1>Tradinet Solutions</h1>
          <p>Dashboard Management System</p>
        </div>
        <slot></slot>
      </div>
    </div>
  </div>
</template>

<script setup>
// This component doesn't need any specific logic,
// it's just a layout wrapper for auth pages
</script>

<style scoped>
.auth-layout {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
  padding: 2rem;
}

.auth-container {
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
}

.auth-card {
  background: white;
  border-radius: 8px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  overflow: hidden;
  max-width: 400px;
  margin: 0 auto;
}

.auth-brand {
  padding: 2rem;
  text-align: center;
  background-color: #4a90e2;
  color: white;
}

.auth-brand h1 {
  margin: 0;
  font-size: 1.8rem;
}

.auth-brand p {
  margin: 0.5rem 0 0 0;
  opacity: 0.9;
  font-size: 0.9rem;
}
</style>
