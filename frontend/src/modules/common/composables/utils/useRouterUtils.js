/**
 * Router utility functions composable
 * Provides helper functions for router data formatting and calculations
 */
export function useRouterUtils() {
  /**
   * Get status dot CSS class based on router status
   */
  const getStatusDotClass = (status) => {
    const classes = {
      online: 'bg-emerald-500 animate-pulse',
      offline: 'bg-slate-400',
      provisioning: 'bg-amber-500 animate-pulse',
      error: 'bg-rose-500',
    }
    return classes[status] || 'bg-slate-300'
  }

  /**
   * Get CPU usage color class based on load percentage
   */
  const getCpuColorClass = (cpuLoad) => {
    if (cpuLoad >= 90) return 'bg-rose-500'
    if (cpuLoad >= 70) return 'bg-amber-500'
    if (cpuLoad >= 50) return 'bg-yellow-500'
    return 'bg-emerald-500'
  }

  /**
   * Get memory usage color class based on usage percentage
   */
  const getMemoryColorClass = (memoryUsage) => {
    if (memoryUsage >= 90) return 'bg-rose-500'
    if (memoryUsage >= 70) return 'bg-amber-500'
    if (memoryUsage >= 50) return 'bg-yellow-500'
    return 'bg-emerald-500'
  }

  /**
   * Get disk usage color class based on usage percentage
   */
  const getDiskColorClass = (diskUsage) => {
    if (diskUsage >= 90) return 'bg-rose-500'
    if (diskUsage >= 80) return 'bg-amber-500'
    if (diskUsage >= 70) return 'bg-yellow-500'
    return 'bg-emerald-500'
  }

  /**
   * Parse memory/disk value with unit conversion to bytes
   */
  const parseMemoryValue = (value) => {
    if (!value || value === 'N/A') return null

    // If it's already a number, return it
    if (typeof value === 'number') return value

    // Convert string to number, handling units
    const str = String(value).trim()
    const match = str.match(/^([\d.]+)\s*([KMGT]i?B)?$/i)

    if (!match) return parseFloat(str)

    const num = parseFloat(match[1])
    const unit = (match[2] || '').toUpperCase()

    // Convert to bytes for consistent comparison
    const multipliers = {
      B: 1,
      KB: 1024,
      KIB: 1024,
      MB: 1024 * 1024,
      MIB: 1024 * 1024,
      GB: 1024 * 1024 * 1024,
      GIB: 1024 * 1024 * 1024,
      TB: 1024 * 1024 * 1024 * 1024,
      TIB: 1024 * 1024 * 1024 * 1024,
    }

    return num * (multipliers[unit] || 1)
  }

  /**
   * Calculate memory usage percentage from router live data
   */
  const getMemoryUsage = (router) => {
    if (!router.live_data) return null

    // MikroTik returns free-memory and total-memory
    const freeMemory = router.live_data.free_memory || router.live_data['free-memory']
    const totalMemory = router.live_data.total_memory || router.live_data['total-memory']

    if (!freeMemory || !totalMemory) return null

    // Parse memory values with proper unit conversion
    const parsedFree = parseMemoryValue(freeMemory)
    const parsedTotal = parseMemoryValue(totalMemory)

    if (!parsedFree || !parsedTotal || parsedTotal === 0) {
      return null
    }

    // MikroTik gives us FREE memory, so we need to calculate USED memory
    // Used Memory = Total - Free
    const usedMemory = parsedTotal - parsedFree
    const usagePercentage = Math.round((usedMemory / parsedTotal) * 100)

    // Ensure percentage is between 0 and 100
    return Math.max(0, Math.min(100, usagePercentage))
  }

  /**
   * Calculate disk usage percentage from router live data
   */
  const getDiskUsage = (router) => {
    if (!router.live_data) return null

    // MikroTik returns free-hdd-space and total-hdd-space
    const freeHdd = router.live_data.free_hdd_space || router.live_data['free-hdd-space']
    const totalHdd = router.live_data.total_hdd_space || router.live_data['total-hdd-space']

    if (!freeHdd || !totalHdd) return null

    // Parse disk values with proper unit conversion
    const parsedFree = parseMemoryValue(freeHdd)
    const parsedTotal = parseMemoryValue(totalHdd)

    if (!parsedFree || !parsedTotal || parsedTotal === 0) {
      return null
    }

    // MikroTik gives us FREE disk space, so we need to calculate USED space
    // Used Disk = Total - Free
    const usedDisk = parsedTotal - parsedFree
    const usagePercentage = Math.round((usedDisk / parsedTotal) * 100)

    // Ensure percentage is between 0 and 100
    return Math.max(0, Math.min(100, usagePercentage))
  }

  /**
   * Get router model from various possible data sources
   */
  const getRouterModel = (router) => {
    // Priority 1: Check live/live_data for board_name (most accurate)
    const liveModel = router.live?.board_name || router.live_data?.board_name
    if (liveModel) return liveModel

    // Priority 2: Check root level model field
    if (router.model) return router.model

    // Priority 3: Check alternative field names
    return (
      router.board_name ||
      router['board-name'] ||
      router.boardName ||
      router.device_model ||
      router['device-model'] ||
      null
    )
  }

  /**
   * Format router model name for display
   */
  const formatModel = (model) => {
    if (!model) return '—'

    // Clean up common verbose model strings
    let formatted = model
      .replace(/innotek GmbH VirtualBox/gi, 'VirtualBox')
      .replace(/CHR\s+/gi, 'CHR ')
      .trim()

    // Extract meaningful parts for common MikroTik models
    // Examples: "RB750Gr3", "CCR1009-7G-1C-1S+", "hEX"
    const mikrotikMatch = formatted.match(/\b(RB\w+|CCR\w+|hEX\w*|CRS\w+|CSS\w+)\b/i)
    if (mikrotikMatch) {
      return mikrotikMatch[1]
    }

    // For CHR VirtualBox, return "CHR VirtualBox"
    if (formatted.toLowerCase().includes('chr') && formatted.toLowerCase().includes('virtualbox')) {
      return 'CHR VirtualBox'
    }

    // If still too long, truncate intelligently
    if (formatted.length > 15) {
      // Try to keep first meaningful word
      const words = formatted.split(/\s+/)
      if (words.length > 1) {
        return words.slice(0, 2).join(' ')
      }
      return formatted.substring(0, 12) + '...'
    }

    return formatted
  }

  /**
   * Get number of connected users from router data
   */
  const getConnectedUsers = (router) => {
    // Check live data for active connections
    if (router.live?.active_connections !== undefined) {
      return router.live.active_connections
    }
    if (router.live_data?.active_connections !== undefined) {
      return router.live_data.active_connections
    }
    return null
  }

  /**
   * Format timestamp as relative time (e.g., "5m ago", "2h ago")
   */
  const formatTimeAgo = (dateString) => {
    if (!dateString) return 'Never'
    const date = new Date(dateString)
    const now = new Date()
    const diffInSeconds = Math.floor((now - date) / 1000)

    if (diffInSeconds < 60) return 'Just now'
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
    return `${Math.floor(diffInSeconds / 86400)}d ago`
  }

  return {
    getStatusDotClass,
    getCpuColorClass,
    getMemoryColorClass,
    getDiskColorClass,
    parseMemoryValue,
    getMemoryUsage,
    getDiskUsage,
    getRouterModel,
    formatModel,
    getConnectedUsers,
    formatTimeAgo,
  }
}

export default useRouterUtils
