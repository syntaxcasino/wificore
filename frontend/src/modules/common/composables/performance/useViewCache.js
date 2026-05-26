export const DEFAULT_VIEW_CACHE_TTL_MS = 30 * 1000

export const scheduleAfterPaint = (callback, timeout = 1000) => {
  if (typeof window === 'undefined') {
    callback()
    return
  }

  if (window.requestIdleCallback) {
    window.requestIdleCallback(callback, { timeout })
    return
  }

  window.requestAnimationFrame(() => {
    window.setTimeout(callback, 0)
  })
}

export const readSnapshot = (key, ttlMs = DEFAULT_VIEW_CACHE_TTL_MS) => {
  if (typeof window === 'undefined') return null

  try {
    const raw = window.localStorage.getItem(key)
    if (!raw) return null

    const parsed = JSON.parse(raw)
    const cachedAt = Number(parsed?.cachedAt || 0)
    if (!parsed || !Object.prototype.hasOwnProperty.call(parsed, 'data')) return null
    if (cachedAt && Date.now() - cachedAt > ttlMs) {
      window.localStorage.removeItem(key)
      return null
    }

    return parsed.data
  } catch {
    return null
  }
}

export const writeSnapshot = (key, data) => {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.setItem(key, JSON.stringify({
      cachedAt: Date.now(),
      data,
    }))
  } catch {
    // Best-effort cache only.
  }
}
