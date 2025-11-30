<template>
  <header class="app-header">
    <div class="header-content">
      <div class="header-left">
        <h1>{{ title }}</h1>
        <p class="greeting">{{ greeting }}</p>
      </div>
      <div class="header-right">
        <div class="user-info" v-if="user">
          <span class="user-name">{{ user.name }}</span>
          <button @click="handleLogout" class="logout-button">Logout</button>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  greeting: {
    type: String,
    required: true,
  },
  user: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['logout'])

const handleLogout = () => {
  emit('logout')
}
</script>

<style scoped>
.app-header {
  background-color: #ffffff;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  padding: 1rem 2rem;
  margin-bottom: 1.5rem;
}

.header-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: 1200px;
  margin: 0 auto;
}

.header-left h1 {
  margin: 0;
  font-size: 1.5rem;
  color: #2c3e50;
}

.greeting {
  margin: 0.25rem 0 0 0;
  color: #7f8c8d;
  font-size: 0.9rem;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.user-name {
  font-weight: 500;
  color: #2c3e50;
}

.logout-button {
  padding: 0.5rem 1rem;
  background-color: #f5f7fa;
  color: #e74c3c;
  border: 1px solid #e74c3c;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.logout-button:hover {
  background-color: #e74c3c;
  color: white;
}
</style>
