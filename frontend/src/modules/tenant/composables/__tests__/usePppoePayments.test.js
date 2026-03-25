import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { usePppoePayments } from '../usePppoePayments'
import axios from 'axios'

vi.mock('axios')

describe('usePppoePayments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  // ---------------------------------------------------------------------------
  // fetchSettings()
  // ---------------------------------------------------------------------------

  describe('fetchSettings()', () => {
    it('sets loading to true while fetching and false after', async () => {
      const composable = usePppoePayments()
      let loadingDuringFetch = null
      axios.get.mockImplementation(() => {
        loadingDuringFetch = composable.loading.value
        return Promise.resolve({ data: { success: true, has_own_paybill: false } })
      })

      await composable.fetchSettings()

      expect(loadingDuringFetch).toBe(true)
      expect(composable.loading.value).toBe(false)
    })

    it('stores response in settings ref', async () => {
      const mockData = {
        success: true,
        has_own_paybill: true,
        using_landlord_paybill: false,
        data: { business_shortcode: '123456' },
      }
      axios.get.mockResolvedValue({ data: mockData })

      const { fetchSettings, settings } = usePppoePayments()
      await fetchSettings()

      expect(settings.value).toEqual(mockData)
    })

    it('calls GET /billing/paybill/settings', async () => {
      axios.get.mockResolvedValue({ data: {} })
      const { fetchSettings } = usePppoePayments()
      await fetchSettings()
      expect(axios.get).toHaveBeenCalledWith('/billing/paybill/settings')
    })

    it('sets error ref on failure and rethrows', async () => {
      const err = { response: { data: { message: 'Unauthorized' } } }
      axios.get.mockRejectedValue(err)

      const { fetchSettings, error } = usePppoePayments()
      await expect(fetchSettings()).rejects.toBeDefined()
      expect(error.value).toBe('Unauthorized')
    })

    it('uses generic message when response has no message', async () => {
      axios.get.mockRejectedValue(new Error('Network Error'))
      const { fetchSettings, error } = usePppoePayments()
      await expect(fetchSettings()).rejects.toBeDefined()
      expect(error.value).toBe('Failed to load settings')
    })

    it('clears error before fetching', async () => {
      axios.get.mockResolvedValue({ data: {} })
      const { fetchSettings, error } = usePppoePayments()
      error.value = 'old error'
      await fetchSettings()
      expect(error.value).toBeNull()
    })
  })

  // ---------------------------------------------------------------------------
  // saveSettings()
  // ---------------------------------------------------------------------------

  describe('saveSettings()', () => {
    it('posts to /billing/paybill/settings with form data', async () => {
      const formData = { business_shortcode: '600000', environment: 'sandbox', use_landlord_paybill: false }
      axios.post.mockResolvedValue({ data: { success: true } })

      const { saveSettings } = usePppoePayments()
      await saveSettings(formData)

      expect(axios.post).toHaveBeenCalledWith('/billing/paybill/settings', formData)
    })

    it('updates settings ref with response data', async () => {
      const responseData = { success: true, data: { business_shortcode: '600000' } }
      axios.post.mockResolvedValue({ data: responseData })

      const { saveSettings, settings } = usePppoePayments()
      await saveSettings({})

      expect(settings.value).toEqual(responseData)
    })

    it('sets error and rethrows on failure', async () => {
      axios.post.mockRejectedValue({ response: { data: { message: 'Validation failed' } } })
      const { saveSettings, error } = usePppoePayments()
      await expect(saveSettings({})).rejects.toBeDefined()
      expect(error.value).toBe('Validation failed')
    })

    it('resets loading to false after error', async () => {
      axios.post.mockRejectedValue(new Error('fail'))
      const { saveSettings, loading } = usePppoePayments()
      try { await saveSettings({}) } catch (_) {}
      expect(loading.value).toBe(false)
    })
  })

  // ---------------------------------------------------------------------------
  // testConnection()
  // ---------------------------------------------------------------------------

  describe('testConnection()', () => {
    it('posts to /billing/paybill/test', async () => {
      axios.post.mockResolvedValue({ data: { success: true, message: 'Connected' } })
      const { testConnection } = usePppoePayments()
      await testConnection()
      expect(axios.post).toHaveBeenCalledWith('/billing/paybill/test')
    })

    it('returns response data on success', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { testConnection } = usePppoePayments()
      const result = await testConnection()
      expect(result.success).toBe(true)
    })

    it('throws Error with message on failure', async () => {
      axios.post.mockRejectedValue({ response: { data: { message: 'Bad credentials' } } })
      const { testConnection } = usePppoePayments()
      await expect(testConnection()).rejects.toThrow('Bad credentials')
    })
  })

  // ---------------------------------------------------------------------------
  // registerUrls()
  // ---------------------------------------------------------------------------

  describe('registerUrls()', () => {
    it('posts to /billing/paybill/register-urls', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { registerUrls } = usePppoePayments()
      await registerUrls()
      expect(axios.post).toHaveBeenCalledWith('/billing/paybill/register-urls')
    })

    it('throws on failure with error message', async () => {
      axios.post.mockRejectedValue({ response: { data: { message: 'Not allowed' } } })
      const { registerUrls } = usePppoePayments()
      await expect(registerUrls()).rejects.toThrow('Not allowed')
    })
  })

  // ---------------------------------------------------------------------------
  // useLandlordPaybill()
  // ---------------------------------------------------------------------------

  describe('useLandlordPaybill()', () => {
    it('posts to /billing/paybill/use-landlord', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { useLandlordPaybill } = usePppoePayments()
      await useLandlordPaybill()
      expect(axios.post).toHaveBeenCalledWith('/billing/paybill/use-landlord')
    })

    it('returns landlord shortcode on success', async () => {
      axios.post.mockResolvedValue({ data: { success: true, landlord_shortcode: '400200' } })
      const { useLandlordPaybill } = usePppoePayments()
      const result = await useLandlordPaybill()
      expect(result.landlord_shortcode).toBe('400200')
    })
  })

  // ---------------------------------------------------------------------------
  // activateOwnPaybill()
  // ---------------------------------------------------------------------------

  describe('activateOwnPaybill()', () => {
    it('posts to /billing/paybill/activate', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { activateOwnPaybill } = usePppoePayments()
      await activateOwnPaybill()
      expect(axios.post).toHaveBeenCalledWith('/billing/paybill/activate')
    })

    it('throws on failure', async () => {
      axios.post.mockRejectedValue({ response: { data: { message: 'Incomplete credentials' } } })
      const { activateOwnPaybill } = usePppoePayments()
      await expect(activateOwnPaybill()).rejects.toThrow('Incomplete credentials')
    })
  })

  // ---------------------------------------------------------------------------
  // getPaymentInstructions()
  // ---------------------------------------------------------------------------

  describe('getPaymentInstructions()', () => {
    it('gets instructions for given userId', async () => {
      axios.get.mockResolvedValue({
        data: { data: { shortcode: '400200', account: 'TST-P00001', amount: 500 } },
      })
      const { getPaymentInstructions } = usePppoePayments()
      const result = await getPaymentInstructions('user-uuid-123')
      expect(axios.get).toHaveBeenCalledWith('/billing/paybill/instructions/user-uuid-123')
      expect(result.shortcode).toBe('400200')
    })
  })

  // ---------------------------------------------------------------------------
  // fetchTransactions()
  // ---------------------------------------------------------------------------

  describe('fetchTransactions()', () => {
    it('calls GET /billing/paybill/transactions with page params', async () => {
      axios.get.mockResolvedValue({ data: { data: { data: [] } } })
      const { fetchTransactions } = usePppoePayments()
      await fetchTransactions(2, 10)
      expect(axios.get).toHaveBeenCalledWith('/billing/paybill/transactions', {
        params: { page: 2, per_page: 10 },
      })
    })

    it('updates transactions ref with response items', async () => {
      const items = [{ id: 'txn-1' }, { id: 'txn-2' }]
      axios.get.mockResolvedValue({ data: { data: { data: items } } })
      const { fetchTransactions, transactions } = usePppoePayments()
      await fetchTransactions()
      expect(transactions.value).toEqual(items)
    })

    it('defaults to page 1 and perPage 20', async () => {
      axios.get.mockResolvedValue({ data: { data: { data: [] } } })
      const { fetchTransactions } = usePppoePayments()
      await fetchTransactions()
      expect(axios.get).toHaveBeenCalledWith('/billing/paybill/transactions', {
        params: { page: 1, per_page: 20 },
      })
    })
  })

  // ---------------------------------------------------------------------------
  // fetchCheckLogs()
  // ---------------------------------------------------------------------------

  describe('fetchCheckLogs()', () => {
    it('calls GET /billing/paybill/logs', async () => {
      axios.get.mockResolvedValue({ data: { data: [] } })
      const { fetchCheckLogs } = usePppoePayments()
      await fetchCheckLogs()
      expect(axios.get).toHaveBeenCalledWith('/billing/paybill/logs')
    })

    it('stores logs in checkLogs ref', async () => {
      const logs = [{ id: 1, status: 'done' }]
      axios.get.mockResolvedValue({ data: { data: logs } })
      const { fetchCheckLogs, checkLogs } = usePppoePayments()
      await fetchCheckLogs()
      expect(checkLogs.value).toEqual(logs)
    })
  })

  // ---------------------------------------------------------------------------
  // triggerPaymentCheck()
  // ---------------------------------------------------------------------------

  describe('triggerPaymentCheck()', () => {
    it('posts to /billing/paybill/check-payments', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { triggerPaymentCheck } = usePppoePayments()
      await triggerPaymentCheck()
      expect(axios.post).toHaveBeenCalledWith('/billing/paybill/check-payments')
    })

    it('throws descriptive error on failure', async () => {
      axios.post.mockRejectedValue({ response: { data: { message: 'Queue full' } } })
      const { triggerPaymentCheck } = usePppoePayments()
      await expect(triggerPaymentCheck()).rejects.toThrow('Queue full')
    })
  })

  // ---------------------------------------------------------------------------
  // Computed: hasOwnPaybill, usingLandlordPaybill, landlordPaybillAvailable, activeShortcode
  // ---------------------------------------------------------------------------

  describe('computed properties', () => {
    it('hasOwnPaybill reflects settings.has_own_paybill', async () => {
      axios.get.mockResolvedValue({ data: { success: true, has_own_paybill: true } })
      const { fetchSettings, hasOwnPaybill } = usePppoePayments()
      await fetchSettings()
      expect(hasOwnPaybill.value).toBe(true)
    })

    it('hasOwnPaybill is false when settings is null', () => {
      const { hasOwnPaybill } = usePppoePayments()
      expect(hasOwnPaybill.value).toBe(false)
    })

    it('usingLandlordPaybill reflects settings.using_landlord_paybill', async () => {
      axios.get.mockResolvedValue({ data: { success: true, using_landlord_paybill: true } })
      const { fetchSettings, usingLandlordPaybill } = usePppoePayments()
      await fetchSettings()
      expect(usingLandlordPaybill.value).toBe(true)
    })

    it('activeShortcode returns landlord shortcode when using landlord', async () => {
      axios.get.mockResolvedValue({
        data: {
          success: true,
          using_landlord_paybill: true,
          landlord_shortcode: '400200',
          data: { business_shortcode: '600000' },
        },
      })
      const { fetchSettings, activeShortcode } = usePppoePayments()
      await fetchSettings()
      expect(activeShortcode.value).toBe('400200')
    })

    it('activeShortcode returns own shortcode when not using landlord', async () => {
      axios.get.mockResolvedValue({
        data: {
          success: true,
          using_landlord_paybill: false,
          landlord_shortcode: '400200',
          data: { business_shortcode: '600000' },
        },
      })
      const { fetchSettings, activeShortcode } = usePppoePayments()
      await fetchSettings()
      expect(activeShortcode.value).toBe('600000')
    })
  })

  // ---------------------------------------------------------------------------
  // setupWebSocketListeners()
  // ---------------------------------------------------------------------------

  describe('setupWebSocketListeners()', () => {
    it('returns null when window.Echo is not available', () => {
      const { setupWebSocketListeners } = usePppoePayments()
      const cleanup = setupWebSocketListeners('tenant-id-123', {})
      expect(cleanup).toBeNull()
    })

    it('subscribes to three channels when Echo is available', () => {
      const stopListening = vi.fn()
      const listen = vi.fn().mockReturnThis()
      const channel = { listen, stopListening }
      window.Echo = { private: vi.fn().mockReturnValue(channel) }

      const { setupWebSocketListeners } = usePppoePayments()
      const cleanup = setupWebSocketListeners('tenant-123', {})

      expect(window.Echo.private).toHaveBeenCalledTimes(3)
      expect(typeof cleanup).toBe('function')

      delete window.Echo
    })

    it('cleanup function calls stopListening on all channels', () => {
      const stopListening = vi.fn()
      const listen = vi.fn().mockReturnThis()
      const channel = { listen, stopListening }
      window.Echo = { private: vi.fn().mockReturnValue(channel) }

      const { setupWebSocketListeners } = usePppoePayments()
      const cleanup = setupWebSocketListeners('tenant-123', {})
      cleanup()

      expect(stopListening).toHaveBeenCalledTimes(3)

      delete window.Echo
    })

    it('calls onSettingsUpdated callback when settings event fires', () => {
      const onSettingsUpdated = vi.fn()
      const listeners = {}
      const channel = {
        listen: vi.fn((event, cb) => { listeners[event] = cb; return channel }),
        stopListening: vi.fn(),
      }
      window.Echo = { private: vi.fn().mockReturnValue(channel) }

      // Stub fetchSettings to avoid real axios call
      axios.get.mockResolvedValue({ data: {} })

      const { setupWebSocketListeners } = usePppoePayments()
      setupWebSocketListeners('tenant-123', { onSettingsUpdated })

      // Simulate the event being fired
      listeners['.paybill.settings.updated']({ shortcode: '123' })
      expect(onSettingsUpdated).toHaveBeenCalledWith({ shortcode: '123' })

      delete window.Echo
    })
  })
})
