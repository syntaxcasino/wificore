import { ref, computed, watch } from 'vue'

/**
 * Composable for handling pagination
 */
export function usePagination(data, initialItemsPerPage = 10) {
  const currentPage = ref(1)
  const itemsPerPage = ref(initialItemsPerPage)

  /**
   * Get paginated data
   */
  const paginatedData = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage.value
    const end = start + itemsPerPage.value
    return (data.value || []).slice(start, end)
  })

  /**
   * Calculate total pages
   */
  const totalPages = computed(() => {
    return Math.ceil((data.value?.length || 0) / itemsPerPage.value)
  })

  /**
   * Get pagination info
   */
  const paginationInfo = computed(() => {
    const total = data.value?.length || 0
    const start = total === 0 ? 0 : (currentPage.value - 1) * itemsPerPage.value + 1
    const end = Math.min(start + itemsPerPage.value - 1, total)
    
    return { start, end, total }
  })

  /**
   * Go to first page
   */
  const goToFirst = () => {
    currentPage.value = 1
  }

  /**
   * Go to last page
   */
  const goToLast = () => {
    currentPage.value = totalPages.value
  }

  /**
   * Go to next page
   */
  const goToNext = () => {
    if (currentPage.value < totalPages.value) {
      currentPage.value++
    }
  }

  /**
   * Go to previous page
   */
  const goToPrevious = () => {
    if (currentPage.value > 1) {
      currentPage.value--
    }
  }

  /**
   * Go to specific page
   */
  const goToPage = (page) => {
    if (page >= 1 && page <= totalPages.value) {
      currentPage.value = page
    }
  }

  /**
   * Reset pagination when data changes
   */
  watch(() => data.value?.length, () => {
    if (currentPage.value > totalPages.value) {
      currentPage.value = Math.max(1, totalPages.value)
    }
  })

  /**
   * Reset to first page when items per page changes
   */
  watch(itemsPerPage, () => {
    currentPage.value = 1
  })

  return {
    currentPage,
    itemsPerPage,
    paginatedData,
    totalPages,
    paginationInfo,
    goToFirst,
    goToLast,
    goToNext,
    goToPrevious,
    goToPage
  }
}
