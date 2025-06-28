import { ref } from 'vue';
import axios from 'axios';

const loading = ref(false);
const paymentStatus = ref(null);
const error = ref(null);

export default function usePayment() {
  const initiatePayment = async ({ package: selectedPackage, phoneNumber, macAddress }) => {
    loading.value = true;
    error.value = null;
    paymentStatus.value = null;

    try {
      const response = await axios.post('/api/payments/initiate', {
        package_id: selectedPackage.id,
        phone_number: phoneNumber,
        mac_address: macAddress,
      });

      const data = response.data;

      if (data.success) {
        paymentStatus.value = {
          type: 'success',
          message: data.message || 'STK push sent successfully. Complete the payment on your phone.',
          transactionId: data.transaction_id || null,
        };

        return { success: true, data };
      } else {
        paymentStatus.value = {
          type: 'error',
          message: data.message || 'Payment initiation failed.',
        };

        return { success: false };
      }
    } catch (err) {
      const errMsg =
        err.response?.data?.message ||
        err.message ||
        'Unexpected error occurred.';

      error.value = errMsg;

      paymentStatus.value = {
        type: 'error',
        message: errMsg,
      };

      return { success: false };
    } finally {
      loading.value = false;
    }
  };

  return {
    loading,
    error,
    paymentStatus,
    initiatePayment,
  };
}
