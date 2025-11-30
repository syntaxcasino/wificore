import { ref, computed, watch } from 'vue'

/**
 * Composable for handling data filtering
 */
export function useFilters(data, initialFilters = {}) {
  const filters = ref({ ...initialFilters })
  const searchQuery = ref('')

  /**
   * Apply filters to the data
   */
  const filteredData = computed(() => {
    let result = data.value || []

    // Apply search query
    if (searchQuery.value) {
      const query = searchQuery.value.toLowerCase()
      result = result.filter(item => {
        return Object.values(item).some(value => {
          if (value === null || value === undefined) return false
          return String(value).toLowerCase().includes(query)
        })
      })
    }

    // Apply custom filters
    Object.entries(filters.value).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) {
        result = result.filter(item => {
          if (Array.isArray(value)) {
            return value.includes(item[key])
          }
          return item[key] === value
        })
      }
    })

    return result
  })

  /**
   * Clear all filters
   */
  const clearFilters = () => {
    filters.value = { ...initialFilters }
    searchQuery.value = ''
  }

  /**
   * Check if any filters are active
   */
  const hasActiveFilters = computed(() => {
    const hasFilters = Object.values(filters.value).some(v => v !== '' && v !== null && v !== undefined)
    const hasSearch = searchQuery.value !== ''
    return hasFilters || hasSearch
  })

  /**
   * Set a specific filter
   */
  const setFilter = (key, value) => {
    filters.value[key] = value
  }

  /**
   * Reset a specific filter
   */
  const resetFilter = (key) => {
    filters.value[key] = initialFilters[key] || ''
  }

  return {
    filters,
    searchQuery,
    filteredData,
    hasActiveFilters,
    clearFilters,
    setFilter,
    resetFilter
  }
}
