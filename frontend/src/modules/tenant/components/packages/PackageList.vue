<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <LoadingSpinner />
    </div>

    <div v-else-if="error" class="text-center py-8">
      <ErrorMessage :message="error" @retry="$emit('retry')" />
    </div>

    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">
      <PackageCard
        v-for="pkg in packages"
        :key="pkg.id"
        :pkg="pkg"
        :selected="selectedPackage === pkg.id"
        @select="$emit('select', pkg.id)"
      />
    </div>
  </div>
</template>

<script setup>
import PackageCard from '@/modules/tenant/components/packages/PackageCard.vue'
import LoadingSpinner from '@/modules/common/components/ui/LoadingSpinner.vue'
import ErrorMessage from '@/modules/common/components/ui/ErrorMessage.vue'

defineProps({
  packages: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: null },
  selectedPackage: { type: Number, default: null },
})

defineEmits(['select', 'retry'])
</script>
