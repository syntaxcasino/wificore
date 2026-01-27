import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useConfirmStore = defineStore('confirm', () => {
  const isOpen = ref(false)
  const title = ref('Confirm Action')
  const message = ref('')
  const confirmText = ref('OK')
  const cancelText = ref('Cancel')
  const variant = ref('danger')

  let resolver = null

  const open = (options = {}) => {
    title.value = options.title || 'Confirm Action'
    message.value = options.message || ''
    confirmText.value = options.confirmText || 'OK'
    cancelText.value = options.cancelText || 'Cancel'
    variant.value = options.variant || 'danger'

    isOpen.value = true

    return new Promise((resolve) => {
      resolver = resolve
    })
  }

  const confirm = () => {
    isOpen.value = false
    if (resolver) {
      resolver(true)
      resolver = null
    }
  }

  const cancel = () => {
    isOpen.value = false
    if (resolver) {
      resolver(false)
      resolver = null
    }
  }

  return {
    isOpen,
    title,
    message,
    confirmText,
    cancelText,
    variant,
    open,
    confirm,
    cancel,
  }
})
