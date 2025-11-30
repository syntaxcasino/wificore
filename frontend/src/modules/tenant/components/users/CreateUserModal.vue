<template>
  <BaseModal v-model="isOpen" title="Create New User" size="lg" @close="handleClose">
    <form @submit.prevent="handleSubmit">
      <div class="space-y-4">
        <!-- User Type -->
        <BaseSelect
          v-model="form.type"
          label="User Type"
          required
          :error="errors.type"
        >
          <option value="hotspot">Hotspot User</option>
          <option value="pppoe">PPPoE User</option>
        </BaseSelect>

        <!-- Name -->
        <BaseInput
          v-model="form.name"
          label="Full Name"
          placeholder="Enter full name"
          required
          :error="errors.name"
        />

        <!-- Username -->
        <BaseInput
          v-model="form.username"
          label="Username"
          placeholder="Enter username"
          required
          :error="errors.username"
          hint="Used for login authentication"
        />

        <!-- Email -->
        <BaseInput
          v-model="form.email"
          type="email"
          label="Email Address"
          placeholder="user@example.com"
          :error="errors.email"
        />

        <!-- Phone -->
        <BaseInput
          v-model="form.phone"
          type="tel"
          label="Phone Number"
          placeholder="+254712345678"
          :error="errors.phone"
        />

        <!-- Password -->
        <BaseInput
          v-model="form.password"
          type="password"
          label="Password"
          placeholder="Enter password"
          required
          :error="errors.password"
          hint="Minimum 8 characters"
        />

        <!-- Confirm Password -->
        <BaseInput
          v-model="form.password_confirmation"
          type="password"
          label="Confirm Password"
          placeholder="Re-enter password"
          required
          :error="errors.password_confirmation"
        />

        <!-- Package Selection -->
        <BaseSelect
          v-model="form.package_id"
          label="Package"
          :error="errors.package_id"
        >
          <option value="">No Package (Manual)</option>
          <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">
            {{ pkg.name }} - {{ pkg.price }} KES
          </option>
        </BaseSelect>

        <!-- Status -->
        <BaseSelect
          v-model="form.status"
          label="Status"
          required
          :error="errors.status"
        >
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </BaseSelect>
      </div>

      <!-- Alert for errors -->
      <BaseAlert
        v-if="submitError"
        variant="danger"
        :message="submitError"
        class="mt-4"
        dismissible
        @close="submitError = ''"
      />
    </form>

    <template #footer>
      <BaseButton @click="handleClose" variant="secondary" :disabled="submitting">
        Cancel
      </BaseButton>
      <BaseButton @click="handleSubmit" variant="primary" :loading="submitting">
        Create User
      </BaseButton>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import { useUsers } from '@/modules/tenant/composables/data/useUsers'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'

const props = defineProps({
  modelValue: Boolean
})

const emit = defineEmits(['update:modelValue', 'success'])

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

// Get packages for selection
const { packages, fetchPackages } = usePackages()
const { createUser } = useUsers()

// Form state
const form = ref({
  type: 'hotspot',
  name: '',
  username: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  package_id: '',
  status: 'active'
})

const errors = ref({})
const submitting = ref(false)
const submitError = ref('')

// Methods
const validateForm = () => {
  errors.value = {}
  
  if (!form.value.name) {
    errors.value.name = 'Name is required'
  }
  
  if (!form.value.username) {
    errors.value.username = 'Username is required'
  }
  
  if (form.value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) {
    errors.value.email = 'Invalid email format'
  }
  
  if (!form.value.password) {
    errors.value.password = 'Password is required'
  } else if (form.value.password.length < 8) {
    errors.value.password = 'Password must be at least 8 characters'
  }
  
  if (form.value.password !== form.value.password_confirmation) {
    errors.value.password_confirmation = 'Passwords do not match'
  }
  
  return Object.keys(errors.value).length === 0
}

const handleSubmit = async () => {
  if (!validateForm()) {
    return
  }
  
  submitting.value = true
  submitError.value = ''
  
  try {
    await createUser(form.value)
    emit('success')
    resetForm()
  } catch (err) {
    submitError.value = err.response?.data?.message || 'Failed to create user'
    if (err.response?.data?.errors) {
      errors.value = err.response.data.errors
    }
  } finally {
    submitting.value = false
  }
}

const handleClose = () => {
  if (!submitting.value) {
    isOpen.value = false
    resetForm()
  }
}

const resetForm = () => {
  form.value = {
    type: 'hotspot',
    name: '',
    username: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    package_id: '',
    status: 'active'
  }
  errors.value = {}
  submitError.value = ''
}

// Fetch packages when modal opens
watch(isOpen, (value) => {
  if (value && packages.value.length === 0) {
    fetchPackages()
  }
})
</script>
