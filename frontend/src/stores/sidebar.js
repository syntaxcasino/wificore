// stores/sidebar.js
import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useSidebarStore = defineStore('sidebar', () => {
  const visible = ref(false)

  function toggle() {
    visible.value = !visible.value
  }

  function hide() {
    visible.value = false
  }

  function show() {
    visible.value = true
  }

  return { visible, toggle, hide, show }
})
