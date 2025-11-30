<template>
  <form @submit.prevent="handleSubmit">
    <div class="form-group">
      <label for="username">Username</label>
      <input
        id="username"
        v-model="form.username"
        type="text"
        required
        placeholder="Enter your username"
      />
    </div>

    <div class="form-group">
      <label for="password">Password</label>
      <input
        id="password"
        v-model="form.password"
        type="password"
        required
        placeholder="Enter your password"
      />
    </div>

    <button type="submit" class="login-button">Login</button>
  </form>
</template>

<script setup>
import { reactive } from 'vue'

const emit = defineEmits(['submit'])

const form = reactive({
  username: '',
  password: '',
})

const handleSubmit = () => {
  emit('submit', {
    username: form.username,
    password: form.password,
  })
}
</script>

<style scoped>
.form-group {
  margin-bottom: 1.5rem;
}

label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

input {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 1rem;
}

.login-button {
  width: 100%;
  padding: 0.75rem;
  background-color: #4a90e2;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
  transition: background-color 0.3s;
}

.login-button:hover {
  background-color: #3a7bc8;
}
</style>
