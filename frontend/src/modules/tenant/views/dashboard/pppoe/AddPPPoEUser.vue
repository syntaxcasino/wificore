<template>
  <PageContainer>
    <PageHeader
      title="Add PPPoE User"
      subtitle="Create a PPPoE customer account"
      icon="Network"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton variant="secondary" @click="$router.push('/dashboard/pppoe/users')">
          Back to Users
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="max-w-2xl">
        <BaseCard>
          <form class="space-y-4" @submit.prevent="handleSubmit">
            <BaseAlert
              v-if="formError"
              variant="danger"
              :title="formError"
              dismissible
            />

            <div class="grid grid-cols-1 gap-4">
              <BaseInput
                v-model="form.username"
                label="Username"
                placeholder="e.g. john.doe"
                :error="fieldErrors.username"
                required
                autocomplete="off"
              />

              <BaseSelect
                v-model="form.router_id"
                label="Router"
                placeholder="Select a router"
                :error="fieldErrors.router_id"
                required
              >
                <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
              </BaseSelect>

              <BaseSelect
                v-model="form.package_id"
                label="Package"
                placeholder="Select a PPPoE package"
                :error="fieldErrors.package_id"
                required
              >
                <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
              </BaseSelect>

              <BaseInput
                v-model="form.simultaneous_use"
                type="number"
                label="Simultaneous Use"
                placeholder="1"
                :error="fieldErrors.simultaneous_use"
                required
              />
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
              <BaseButton
                variant="secondary"
                type="button"
                :disabled="submitting"
                @click="$router.push('/dashboard/pppoe/users')"
              >
                Cancel
              </BaseButton>
              <BaseButton variant="primary" type="submit" :loading="submitting">
                Create User
              </BaseButton>
            </div>
          </form>
        </BaseCard>
      </div>
    </PageContent>

    <BaseModal v-model="showPasswordModal" title="PPPoE User Created" :closeOnBackdrop="false">
      <div class="space-y-4">
        <div>
          <div class="text-sm font-medium text-slate-700">Username</div>
          <div class="mt-1 text-sm text-slate-900 font-mono">{{ createdUser?.username }}</div>
        </div>

        <div>
          <div class="text-sm font-medium text-slate-700">Generated Password</div>
          <div class="mt-1 flex items-center gap-2">
            <div class="flex-1 text-sm text-slate-900 font-mono bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              {{ generatedPassword }}
            </div>
            <BaseButton variant="secondary" size="sm" @click="copyPassword">
              Copy
            </BaseButton>
          </div>
          <div class="mt-2 text-xs text-slate-500">This password is shown only once. Store it securely.</div>
        </div>

        <div class="flex items-center justify-end gap-3">
          <BaseButton variant="primary" @click="finish">
            Done
          </BaseButton>
        </div>
      </div>
    </BaseModal>
  </PageContainer>
</template>

<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'

import { usePppoeUsers } from '@/modules/tenant/composables/data/usePppoeUsers'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'

const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'PPPoE', to: '/dashboard/pppoe/sessions' },
  { label: 'Users', to: '/dashboard/pppoe/users' },
  { label: 'Add User' },
])

const { createUser } = usePppoeUsers()
const { packages, fetchPackages } = usePackages()
const { routers, fetchRouters } = useRouters()

const pppoePackages = computed(() => (packages.value || []).filter((p) => p?.type === 'pppoe'))

const form = reactive({
  username: '',
  package_id: '',
  router_id: '',
  simultaneous_use: 1,
})

const submitting = ref(false)
const formError = ref('')
const fieldErrors = reactive({
  username: '',
  package_id: '',
  router_id: '',
  simultaneous_use: '',
})

const showPasswordModal = ref(false)
const generatedPassword = ref('')
const createdUser = ref(null)

const resetErrors = () => {
  formError.value = ''
  fieldErrors.username = ''
  fieldErrors.package_id = ''
  fieldErrors.router_id = ''
  fieldErrors.simultaneous_use = ''
}

const handleSubmit = async () => {
  resetErrors()
  submitting.value = true

  try {
    const payload = {
      username: String(form.username || '').trim(),
      package_id: form.package_id,
      router_id: form.router_id,
      simultaneous_use: Number(form.simultaneous_use || 1),
    }

    const { user, generatedPassword: password } = await createUser(payload)
    createdUser.value = user
    generatedPassword.value = password || ''
    showPasswordModal.value = true
  } catch (err) {
    const status = err.response?.status
    const message = err.response?.data?.message || err.response?.data?.error || 'Failed to create PPPoE user'

    if (status === 422) {
      const errors = err.response?.data?.errors || {}
      fieldErrors.username = errors.username?.[0] || ''
      fieldErrors.package_id = errors.package_id?.[0] || ''
      fieldErrors.router_id = errors.router_id?.[0] || ''
      fieldErrors.simultaneous_use = errors.simultaneous_use?.[0] || ''
      formError.value = message
    } else {
      formError.value = message
    }
  } finally {
    submitting.value = false
  }
}

const copyPassword = async () => {
  if (!generatedPassword.value) return
  try {
    await navigator.clipboard.writeText(generatedPassword.value)
  } catch (e) {
    formError.value = 'Failed to copy password'
  }
}

const finish = () => {
  showPasswordModal.value = false
  generatedPassword.value = ''
  createdUser.value = null
  window.location.href = '/dashboard/pppoe/users'
}

onMounted(() => {
  fetchPackages()
  fetchRouters()
})
</script>
