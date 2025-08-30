<template>
  <div class="flex flex-col h-screen bg-white rounded-lg shadow-md overflow-hidden">
    <!-- Header -->
    <div class="sticky top-0 z-30 flex-shrink-0 px-6 py-4 border-b border-gray-200 bg-gray-50">
      <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-gray-800">Router Management</h2>
        <div class="flex gap-2">
          <button
            @click="openCreateOverlay"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-green-500 rounded-md hover:bg-green-600 transition-colors"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            Add Router
          </button>
          <button
            @click="fetchRouters"
            :disabled="loading"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-500 rounded-md hover:bg-blue-600 disabled:opacity-70 disabled:cursor-not-allowed transition-colors"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
            </svg>
            Refresh
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center flex-1 gap-4 p-8">
      <div class="w-10 h-10 border-4 border-gray-200 border-t-blue-500 rounded-full animate-spin"></div>
      <p class="text-gray-600">Loading routers...</p>
    </div>

    <!-- Error -->
    <div v-else-if="listError" class="flex flex-col items-center justify-center flex-1 gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ listError }}</p>
      <button
        @click="fetchRouters"
        class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors"
      >
        Retry
      </button>
    </div>

    <!-- Content -->
    <div v-else class="flex-1 overflow-hidden relative">
      <!-- Overlay Component -->
      <Overlay
        :show-details-overlay="showDetailsOverlay"
        :show-form-overlay="showFormOverlay"
        :current-router="currentRouter"
        :is-editing="isEditing"
        :form-data="formData"
        :current-step="currentStep"
        :steps="steps"
        :form-submitting="formSubmitting"
        :form-message="formMessage"
        :form-submitted="formSubmitted"
        :config-loading="configLoading"
        :connectivity-verified="connectivityVerified"
        :available-interfaces="availableInterfaces"
        :configuration-progress="configurationProgress"
        :loading="loading"
        :form-error="formError"
        :status-badge-class="statusBadgeClass"
        :format-timestamp="formatTimestamp"
        @close-details="closeDetails"
        @close-form="closeFormOverlay"
        @next-step="nextStep"
        @previous-step="previousStep"
        @verify-connectivity="verifyConnectivity"
        @copy-script="copyToClipboard"
        @update-interfaces="updateInterfaceAssignments"
        @apply-configs="applyConfigurations"
        @retry="fetchRouters"
        @update-form-data="updateFormData"
        @submit-form="handleFormSubmit"
        @save-service-configuration="generateConfigs"
      />

      <!-- Update Overlay Component -->
      <UpdateOverlay
        :show-update-overlay="showUpdateOverlay"
        :selected-router="selectedRouter"
        :form-data="formData"
        :form-submitting="formSubmitting"
        :form-message="formMessage"
        :form-submitted="formSubmitted"
        :config-token="formData.config_token"
        :config-loading="configLoading"
        :error="formError"
        :format-timestamp="formatTimestamp"
        @close-update="closeUpdateOverlay"
        @generate-configs="generateConfigs"
        @copy-token="copyToClipboard"
        @update-router="handleFormSubmit"
        @retry="fetchRouters"
      />

      <div v-if="routers.length" class="p-6 space-y-4 h-full flex flex-col">
        <!-- Table Header -->
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 z-10">
              <tr class="text-left text-gray-500">
                <th class="p-3 font-medium">Name</th>
                <th class="p-3 font-medium">IP Address</th>
                <th class="p-3 font-medium">Status</th>
                <th class="p-3 font-medium">Last Updated</th>
                <th class="p-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
          </table>
        </div>

        <!-- Scrollable Table Body -->
        <div class="overflow-auto max-h-[550px]">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-200">
              <tr v-for="router in routers" :key="router.id" class="hover:bg-gray-50 transition-colors">
                <td class="p-3 font-medium">{{ router.name }}</td>
                <td class="p-3 font-mono">{{ router.ip_address }}</td>
                <td class="p-3">
                  <span :class="statusBadgeClass(router.status)" class="inline-block px-2 py-1 text-xs font-medium capitalize rounded">
                    {{ router.status }}
                  </span>
                </td>
                <td class="p-3 whitespace-nowrap text-gray-500">
                  {{ formatTimestamp(router.updated_at) }}
                </td>
                <td class="p-3 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <button
                      @click="openDetails(router)"
                      class="px-3 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors flex items-center gap-1"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                      View
                    </button>
                    <button
                      @click="openEditOverlay(router)"
                      class="px-3 py-1 text-xs font-medium text-white bg-blue-500 rounded-md hover:bg-blue-600 transition-colors flex items-center gap-1"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                      Edit
                    </button>
                    <button
                      @click="handleReprovision(router)"
                      class="px-3 py-1 text-xs font-medium text-white bg-purple-500 rounded-md hover:bg-purple-600 transition-colors flex items-center gap-1"
                      :disabled="formSubmitting"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                      </svg>
                      Reprovision
                    </button>
                    <button
                      @click="handleDeleteRouter(router)"
                      class="px-3 py-1 text-xs font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors flex items-center gap-1"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="flex flex-col items-center justify-center flex-1 gap-4 p-8 text-gray-400">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <p class="text-lg">No routers configured</p>
        <button
          @click="openCreateOverlay"
          class="px-4 py-2 text-sm font-medium text-white bg-green-500 rounded-md hover:bg-green-600 transition-colors"
        >
          Add Your First Router
        </button>
      </div>
    </div>

    <!-- Footer -->
    <div class="sticky bottom-0 flex-shrink-0 px-6 py-3 text-xs text-gray-500 border-t border-gray-200 bg-gray-50">
      <div class="flex items-center justify-between">
        <span>© {{ new Date().getFullYear() }} Network Management System</span>
        <span
          :class="{
            'text-green-600 bg-green-100': !loading,
            'text-yellow-600 bg-yellow-100': loading,
          }"
          class="px-2 py-1 rounded-full text-xs font-medium"
        >
          {{ loading ? 'Loading...' : 'Ready' }}
        </span>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, watch } from 'vue';
import { useRouters } from '@/composables/useRouters';
import Overlay from './routers/Overlay.vue';
import UpdateOverlay from './routers/UpdateOverlay.vue';

export default {
  components: {
    Overlay,
    UpdateOverlay
  },
  setup() {
    const {
      routers,
      loading,
      listError,
      formError,
      showFormOverlay,
      showDetailsOverlay,
      showUpdateOverlay,
      currentRouter,
      isEditing,
      selectedRouter,
      formData,
      formSubmitting,
      currentStep,
      steps,
      configLoading,
      connectivityVerified,
      availableInterfaces,
      configurationProgress,
      formMessage,
      formSubmitted,
      fetchRouters,
      verifyConnectivity,
      addRouter,
      editRouter,
      updateRouter,
      deleteRouter,
      generateConfigs,
      applyConfigurations,
      formatTimestamp,
      statusBadgeClass,
      openCreateOverlay,
      openEditOverlay,
      openDetails,
      closeDetails,
      closeFormOverlay,
      closeUpdateOverlay,
      nextStep,
      previousStep,
      copyToClipboard,
      updateInterfaceAssignments,
      updateFormData
    } = useRouters();

    const handleDeleteRouter = async (router) => {
      if (confirm(`Are you sure you want to delete router ${router.name}?`)) {
        try {
          await deleteRouter(router.id);
          formMessage.value = { text: `Router ${router.name} deleted successfully`, type: 'success' };
        } catch (err) {
          formMessage.value = { text: `Failed to delete router: ${err.message}`, type: 'error' };
        }
      }
    };

    const handleFormSubmit = async () => {
      formSubmitting.value = true;
      try {
        if (isEditing.value) {
          await updateRouter();
        } else {
          await addRouter();
        }
      } catch (err) {
        console.error('Form submission error:', err);
      } finally {
        formSubmitting.value = false;
      }
    };

    const handleReprovision = async (router) => {
      if (confirm(`Are you sure you want to reprovision router ${router.name}? This will reapply the existing configurations.`)) {
        // Populate formData with the router's details
        formData.value = {
          ...router,
          port: router.port || null,
          username: router.username || '',
          password: router.password || '',
          location: router.location || '',
          interface_assignments: router.interface_assignments || [],
          interface_services: router.interface_services || {},
          configurations: router.configurations || {},
          connectivity_script: router.connectivity_script || '',
          service_script: router.service_script || '',
          config_token: router.config_token || ''
        };
        console.log('handleReprovision called, formData:', JSON.parse(JSON.stringify(formData.value)));
        try {
          await applyConfigurations();
          formMessage.value = { text: `Router ${router.name} reprovisioned successfully`, type: 'success' };
        } catch (err) {
          formMessage.value = { text: `Failed to reprovision router: ${err.message}`, type: 'error' };
          console.error('handleReprovision error:', err.message, err.response?.data);
        }
      }
    };

    watch(showDetailsOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : '';
    });

    watch(showFormOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : '';
    });

    watch(showUpdateOverlay, (open) => {
      document.body.style.overflow = open ? 'hidden' : '';
    });

    return {
      routers,
      loading,
      listError,
      formError,
      showFormOverlay,
      showDetailsOverlay,
      showUpdateOverlay,
      currentRouter,
      isEditing,
      selectedRouter,
      formData,
      formSubmitting,
      currentStep,
      steps,
      configLoading,
      connectivityVerified,
      availableInterfaces,
      configurationProgress,
      formMessage,
      formSubmitted,
      fetchRouters,
      verifyConnectivity,
      handleFormSubmit,
      handleDeleteRouter,
      handleReprovision,
      generateConfigs,
      applyConfigurations,
      formatTimestamp,
      statusBadgeClass,
      openCreateOverlay,
      openEditOverlay,
      openDetails,
      closeDetails,
      closeFormOverlay,
      closeUpdateOverlay,
      nextStep,
      previousStep,
      copyToClipboard,
      updateInterfaceAssignments,
      updateFormData
    };
  },
  mounted() {
    this.fetchRouters();
  }
};
</script>