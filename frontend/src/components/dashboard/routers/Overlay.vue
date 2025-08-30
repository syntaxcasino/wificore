<template>
  <div class="font-sans antialiased text-gray-900">
    <!-- Loading State -->
    <div v-if="loading" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-gray-800 p-6 rounded-xl shadow-2xl w-80">
        <div class="flex flex-col items-center">
          <div class="w-12 h-12 border-4 border-gray-700 border-t-blue-400 rounded-full animate-spin mb-4"></div>
          <p class="text-gray-300 font-medium">Loading routers...</p>
          <p class="text-gray-500 text-sm mt-1">Please wait while we fetch your devices</p>
        </div>
      </div>
    </div>
    
    <!-- Error State -->
    <div v-else-if="formError" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-gray-800 p-6 rounded-xl shadow-2xl w-80">
        <div class="flex flex-col items-center text-center">
          <div class="w-12 h-12 bg-red-500/20 rounded-full flex items-center justify-center mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <h3 class="text-lg font-medium text-gray-100 mb-1">Connection Error</h3>
          <p class="text-gray-400 text-sm mb-6">{{ formError }}</p>
          <button 
            @click="$emit('retry')" 
            class="w-full py-2 px-4 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors duration-200 flex items-center justify-center"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Retry Connection
          </button>
        </div>
      </div>
    </div>
    
    <!-- Details Overlay -->
    <transition
      v-if="showDetailsOverlay"
      enter-active-class="transition-transform duration-300 ease-out"
      enter-from-class="translate-x-full"
      enter-to-class="translate-x-0"
      leave-active-class="transition-transform duration-300 ease-in"
      leave-from-class="translate-x-0"
      leave-to-class="translate-x-full"
    >
      <div class="fixed inset-y-0 right-0 z-50 w-full sm:w-96 bg-gray-800 shadow-xl border-l border-gray-700 flex flex-col">
        <!-- Details content remains the same -->
      </div>
    </transition>

    <!-- Form Overlay -->
    <transition
      v-if="showFormOverlay"
      enter-active-class="transition-transform duration-300 ease-out"
      enter-from-class="translate-x-full"
      enter-to-class="translate-x-0"
      leave-active-class="transition-transform duration-300 ease-in"
      leave-from-class="translate-x-0"
      leave-to-class="translate-x-full"
    >
      <div class="fixed inset-y-0 right-0 z-50 w-full sm:w-1/2 lg:w-1/3 bg-gray-800 shadow-xl border-l border-gray-700 flex flex-col">
        <div class="flex items-center justify-between p-5 border-b border-gray-700 bg-gray-900">
          <h3 class="text-lg font-semibold text-gray-100">
            {{ isEditing ? 'Edit Router' : 'New Router' }}
          </h3>
          <button 
            @click="$emit('close-form')" 
            class="p-1.5 rounded-lg hover:bg-gray-700 transition-colors text-gray-400 hover:text-gray-200"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
        
        <div class="p-5 overflow-y-auto flex-1">
          <div v-if="formSubmitting" class="flex flex-col items-center justify-center h-full gap-4">
            <div class="w-12 h-12 border-4 border-gray-700 border-t-blue-400 rounded-full animate-spin"></div>
            <p class="text-gray-400">
              {{ isEditing ? 'Updating router...' : 'Configuring router...' }}
            </p>
          </div>
          
          <div v-else>
            <!-- Form Message -->
            <div v-if="formMessage.text" class="mb-5 p-3 rounded-lg" :class="{
              'bg-green-900/50 text-green-400': formMessage.type === 'success',
              'bg-red-900/50 text-red-400': formMessage.type === 'error'
            }">
              <div class="flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path v-if="formMessage.type === 'success'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  <path v-if="formMessage.type === 'error'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ formMessage.text }}</span>
              </div>
            </div>

            <!-- Step Navigation -->
            <div class="mb-6">
              <div class="flex justify-between items-center">
                <div v-for="(step, index) in steps" :key="index" class="flex-1 flex flex-col items-center">
                  <div class="relative">
                    <div
                      class="w-8 h-8 rounded-full flex items-center justify-center relative z-10"
                      :class="{
                        'bg-blue-500 text-white': currentStep === index + 1,
                        'bg-gray-700 text-gray-400': currentStep !== index + 1,
                      }"
                    >
                      {{ index + 1 }}
                    </div>
                    <div 
                      v-if="index < steps.length - 1"
                      class="absolute top-1/2 left-full w-8 h-0.5 transform -translate-y-1/2"
                      :class="{
                        'bg-blue-500': currentStep > index + 1,
                        'bg-gray-700': currentStep <= index + 1
                      }"
                    ></div>
                  </div>
                  <p 
                    class="mt-2 text-xs font-medium text-center"
                    :class="{
                      'text-blue-400': currentStep === index + 1,
                      'text-gray-500': currentStep !== index + 1
                    }"
                  >
                    {{ step }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Form Content -->
            <form @submit.prevent="handleSubmit">
              <!-- Step 1: Router Name -->
              <div v-if="currentStep === 1" class="space-y-5">
                <div>
                  <label class="block text-sm font-medium text-gray-400 mb-2">Router Name</label>
                  <input
                    v-model.trim="localFormData.name"
                    type="text"
                    required
                    @input="updateFormData"
                    class="block w-full px-4 py-2.5 bg-gray-700 border border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-gray-100 placeholder-gray-500"
                    placeholder="e.g., GGN-HSP-01"
                  />
                  <p v-if="formSubmitted && !localFormData.name" class="mt-1 text-sm text-red-400">
                    Router name is required
                  </p>
                </div>
                
                <div class="pt-4">
                  <label class="block text-sm font-medium text-gray-400 mb-2">Initial Configuration</label>
                  <div class="bg-gray-700/50 p-4 rounded-lg border border-gray-600">
                    <p class="text-sm text-gray-400 mb-3">This router will be configured with basic connectivity settings.</p>
                    <div class="flex items-center text-sm text-gray-300">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                      </svg>
                      Secure connection with unique token
                    </div>
                  </div>
                </div>
              </div>

              <!-- Step 2: Connectivity Script and Interface Assignment -->
              <div v-if="currentStep === 2" class="space-y-6">
                <!-- Router Info Cards -->
                <div class="grid grid-cols-2 gap-4">
                  <div class="bg-gray-700/50 p-3 rounded-lg border border-gray-600">
                    <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Model</h4>
                    <p class="text-gray-100 font-medium">{{ formData.model || 'N/A' }}</p>
                  </div>
                  <div class="bg-gray-700/50 p-3 rounded-lg border border-gray-600">
                    <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">OS Version</h4>
                    <p class="text-gray-100 font-medium">{{ formData.os_version || 'N/A' }}</p>
                  </div>
                </div>

                <!-- Connectivity Script Section -->
                <div class="bg-gray-700/30 p-4 rounded-lg border border-gray-600 shadow">
                  <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-gray-300">Connectivity Script</label>
                    <button
                      v-if="formData.connectivity_script"
                      type="button"
                      @click="$emit('copy-script', formData.connectivity_script)"
                      class="inline-flex items-center px-3 py-1 text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 transition-colors"
                    >
                      Copy
                    </button>
                  </div>
                  
                  <div class="relative">
                    <pre class="bg-gray-800 p-3 rounded-md text-xs font-mono text-gray-300 overflow-x-auto">{{ formData.connectivity_script || 'Script not generated yet. Complete Step 1 to generate the script.' }}</pre>
                  </div>
                  
                  <button
                    type="button"
                    @click="handleVerifyClick"
                    :disabled="configLoading || !formData.id || !formData.connectivity_script"
                    class="mt-4 w-full px-4 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center"
                  >
                    {{ configLoading ? 'Verifying...' : 'Verify Connectivity' }}
                  </button>
                </div>

                <!-- Interface Assignments Section -->
                <div v-if="connectivityVerified" class="bg-gray-700/30 p-5 rounded-lg border border-gray-600 shadow">
                  <h3 class="text-sm font-medium text-gray-300 mb-5">Interface Configuration</h3>
                  
                  <div v-if="availableInterfaces.length === 0" class="text-center py-4 text-gray-500">
                    No interfaces detected
                  </div>
                  
                  <div v-else class="space-y-6">
                    <!-- Service Selection Toggle -->
                    <div class="flex items-center space-x-6">
                      <div class="flex items-center">
                        <label class="relative inline-flex items-center cursor-pointer">
                          <input
                            type="checkbox"
                            v-model="localFormData.enable_pppoe"
                            class="sr-only peer"
                            @change="updateFormData"
                          />
                          <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                          <span class="ml-3 text-sm font-medium text-gray-300">PPPoE</span>
                        </label>
                      </div>
                      <div class="flex items-center">
                        <label class="relative inline-flex items-center cursor-pointer">
                          <input
                            type="checkbox"
                            v-model="localFormData.enable_hotspot"
                            class="sr-only peer"
                            @change="updateFormData"
                          />
                          <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                          <span class="ml-3 text-sm font-medium text-gray-300">Hotspot</span>
                        </label>
                      </div>
                    </div>

                    <!-- PPPoE Interface Assignment -->
                    <div v-if="localFormData.enable_pppoe" class="space-y-4 border border-blue-500/30 rounded-lg p-4">
                      <div class="flex items-center justify-between">
                        <h4 class="text-sm font-medium text-gray-300">PPPoE Interfaces</h4>
                        <span class="text-xs text-blue-400" v-if="localFormData.pppoe_interfaces?.length">
                          {{ localFormData.pppoe_interfaces.length }} selected
                        </span>
                      </div>
                      
                      <div class="space-y-3">
                        <div 
                          v-for="iface in availableInterfaces" 
                          :key="`pppoe-${iface.name}`"
                          class="flex items-center justify-between p-3 border border-gray-600 rounded-lg"
                        >
                          <div class="flex items-center">
                            <span class="text-sm text-gray-200">
                              {{ iface.name }} ({{ iface.type }})
                            </span>
                          </div>
                          <label class="relative inline-flex items-center cursor-pointer">
                            <input
                              type="checkbox"
                              :checked="isInterfaceSelected('pppoe', iface.name)"
                              @change="toggleInterfaceSelection('pppoe', iface.name)"
                              class="sr-only peer"
                            />
                            <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                          </label>
                        </div>
                      </div>

                      <!-- PPPoE Configuration -->
                      <div v-if="localFormData.pppoe_interfaces?.length" class="pl-4 border-l-2 border-blue-500/30 space-y-4 mt-4">
                        <div>
                          <label class="block text-xs font-medium text-gray-400 mb-1">PPPoE Service Name</label>
                          <input
                            v-model="localFormData.pppoe_service_name"
                            type="text"
                            placeholder="e.g., pppoe-service"
                            class="block w-full text-sm rounded-lg border-gray-600 bg-gray-700 text-gray-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2.5"
                            @input="updateFormData"
                          />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-400 mb-1">IP Pool</label>
                          <input
                            v-model="localFormData.pppoe_ip_pool"
                            type="text"
                            placeholder="e.g., 192.168.2.100-192.168.2.200"
                            class="block w-full text-sm rounded-lg border-gray-600 bg-gray-700 text-gray-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2.5"
                            @input="updateFormData"
                          />
                        </div>
                      </div>
                    </div>

                    <!-- Hotspot Interface Assignment -->
                    <div v-if="localFormData.enable_hotspot" class="space-y-4 border border-green-500/30 rounded-lg p-4">
                      <div class="flex items-center justify-between">
                        <h4 class="text-sm font-medium text-gray-300">Hotspot Interfaces</h4>
                        <span class="text-xs text-green-400" v-if="localFormData.hotspot_interfaces?.length">
                          {{ localFormData.hotspot_interfaces.length }} selected
                        </span>
                      </div>
                      
                      <div class="space-y-3">
                        <div 
                          v-for="iface in availableInterfaces" 
                          :key="`hotspot-${iface.name}`"
                          class="flex items-center justify-between p-3 border border-gray-600 rounded-lg"
                        >
                          <div class="flex items-center">
                            <span class="text-sm text-gray-200">
                              {{ iface.name }} ({{ iface.type }})
                            </span>
                          </div>
                          <label class="relative inline-flex items-center cursor-pointer">
                            <input
                              type="checkbox"
                              :checked="isInterfaceSelected('hotspot', iface.name)"
                              @change="toggleInterfaceSelection('hotspot', iface.name)"
                              class="sr-only peer"
                            />
                            <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Step 3: Service Configuration Application -->
              <div v-if="currentStep === 3" class="space-y-5">
                <div>
                  <label class="block text-sm font-medium text-gray-300 mb-2">Service Configuration Script</label>
                  <div class="relative bg-gray-700 rounded-lg border border-gray-600 overflow-hidden">
                    <pre class="p-4 text-xs font-mono text-gray-300 overflow-x-auto max-h-60">{{ formData.service_script || 'Generate service configuration to view the script.' }}</pre>
                    <button
                      v-if="formData.service_script"
                      type="button"
                      @click="$emit('copy-script', formData.service_script)"
                      class="absolute top-2 right-2 px-2.5 py-1.5 text-xs font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors flex items-center"
                    >
                      Copy
                    </button>
                  </div>
                  <p class="mt-2 text-xs text-gray-500">
                    This script will configure the selected services on your router.
                  </p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-300 mb-2">Configuration Progress</label>
                  <div class="bg-gray-700/30 p-4 rounded-lg border border-gray-600">
                    <div class="flex items-center justify-between mb-2">
                      <span class="text-xs font-medium text-gray-400">{{ configurationProgress.status }}</span>
                      <span class="text-xs font-medium text-blue-400">{{ configurationProgress.percentage }}%</span>
                    </div>
                    <div class="w-full bg-gray-700 rounded-full h-2">
                      <div 
                        class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 rounded-full" 
                        :style="{ width: configurationProgress.percentage + '%' }"
                      ></div>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
        
        <div class="sticky bottom-0 p-4 border-t border-gray-700 bg-gray-900 flex justify-between gap-3">
          <div>
            <button
              type="button"
              v-if="!formSubmitting && currentStep > 1 && currentStep < 3"
              @click="$emit('previous-step')"
              class="px-4 py-2.5 text-sm font-medium text-gray-300 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors flex items-center"
            >
              Previous
            </button>
          </div>
          <div class="flex gap-3">
            <button
              type="button"
              v-if="!formSubmitting"
              @click="$emit('close-form')"
              class="px-4 py-2.5 text-sm font-medium text-gray-300 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              type="button"
              v-if="!formSubmitting && currentStep === 1"
              @click="handleSubmit"
              class="px-4 py-2.5 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-900"
              :disabled="!localFormData.name || localFormData.name.trim() === ''"
            >
              Create Router
            </button>
            <button
              type="button"
              v-if="!formSubmitting && currentStep === 2"
              @click="handleNextStep"
              class="px-4 py-2.5 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-900"
              :disabled="!connectivityVerified || generatingScript"
            >
              <span v-if="generatingScript">Generating Script...</span>
              <span v-else>Continue</span>
            </button>
            <button
              type="button"
              v-if="!formSubmitting && currentStep === 3"
              @click="$emit('apply-configs')"
              class="px-4 py-2.5 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-900"
              :disabled="configurationProgress.status === 'Applying'"
            >
              Apply Configuration
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { reactive, watch } from 'vue';

export default {
  props: {
    showDetailsOverlay: Boolean,
    showFormOverlay: Boolean,
    currentRouter: Object,
    isEditing: Boolean,
    formData: Object,
    currentStep: Number,
    steps: Array,
    formSubmitting: Boolean,
    formMessage: Object,
    formSubmitted: Boolean,
    configLoading: Boolean,
    connectivityVerified: Boolean,
    availableInterfaces: Array,
    configurationProgress: Object,
    loading: Boolean,
    formError: String,
    statusBadgeClass: Function,
    formatTimestamp: Function,
    generatingScript: Boolean
  },
  emits: [
    'close-details',
    'close-form',
    'next-step',
    'previous-step',
    'verify-connectivity',
    'copy-script',
    'apply-configs',
    'retry',
    'update-form-data',
    'submit-form',
    'generate-configs'
  ],
  setup(props, { emit }) {
    const localFormData = reactive({
      name: props.formData.name || '',
      id: props.formData.id || null,
      ip_address: props.formData.ip_address || '',
      config_token: props.formData.config_token || '',
      enable_pppoe: props.formData.enable_pppoe || false,
      enable_hotspot: props.formData.enable_hotspot || false,
      pppoe_interfaces: props.formData.pppoe_interfaces || [],
      hotspot_interfaces: props.formData.hotspot_interfaces || [],
      pppoe_service_name: props.formData.pppoe_service_name || '',
      pppoe_ip_pool: props.formData.pppoe_ip_pool || '',
      connectivity_script: props.formData.connectivity_script || '',
      service_script: props.formData.service_script || '',
      model: props.formData.model || '',
      os_version: props.formData.os_version || '',
      last_seen: props.formData.last_seen || null,
      status: props.formData.status || 'pending'
    });

    watch(() => props.formData, (newFormData) => {
      Object.assign(localFormData, newFormData);
    }, { deep: true });

    const handleVerifyClick = () => {
      emit('verify-connectivity');
    };

    const handleSubmit = () => {
      if (!localFormData.name || localFormData.name.trim() === '') {
        emit('update-form-data', { 
          formMessage: { text: 'Router name is required', type: 'error' }
        });
        return;
      }
      
      emit('update-form-data', JSON.parse(JSON.stringify(localFormData)));
      emit('submit-form');
    };

 const handleNextStep = () => {
  // Prepare the data to send to the backend
  const payload = {
    router_id: localFormData.id,
    services: {
      pppoe: {
        enabled: localFormData.enable_pppoe,
        interfaces: localFormData.pppoe_interfaces,
        service_name: localFormData.pppoe_service_name,
        ip_pool: localFormData.pppoe_ip_pool
      },
      hotspot: {
        enabled: localFormData.enable_hotspot,
        interfaces: localFormData.hotspot_interfaces
      }
    }
  };

  // Send the data to the backend
  emit('update-form-data', JSON.parse(JSON.stringify(localFormData)));
  
  // Emit an event to notify parent component to send data to backend
  emit('save-service-configuration', payload);
  
  if (props.currentStep === 2) {
    emit('generate-configs');
  }
  
  emit('next-step');
};

    const updateFormData = () => {
      emit('update-form-data', JSON.parse(JSON.stringify(localFormData)));
    };

    const isInterfaceSelected = (serviceType, interfaceName) => {
      return localFormData[`${serviceType}_interfaces`]?.includes(interfaceName) || false;
    };

    const toggleInterfaceSelection = (serviceType, interfaceName) => {
      if (!localFormData[`${serviceType}_interfaces`]) {
        localFormData[`${serviceType}_interfaces`] = [];
      }

      const index = localFormData[`${serviceType}_interfaces`].indexOf(interfaceName);
      if (index === -1) {
        localFormData[`${serviceType}_interfaces`].push(interfaceName);
      } else {
        localFormData[`${serviceType}_interfaces`].splice(index, 1);
      }
      updateFormData();
    };

    return { 
      localFormData,
      handleVerifyClick,
      handleSubmit,
      handleNextStep,
      updateFormData,
      isInterfaceSelected,
      toggleInterfaceSelection
    };
  }
}
</script>