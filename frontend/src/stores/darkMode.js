import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

export const useDarkModeStore = defineStore('darkMode', () => {
  const isDark = ref(
    localStorage.getItem('darkMode') === 'true'
    // Default to light mode - only dark if explicitly set by user
  )

  const apply = () => {
    if (isDark.value) {
      document.documentElement.classList.add('dark')
    } else {
      document.documentElement.classList.remove('dark')
    }
  }

  const toggle = () => {
    isDark.value = !isDark.value
  }

  watch(isDark, (val) => {
    localStorage.setItem('darkMode', String(val))
    apply()
  }, { immediate: true })

  return { isDark, toggle }
})
