import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { usePayment } from '../usePayments'
import axios from 'axios'

vi.mock('axios')

describe('usePayment', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  // ---------------------------------------------------------------------------
  // initiatePayment()
  // ---------------------------------------------------------------------------

  describe('initiatePayment()', () => {
    const validInput = {
      package: { id: 'pkg-uuid-001', name: 'Basic 1HR', price: 50 },
      phoneNumber: '254712345678',
      macAddress: '00:1A:2B:3C:4D:5E',
    }

    it('posts to /payments/initiate with correct payload', async () => {
      axios.post.mockResolvedValue({
        data: { success: true, transaction_id: 'ws_CO_TEST001', message: 'STK Push sent' },
      })

      const { initiatePayment } = usePayment()
      await initiatePayment(validInput)

      expect(axios.post).toHaveBeenCalledWith('/payments/initiate', {
        package_id:  'pkg-uuid-001',
        phone_number: '254712345678',
        mac_address: '00:1A:2B:3C:4D:5E',
      })
    })

    it('sets paymentStatus to success type on success', async () => {
      axios.post.mockResolvedValue({
        data: { success: true, transaction_id: 'ws_CO_TEST001', message: 'STK Push sent' },
      })

      const { initiatePayment, paymentStatus } = usePayment()
      await initiatePayment(validInput)

      expect(paymentStatus.value?.type).toBe('success')
    })

    it('includes transactionId in paymentStatus on success', async () => {
      axios.post.mockResolvedValue({
        data: { success: true, transaction_id: 'ws_CO_TEST001' },
      })

      const { initiatePayment, paymentStatus } = usePayment()
      await initiatePayment(validInput)

      expect(paymentStatus.value?.transactionId).toBe('ws_CO_TEST001')
    })

    it('uses default message when server message is absent', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { initiatePayment, paymentStatus } = usePayment()
      await initiatePayment(validInput)
      expect(paymentStatus.value?.message).toContain('STK push sent')
    })

    it('returns { success: true, data } on success', async () => {
      const mockData = { success: true, transaction_id: 'ws_CO_TEST001' }
      axios.post.mockResolvedValue({ data: mockData })

      const { initiatePayment } = usePayment()
      const result = await initiatePayment(validInput)

      expect(result.success).toBe(true)
      expect(result.data).toEqual(mockData)
    })

    it('sets paymentStatus to error type when success is false', async () => {
      axios.post.mockResolvedValue({
        data: { success: false, message: 'Package not found' },
      })

      const { initiatePayment, paymentStatus } = usePayment()
      await initiatePayment(validInput)

      expect(paymentStatus.value?.type).toBe('error')
      expect(paymentStatus.value?.message).toBe('Package not found')
    })

    it('returns { success: false } when server returns success: false', async () => {
      axios.post.mockResolvedValue({ data: { success: false } })
      const { initiatePayment } = usePayment()
      const result = await initiatePayment(validInput)
      expect(result.success).toBe(false)
    })

    it('handles axios error and sets error ref', async () => {
      axios.post.mockRejectedValue({
        response: { data: { message: 'Tenant not found' } },
      })

      const { initiatePayment, error } = usePayment()
      const result = await initiatePayment(validInput)

      expect(result.success).toBe(false)
      expect(error.value).toBe('Tenant not found')
    })

    it('handles network error with generic message', async () => {
      axios.post.mockRejectedValue(new Error('Network Error'))
      const { initiatePayment, error } = usePayment()
      await initiatePayment(validInput)
      expect(error.value).toBe('Network Error')
    })

    it('sets loading to true during fetch and false after', async () => {
      let loadingDuringCall = null
      const { initiatePayment, loading } = usePayment()

      axios.post.mockImplementation(() => {
        loadingDuringCall = loading.value
        return Promise.resolve({ data: { success: true } })
      })

      await initiatePayment(validInput)

      expect(loadingDuringCall).toBe(true)
      expect(loading.value).toBe(false)
    })

    it('resets loading to false even on error', async () => {
      axios.post.mockRejectedValue(new Error('fail'))
      const { initiatePayment, loading } = usePayment()
      await initiatePayment(validInput)
      expect(loading.value).toBe(false)
    })

    it('clears error and paymentStatus before each call', async () => {
      axios.post.mockResolvedValue({ data: { success: true } })
      const { initiatePayment, error, paymentStatus } = usePayment()

      error.value = 'old error'
      paymentStatus.value = { type: 'error', message: 'old' }

      await initiatePayment(validInput)

      // After a successful call, error should be null (was cleared at start)
      expect(error.value).toBeNull()
    })

    it('sets paymentStatus error with "Unexpected error" for unknown axios error', async () => {
      axios.post.mockRejectedValue({})  // no response, no message
      const { initiatePayment, paymentStatus } = usePayment()
      await initiatePayment(validInput)
      expect(paymentStatus.value?.type).toBe('error')
      expect(paymentStatus.value?.message).toContain('Unexpected error')
    })
  })

  // ---------------------------------------------------------------------------
  // fetchPayments()
  // ---------------------------------------------------------------------------

  describe('fetchPayments()', () => {
    it('calls GET /payments', async () => {
      axios.get.mockResolvedValue({ data: [] })
      const { fetchPayments } = usePayment()
      await fetchPayments()
      expect(axios.get).toHaveBeenCalledWith('/payments')
    })

    it('populates transactions ref with response data', async () => {
      const mockPayments = [
        { id: 'pay-001', status: 'completed', amount: 50 },
        { id: 'pay-002', status: 'pending',   amount: 100 },
      ]
      axios.get.mockResolvedValue({ data: mockPayments })

      const { fetchPayments, transactions } = usePayment()
      await fetchPayments()

      expect(transactions.value).toEqual(mockPayments)
    })

    it('sets transactions to empty array on error', async () => {
      axios.get.mockRejectedValue(new Error('Server Error'))
      const { fetchPayments, transactions } = usePayment()
      await fetchPayments()
      expect(transactions.value).toEqual([])
    })

    it('sets error ref on failure', async () => {
      axios.get.mockRejectedValue({
        response: { data: { message: 'Unauthorized' } },
      })

      const { fetchPayments, error } = usePayment()
      await fetchPayments()

      expect(error.value).toBe('Unauthorized')
    })

    it('sets loading true during fetch and false after', async () => {
      let loadingDuringFetch = null
      const { fetchPayments, loading } = usePayment()

      axios.get.mockImplementation(() => {
        loadingDuringFetch = loading.value
        return Promise.resolve({ data: [] })
      })

      await fetchPayments()

      expect(loadingDuringFetch).toBe(true)
      expect(loading.value).toBe(false)
    })

    it('resets loading to false after error', async () => {
      axios.get.mockRejectedValue(new Error('fail'))
      const { fetchPayments, loading } = usePayment()
      await fetchPayments()
      expect(loading.value).toBe(false)
    })

    it('accepts empty array response without error', async () => {
      axios.get.mockResolvedValue({ data: [] })
      const { fetchPayments, transactions, error } = usePayment()
      await fetchPayments()
      expect(transactions.value).toEqual([])
      expect(error.value).toBeNull()
    })

    it('handles null response gracefully', async () => {
      axios.get.mockResolvedValue({ data: null })
      const { fetchPayments, transactions } = usePayment()
      await fetchPayments()
      // null is falsy so || [] kicks in
      expect(transactions.value).toEqual([])
    })
  })

  // ---------------------------------------------------------------------------
  // Shared reactive state
  // ---------------------------------------------------------------------------

  describe('shared reactive state', () => {
    it('loading starts as false', () => {
      const { loading } = usePayment()
      expect(loading.value).toBe(false)
    })

    it('error starts as null', () => {
      const { error } = usePayment()
      expect(error.value).toBeNull()
    })

    it('paymentStatus starts as null', () => {
      const { paymentStatus } = usePayment()
      expect(paymentStatus.value).toBeNull()
    })

    it('transactions starts as empty array', () => {
      const { transactions } = usePayment()
      expect(transactions.value).toEqual([])
    })
  })
})
