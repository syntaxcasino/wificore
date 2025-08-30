<template>
  <AuthLayout>
    <div class="login-container">
      <h1>Welcome to Tradinet Solutions</h1>
      <LoginForm @submit="handleLogin" />
      <p v-if="error" class="error-message">{{ error }}</p>
    </div>
  </AuthLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import LoginForm from '@/components/auth/LoginForm.vue'

const router = useRouter()
const { login } = useAuth()
const error = ref('')

const handleLogin = async (credentials) => {
  const result = await login(credentials)

  if (result.success) {
    router.push('/dashboard')
  } else {
    error.value = result.error
    router.push('/dashboard')
  }
}
</script>

<style scoped>
.login-container {
  max-width: 400px;
  margin: 0 auto;
  padding: 2rem;
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

h1 {
  text-align: center;
  margin-bottom: 1.5rem;
  color: #2c3e50;
}

.error-message {
  color: #e74c3c;
  text-align: center;
  margin-top: 1rem;
}
</style>
