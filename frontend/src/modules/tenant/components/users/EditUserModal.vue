<template>
  <BaseModal v-model="isOpen" title="Edit User" size="lg" @close="handleClose">
    <form @submit.prevent="handleSubmit" v-if="user">
      <div class="space-y-4">
        <!-- Name -->
        <BaseInput
          v-model="form.name"
          label="Full Name"
          placeholder="Enter full name"
          required
          :error="errors.name"
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
          <option value="blocked">Blocked</option>
        </BaseSelect>

        <!-- Change Password (Optional) -->
        <div class="border-t border-slate-200 pt-4 mt-4">
          <h4 class="text-sm font-medium text-slate-900 mb-3">Change Password (Optional)</h4>
          
          <BaseInput
            v-model="form.password"
            type="password"
            label="New Password"
            placeholder="Leave blank to keep current password"
            :error="errors.password"
            hint="Minimum 8 characters"
          />

          <BaseInput
            v-if="form.password"
            v-model="form.password_confirmation"
            type="password"
            label="Confirm New Password"
            placeholder="Re-enter new password"
            :error="errors.password_confirmation"
            class="mt-3"
          />
        </div>
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
        Update User
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
  modelValue: Boolean,
  user: Object
})

const emit = defineEmits(['update:modelValue', 'success'])

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

// Get packages for selection
const { packages, fetchPackages } = usePackages()
const { updateUser } = useUsers()

// Form state
const form = ref({
  name: '',
  email: '',
  phone: '',
  package_id: '',
  status: 'active',
  password: '',
  password_confirmation: ''
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
  
  if (form.value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) {
    errors.value.email = 'Invalid email format'
  }
  
  if (form.value.password) {
    if (form.value.password.length < 8) {
      errors.value.password = 'Password must be at least 8 characters'
    }
    
    if (form.value.password !== form.value.password_confirmation) {
      errors.value.password_confirmation = 'Passwords do not match'
    }
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
    const updateData = { ...form.value }
    
    // Remove password fields if not changing password
    if (!updateData.password) {
      delete updateData.password
      delete updateData.password_confirmation
    }
    
    await updateUser(props.user.id, updateData)
    emit('success')
    handleClose()
  } catch (err) {
    submitError.value = err.response?.data?.message || 'Failed to update user'
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
  errors.value = {}
  submitError.value = ''
  form.value.password = ''
  form.value.password_confirmation = ''
}

// Load user data when modal opens
watch([isOpen, () => props.user], ([open, user]) => {
  if (open && user) {
    form.value = {
      name: user.name || '',
      email: user.email || '',
      phone: user.phone || '',
      package_id: user.package_id || '',
      status: user.status || 'active',
      password: '',
      password_confirmation: ''
    }
    
    // Fetch packages if not loaded
    if (packages.value.length === 0) {
      fetchPackages()
    }
  }
}, { immediate: true })
</script>
