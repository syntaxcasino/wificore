import { ref, watchEffect, onMounted } from 'vue'

const isDark = ref(false)

export function useTheme() {
  const toggleTheme = () => {
    isDark.value = !isDark.value
    applyTheme()
  }

  const applyTheme = () => {
    const root = document.documentElement
    if (isDark.value) {
      root.classList.add('dark')
    } else {
      root.classList.remove('dark')
    }
  }

  onMounted(() => {
    // Optional: Load theme from localStorage
    isDark.value = localStorage.theme === 'dark'
    applyTheme()
  })

  watchEffect(() => {
    localStorage.theme = isDark.value ? 'dark' : 'light'
  })

  return { isDark, toggleTheme }
}
