/**
 * useSSE — authenticated Server-Sent Events composable
 *
 * Wraps EventSource with:
 *  - Bearer-token auth via a one-shot /api/sse/token exchange
 *    (SSE doesn't support custom headers, so we use a short-lived token
 *     stored in a signed cookie or passed as a query param — here we use
 *     the existing Sanctum cookie / session auth via withCredentials)
 *  - Named event routing: subscribe({ EventName: handler })
 *  - Exponential backoff reconnect with max-attempts guard
 *  - Visibility-change reconnect
 *  - Full cleanup on onUnmounted
 *
 * Usage:
 *   const { subscribe, unsubscribeAll, isConnected } = useSSE('/api/sse/tenant', {
 *     channels: 'router-updates,dashboard-stats'
 *   })
 *   subscribe('RouterStatusUpdated', (data) => { ... })
 *
 * The server sends events in the format:
 *   event: RouterStatusUpdated
 *   data: { "channel": "tenant.uuid.router-updates", "data": {...}, "ts": "..." }
 */

import { ref, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
const MAX_ATTEMPTS = 10
const BASE_DELAY_MS = 2000
const MAX_DELAY_MS = 30000

export function useSSE(path, queryParams = {}) {
  const authStore = useAuthStore()
  const isConnected = ref(false)
  const error = ref(null)

  let eventSource = null
  let reconnectTimer = null
  let attempts = 0
  let intentionalClose = false

  // Map of eventName => [handlers]
  const handlers = new Map()

  // -------------------------------------------------------------------------
  // Connection
  // -------------------------------------------------------------------------

  const buildUrl = () => {
    const params = new URLSearchParams(queryParams)
    // Append token as query param — required because EventSource cannot set
    // Authorization headers. The server validates it via Sanctum token guard.
    const token = localStorage.getItem('authToken')
    if (token) {
      params.set('token', token)
    }
    const qs = params.toString()
    return `${BASE_URL}${path}${qs ? '?' + qs : ''}`
  }

  const open = () => {
    if (!authStore.isAuthenticated) return
    close(false)

    intentionalClose = false

    try {
      eventSource = new EventSource(buildUrl(), { withCredentials: true })

      eventSource.onopen = () => {
        isConnected.value = true
        error.value = null
        attempts = 0
      }

      // Route named events
      eventSource.onmessage = (e) => dispatchEvent('message', e)

      eventSource.onerror = () => {
        isConnected.value = false
        if (intentionalClose) return
        eventSource?.close()
        eventSource = null
        scheduleReconnect()
      }

      // Attach any already-registered named event listeners
      handlers.forEach((_, eventName) => attachNativeListener(eventName))

    } catch (err) {
      error.value = err.message
      scheduleReconnect()
    }
  }

  const close = (intentional = true) => {
    intentionalClose = intentional
    clearTimeout(reconnectTimer)
    reconnectTimer = null
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }
    isConnected.value = false
  }

  // -------------------------------------------------------------------------
  // Event routing
  // -------------------------------------------------------------------------

  const attachNativeListener = (eventName) => {
    if (!eventSource) return
    eventSource.addEventListener(eventName, (e) => dispatchEvent(eventName, e))
  }

  const dispatchEvent = (eventName, e) => {
    const list = handlers.get(eventName) || []
    if (!list.length) return
    try {
      const payload = JSON.parse(e.data)
      list.forEach(fn => fn(payload?.data ?? payload, payload))
    } catch {
      list.forEach(fn => fn(e.data))
    }
  }

  /**
   * Register a handler for a named SSE event.
   * @param {string} eventName  - matches the `event:` field sent by the server
   * @param {Function} handler  - called with (eventData, fullPayload)
   * @returns {Function} unsubscribe
   */
  const subscribe = (eventName, handler) => {
    if (!handlers.has(eventName)) {
      handlers.set(eventName, [])
      // If already connected, attach listener immediately
      if (eventSource) attachNativeListener(eventName)
    }
    handlers.get(eventName).push(handler)

    return () => {
      const list = handlers.get(eventName) || []
      const idx = list.indexOf(handler)
      if (idx !== -1) list.splice(idx, 1)
    }
  }

  /**
   * Subscribe to multiple events at once.
   * @param {Object} eventMap - { EventName: handler, ... }
   * @returns {Function} unsubscribe all
   */
  const subscribeMany = (eventMap) => {
    const unsubs = Object.entries(eventMap).map(([name, fn]) => subscribe(name, fn))
    return () => unsubs.forEach(fn => fn())
  }

  const unsubscribeAll = () => {
    handlers.clear()
  }

  // -------------------------------------------------------------------------
  // Reconnect
  // -------------------------------------------------------------------------

  const scheduleReconnect = () => {
    if (reconnectTimer || intentionalClose) return
    if (attempts >= MAX_ATTEMPTS) {
      error.value = 'SSE: max reconnect attempts reached'
      return
    }
    const delay = Math.min(BASE_DELAY_MS * Math.pow(2, attempts), MAX_DELAY_MS)
    attempts++
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null
      open()
    }, delay)
  }

  // -------------------------------------------------------------------------
  // Visibility change
  // -------------------------------------------------------------------------

  const onVisibilityChange = () => {
    if (document.visibilityState === 'visible' && !isConnected.value && !intentionalClose) {
      attempts = 0
      open()
    }
  }

  document.addEventListener('visibilitychange', onVisibilityChange)

  onUnmounted(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange)
    close(true)
  })

  // Auto-open
  open()

  return {
    isConnected,
    error,
    subscribe,
    subscribeMany,
    unsubscribeAll,
    open,
    close,
  }
}
