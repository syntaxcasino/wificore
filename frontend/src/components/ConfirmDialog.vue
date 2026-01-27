<template>
  <BaseModal v-model="isOpen" :closable="false" :closeOnBackdrop="false" size="sm">
    <div class="space-y-4">
      <div>
        <div class="text-base font-semibold text-slate-900">{{ title }}</div>
        <div v-if="message" class="mt-1 text-sm text-slate-600">{{ message }}</div>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <BaseButton variant="secondary" @click="cancel">
          {{ cancelText }}
        </BaseButton>
        <BaseButton :variant="variant" @click="confirm">
          {{ confirmText }}
        </BaseButton>
      </div>
    </div>
  </BaseModal>
</template>

<script setup>
import { storeToRefs } from 'pinia'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()
const { isOpen, title, message, confirmText, cancelText, variant } = storeToRefs(confirmStore)
const { confirm, cancel } = confirmStore
</script>
