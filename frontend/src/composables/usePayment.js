import { ref } from 'vue'
import axios from 'axios'

const loading = ref(false)
const paymentStatus = ref(null)
const error = ref(null)

export default function usePayment() {
  const initiatePayment = async ({ package: selectedPackage, phoneNumber, macAddress }) => {
    loading.value = true
    error.value = null
    paymentStatus.value = null

    try {
      const response = await axios.post('/api/payments/initiate', {
        package_id: selectedPackage.id,
        phone_number: phoneNumber,
        mac_address: macAddress
      })

      const { data } = response

      if (data?.CheckoutRequestID) {
        paymentStatus.value = {
          type: 'success',
          message: 'STK push sent successfully. Complete the payment on your phone.',
          transactionId: data.CheckoutRequestID
        }
        return { success: true, data }
      } else {
        throw new Error('Invalid response from server.')
      }
    } catch (err) {
      error.value = 'Failed to initiate payment. Please try again.'
      paymentStatus.value = {
        type: 'error',
        message: err.response?.data?.message || 'Payment initiation failed.'
      }
      return { success: false }
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    error,
    paymentStatus,
    initiatePayment
  }
}
