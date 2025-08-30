<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Payments</h2>
    <div class="mb-4">
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Search transactions..."
        class="p-2 border rounded w-full md:w-1/3"
      />
    </div>
    <table class="w-full text-left border-collapse">
      <thead>
        <tr class="bg-gray-200">
          <th class="p-2 border">Transaction ID</th>
          <th class="p-2 border">Amount</th>
          <th class="p-2 border">Date</th>
          <th class="p-2 border">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="transaction in filteredTransactions" :key="transaction.id" class="border">
          <td class="p-2">{{ transaction.id }}</td>
          <td class="p-2">${{ transaction.amount.toFixed(2) }}</td>
          <td class="p-2">{{ transaction.date }}</td>
          <td
            class="p-2"
            :class="{
              'text-green-600': transaction.status === 'Completed',
              'text-red-600': transaction.status === 'Failed',
            }"
          >
            {{ transaction.status }}
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="filteredTransactions.length === 0" class="mt-4 text-gray-500">
      No transactions found.
    </p>
    <p v-if="error" class="mt-4 text-red-500">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { usePayment } from '@/composables/usePayment'

const searchQuery = ref('')
const { transactions, fetchPayments, error, loading } = usePayment()

onMounted(() => {
  fetchPayments()
})

const filteredTransactions = computed(() => {
  if (!searchQuery.value) return transactions.value
  return transactions.value.filter(
    (transaction) =>
      transaction.id.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      transaction.date.toLowerCase().includes(searchQuery.value.toLowerCase()),
  )
})
</script>

<style scoped>
/* Add custom styles if needed */
</style>
