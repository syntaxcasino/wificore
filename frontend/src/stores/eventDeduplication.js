import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Event Deduplication Store
 *
 * Prevents duplicate event processing by tracking processed event IDs.
 * This is critical for real-time systems to ensure:
 * - Events from multiple tabs don't create duplicates
 * - Reconnects don't re-process already-handled events
 * - Race conditions between HTTP and WebSocket don't corrupt state
 *
 * Strategy:
 * - Track last 1000 processed event IDs with timestamps
 * - Auto-cleanup of old events after 5 minutes
 * - In-memory only (no persistence) to ensure fresh state on page load
 */
export const useEventDeduplicationStore = defineStore('eventDeduplication', () => {
  // Map of eventId -> timestamp
  const processedEvents = ref(new Map())

  const MAX_EVENTS = 1000
  const MAX_AGE_MS = 5 * 60 * 1000 // 5 minutes

  /**
   * Check if an event has already been processed
   * @param {string} eventId - Unique event identifier
   * @returns {boolean} - True if already processed
   */
  const isProcessed = (eventId) => {
    if (!eventId) return false

    cleanupOldEvents()
    return processedEvents.value.has(eventId)
  }

  /**
   * Mark an event as processed
   * @param {string} eventId - Unique event identifier
   */
  const markProcessed = (eventId) => {
    if (!eventId) return

    // If already at max, remove oldest entry
    if (processedEvents.value.size >= MAX_EVENTS) {
      const oldestKey = processedEvents.value.keys().next().value
      processedEvents.value.delete(oldestKey)
    }

    processedEvents.value.set(eventId, Date.now())
  }

  /**
   * Generate a unique event ID from event data
   * @param {string} eventType - Event type (e.g., 'user-created')
   * @param {string|number} entityId - Entity ID (e.g., user.id)
   * @param {string} timestamp - Event timestamp
   * @returns {string} - Unique event ID
   */
  const generateEventId = (eventType, entityId, timestamp) => {
    return `${eventType}:${entityId}:${timestamp}`
  }

  /**
   * Try to process an event - returns true if should process, false if duplicate
   * @param {string} eventType - Event type
   * @param {string|number} entityId - Entity ID
   * @param {string} timestamp - Event timestamp
   * @returns {boolean} - True if should process this event
   */
  const tryProcess = (eventType, entityId, timestamp) => {
    const eventId = generateEventId(eventType, entityId, timestamp)

    if (isProcessed(eventId)) {
      console.log(`[EventDedup] Skipping duplicate event: ${eventId}`)
      return false
    }

    markProcessed(eventId)
    return true
  }

  /**
   * Clean up events older than MAX_AGE_MS
   */
  const cleanupOldEvents = () => {
    const now = Date.now()
    const keysToDelete = []

    for (const [key, timestamp] of processedEvents.value.entries()) {
      if (now - timestamp > MAX_AGE_MS) {
        keysToDelete.push(key)
      }
    }

    keysToDelete.forEach(key => processedEvents.value.delete(key))

    if (keysToDelete.length > 0) {
      console.log(`[EventDedup] Cleaned up ${keysToDelete.length} old events`)
    }
  }

  /**
   * Clear all tracked events (useful for testing or manual reset)
   */
  const clearAll = () => {
    processedEvents.value.clear()
    console.log('[EventDedup] Cleared all tracked events')
  }

  /**
   * Get stats about tracked events
   */
  const getStats = () => {
    cleanupOldEvents()
    return {
      count: processedEvents.value.size,
      maxEvents: MAX_EVENTS,
      maxAgeMinutes: MAX_AGE_MS / 60000
    }
  }

  return {
    isProcessed,
    markProcessed,
    generateEventId,
    tryProcess,
    clearAll,
    getStats
  }
})
