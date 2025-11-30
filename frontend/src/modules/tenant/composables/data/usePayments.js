import { ref } from 'vue'
import axios from 'axios'

const loading = ref(false)
const paymentStatus = ref(null)
const error = ref(null)
const transactions = ref([]) // Added to store transaction data

export function usePayment() {
  const initiatePayment = async ({ package: selectedPackage, phoneNumber, macAddress }) => {
    loading.value = true
    error.value = null
    paymentStatus.value = null

    try {
      const response = await axios.post('/payments/initiate', {
        package_id: selectedPackage.id,
        phone_number: phoneNumber,
        mac_address: macAddress,
      })

      const data = response.data

      if (data.success) {
        paymentStatus.value = {
          type: 'success',
          message:
            data.message || 'STK push sent successfully. Complete the payment on your phone.',
          transactionId: data.transaction_id || null,
        }
        return { success: true, data }
      } else {
        paymentStatus.value = {
          type: 'error',
          message: data.message || 'Payment initiation failed.',
        }
        return { success: false }
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || err.message || 'Unexpected error occurred.'
      error.value = errMsg
      paymentStatus.value = { type: 'error', message: errMsg }
      return { success: false }
    } finally {
      loading.value = false
    }
  }

  const fetchPayments = async () => {
    try {
      loading.value = true
      error.value = null
      const response = await axios.get('/payments')
      transactions.value = response.data || []
    } catch (err) {
      console.error('Payment fetch error:', err)
      error.value = err.response?.data?.message || 'Failed to load payments'
      transactions.value = []
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    error,
    paymentStatus,
    transactions,
    initiatePayment,
    fetchPayments,
  }
}
