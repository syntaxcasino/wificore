<template>
  <div class="container mx-auto p-4 sm:p-6 lg:p-8 text-center">
    <h1 class="text-2xl sm:text-3xl font-bold mb-4">Payment Successful!</h1>
    <p class="text-lg">Thank you for your purchase. You'll receive a confirmation soon.</p>
    <router-link
      to="/"
      class="mt-4 inline-block bg-blue-500 text-white py-2 px-4 rounded-md hover:bg-blue-600"
      >Back to Home</router-link
    >
  </div>
</template>
<script>
export default { name: 'PaymentSuccess' }
</script>
