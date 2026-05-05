<template>
    <!-- Loading State -->
    <div
      v-if="loading"
      class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50"
    >
      <div class="bg-gray-800 p-6 rounded-xl shadow-2xl w-80">
        <div class="flex flex-col items-center">
          <div
            class="w-12 h-12 border-4 border-gray-700 border-t-blue-400 rounded-full animate-spin mb-4"
          ></div>
          <p class="text-gray-300 font-medium">Loading routers...</p>
          <p class="text-gray-500 text-sm mt-1">Please wait while we fetch your devices</p>
        </div>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-else-if="formError"
      class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50"
    >
      <div class="bg-gray-800 p-6 rounded-xl shadow-2xl w-80">
        <div class="flex flex-col items-center text-center">
          <div class="w-12 h-12 bg-red-500/20 rounded-full flex items-center justify-center mb-4">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="w-6 h-6 text-red-400"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
          </div>
          <h3 class="text-lg font-medium text-gray-100 mb-1">Connection Error</h3>
          <p class="text-gray-400 text-sm mb-6">{{ formError }}</p>
          <button
            @click="$emit('retry')"
            class="w-full py-2 px-4 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors duration-200 flex items-center justify-center"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="w-5 h-5 mr-2"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
              />
            </svg>
            Retry Connection
          </button>
        </div>
      </div>
    </div>

    <!-- Slide-in Overlay Panel -->
    <SlideOverlay
      :model-value="showFormOverlay"
      title="Router Provisioning"
      :subtitle="provisioningRouter ? provisioningRouter.name : 'Configure your MikroTik router'"
      icon="Wifi"
      :gradient="true"
      :badge="currentStage === 3 && provisioningProgress === 100 ? 'Complete' : `Stage ${currentStage}`"
      width="60%"
      :close-on-backdrop="!waitingForJobCompletion"
      no-padding
      @update:modelValue="val => { if (!val) $emit('close-form') }"
      @close="$emit('close-form')"
    >
      <template #status-bar>
        <!-- Progress Bar -->
        <div class="px-4 py-2 bg-white border-b border-gray-100">
          <div class="flex items-center justify-between mb-1">
            <div class="flex items-center gap-1.5">
              <div class="w-1.5 h-1.5 rounded-full animate-pulse" :class="provisioningProgress >= 100 ? 'bg-green-500' : 'bg-blue-500'"></div>
              <span class="text-[11px] font-semibold text-gray-600">{{ currentStageText }}</span>
            </div>
            <span class="text-xs font-bold" :class="provisioningProgress >= 100 ? 'text-green-600' : 'text-blue-600'">{{ Math.round(provisioningProgress) }}%</span>
          </div>
          <div class="relative w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
            <div
              class="absolute inset-0 rounded-full transition-all duration-500 ease-out"
              :class="provisioningProgress >= 100 ? 'bg-gradient-to-r from-green-400 to-emerald-500' : 'bg-gradient-to-r from-blue-500 to-indigo-600'"
              :style="{ width: provisioningProgress + '%' }"
            ></div>
          </div>
          <div class="mt-1 text-[10px] text-gray-500 text-right">{{ provisioningStatus }}</div>
        </div>
        <!-- Stage pills -->
        <div class="flex items-center px-4 py-2 gap-1 bg-gray-50 border-b border-gray-100 overflow-x-auto">
          <template v-for="(lbl, idx) in ['Setup', 'Monitoring', 'Deploy']" :key="idx">
            <div class="flex items-center gap-1 flex-shrink-0">
              <div class="w-5 h-5 rounded-full flex items-center justify-center text-[9px] font-bold ring-1.5 transition-all"
                :class="currentStage > idx+1 ? 'bg-green-500 ring-green-300 text-white' : currentStage === idx+1 ? 'bg-blue-600 ring-blue-300 text-white' : 'bg-white ring-gray-300 text-gray-400'">
                <svg v-if="currentStage > idx+1" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                <span v-else>{{ idx+1 }}</span>
              </div>
              <span class="text-[10px] font-medium" :class="currentStage > idx+1 ? 'text-green-600' : currentStage === idx+1 ? 'text-blue-600' : 'text-gray-400'">{{ lbl }}</span>
            </div>
            <div v-if="idx < 2" class="flex-1 h-px min-w-[8px] mx-0.5 transition-colors" :class="currentStage > idx+1 ? 'bg-green-300' : 'bg-gray-200'" />
          </template>
        </div>
      </template>

        <!-- Main Content - Scrollable -->
        <div class="p-4 bg-gray-50">
            <!-- Stage 1: Router Identity & Initial Config -->
            <div v-if="currentStage === 1" class="space-y-4">
              <!-- Router Name Input - Show only if config not generated -->
              <div v-if="!initialConfig" class="space-y-4">
                <div class="text-center mb-4">
                  <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                  </div>
                  <h4 class="text-base font-bold text-gray-800 mb-1">Create Router Identity</h4>
                  <p class="text-gray-600 text-xs max-w-md mx-auto">Set up your MikroTik router's identity</p>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5 flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    Router Name
                  </label>
                  <div class="relative">
                    <input
                      v-model.trim="routerName"
                      type="text"
                      required
                      class="block w-full px-3 py-2 text-sm bg-white border-2 border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900 placeholder-gray-400 transition-all"
                      placeholder="e.g., GGN-HSP-01"
                    />
                    <div v-if="routerName" class="absolute right-2 top-1/2 -translate-y-1/2">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                      </svg>
                    </div>
                  </div>
                  <p v-if="formSubmitted && !routerName" class="mt-1 text-xs text-red-600 flex items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    Router name is required
                  </p>
                </div>
              </div>

              <!-- Configuration Preview and Script - Show after creation -->
              <div v-if="initialConfig" class="space-y-4">
                <div class="text-center mb-4">
                  <div class="w-12 h-12 bg-gradient-to-br from-green-600 to-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <h4 class="text-base font-bold text-gray-800 mb-1">Router Created Successfully!</h4>
                  <p class="text-gray-600 text-xs max-w-md mx-auto">Apply the configuration to your MikroTik router</p>
                </div>

                <!-- Configuration Preview - Compact -->
                <div class="bg-white p-3 rounded-lg border border-gray-200">
                  <div class="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <h5 class="text-xs font-semibold text-gray-800">Configuration Preview</h5>
                  </div>
                  <div class="space-y-1.5 text-xs">
                    <div class="flex justify-between">
                      <span class="text-gray-600">Router Name:</span>
                      <span class="text-gray-900 font-mono">{{ routerName }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-600">Router ID:</span>
                      <span class="text-gray-900 font-mono">{{ provisioningRouter?.id || 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-600">Status:</span>
                      <span class="text-green-600 font-medium">Created</span>
                    </div>
                  </div>
                </div>

                <!-- Initial Configuration Script (Combined: Connectivity + VPN) -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 p-4 rounded-xl border-2 border-blue-200 shadow-lg">
                  <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                      <div class="p-2 bg-blue-600 rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                        </svg>
                      </div>
                      <div>
                        <h5 class="text-sm font-bold text-gray-900">Complete Configuration Script</h5>
                        <p class="text-xs text-gray-600">Connectivity + VPN (Ready to Apply)</p>
                      </div>
                    </div>
                    <button
                      @click="copyToClipboard(combinedScript)"
                      class="px-4 py-2 text-sm font-semibold rounded-lg text-white bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 transition-all shadow-md hover:shadow-lg flex items-center gap-2 transform hover:scale-105"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                      </svg>
                      Copy to Clipboard
                    </button>
                  </div>
                  
                  <!-- Script Display with Better UX -->
                  <div class="relative group">
                    <div class="absolute top-2 right-2 z-10">
                      <button
                        @click="copyToClipboard(combinedScript)"
                        class="px-2 py-1 text-xs font-medium rounded bg-blue-600 text-white hover:bg-blue-700 transition-colors opacity-0 group-hover:opacity-100"
                      >
                        📋 Copy
                      </button>
                    </div>
                    <textarea
                      readonly
                      :value="combinedScript"
                      class="w-full bg-gray-900 p-4 rounded-lg text-xs font-mono text-gray-100 border-2 border-gray-700 focus:border-blue-500 focus:outline-none resize-none cursor-text"
                      rows="20"
                      @click="$event.target.select()"
                    ></textarea>
                  </div>
                  
                  <!-- Debug info (remove after testing) -->
                  <div v-if="!combinedScript" class="mt-2 p-2 bg-red-100 border border-red-300 rounded text-xs">
                    <p class="font-bold text-red-800">Debug: Script is empty!</p>
                    <p class="text-red-700">initialConfig: {{ initialConfig ? 'exists' : 'missing' }}</p>
                    <p class="text-red-700">vpnScript: {{ vpnScript ? 'exists' : 'missing' }}</p>
                  </div>
                  
                  <div class="mt-3 bg-white rounded-lg p-3 border border-blue-200">
                    <div class="flex items-start gap-2">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <div class="text-xs text-gray-700">
                        <p class="font-semibold mb-1">How to apply:</p>
                        <ol class="list-decimal list-inside space-y-1">
                          <li>Click "Copy to Clipboard" button above</li>
                          <li>Open your MikroTik router terminal (SSH or Winbox)</li>
                          <li>Paste the entire script and press Enter</li>
                          <li>Wait for the configuration to complete</li>
                        </ol>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Stage 2: Connectivity & Interface Discovery -->
            <div v-if="currentStage === 2" class="space-y-6">
              <div class="text-center">
                <div class="w-16 h-16 bg-yellow-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                </div>
                <h4 class="text-lg font-medium text-gray-800 mb-2">Waiting for Router Connection</h4>
                <p class="text-gray-600 text-sm">The system is continuously monitoring for your router. Please ensure the initial configuration has been applied.</p>
              </div>

              <!-- Connection Status -->
              <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                  <span class="text-sm font-medium text-gray-700">Connection Status</span>
                  <div class="flex items-center space-x-2">
                    <div :class="connectionStatusClass" class="w-3 h-3 rounded-full animate-pulse"></div>
                    <span class="text-sm font-medium" :class="connectionStatusTextClass">{{ connectionStatus }}</span>
                  </div>
                </div>

                <div v-if="provisioningRouter" class="space-y-3 text-sm">
                  <div class="flex justify-between">
                    <span class="text-gray-600">Router:</span>
                    <span class="text-gray-900">{{ provisioningRouter.name }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-600">IP Address:</span>
                    <span class="text-gray-900">{{ provisioningRouter.ip_address || 'Detecting...' }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-gray-600">Status:</span>
                    <span class="text-gray-900">{{ provisioningRouter.status || 'Pending' }}</span>
                  </div>
                </div>
              </div>

              <!-- Instructions -->
              <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-start space-x-3">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div>
                    <h5 class="text-sm font-medium text-blue-900 mb-1">Next Steps</h5>
                    <ol class="text-sm text-blue-800 space-y-1 list-decimal list-inside">
                      <li>Copy the initial configuration script from Stage 1</li>
                      <li>Access your MikroTik router's terminal</li>
                      <li>Paste and run the configuration script</li>
                      <li>Wait for the connection status to change to "Connected"</li>
                    </ol>
                  </div>
                </div>
              </div>
            </div>

            <!-- Stage 3: Provisioning Complete - SSH Access -->
            <div v-if="currentStage === 3" class="space-y-4">
              <div class="text-center mb-4">
                <div class="w-16 h-16 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <h4 class="text-lg font-bold text-gray-800 mb-2">Router Connected</h4>
                <p class="text-gray-600 text-sm">Map services to interfaces, then deploy once</p>
              </div>

              <!-- Router Information -->
              <div class="grid grid-cols-2 gap-3">
                <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                  <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-1">Router Name</h4>
                  <p class="text-gray-900 font-medium">{{ provisioningRouter?.name || 'N/A' }}</p>
                </div>
                <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                  <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-1">Status</h4>
                  <p class="text-green-600 font-medium flex items-center gap-1">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    {{ provisioningRouter?.status || 'Online' }}
                  </p>
                </div>
              </div>

              <!-- SSH Access Instructions -->
              <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-lg p-4">
                <div class="flex items-start gap-3">
                  <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </div>
                  <div class="flex-1">
                    <h5 class="text-base font-bold text-gray-800 mb-2">Service Mapping</h5>
                    <p class="text-sm text-gray-700 mb-3">Select exactly one service per interface. Advanced options are applied automatically.</p>

                    <div class="bg-white rounded-lg border border-blue-200 overflow-hidden">
                      <div
                        v-for="iface in availableInterfaces"
                        :key="iface.name"
                        class="flex items-center justify-between gap-3 px-3 py-2 border-b border-blue-100 last:border-b-0"
                      >
                        <div class="min-w-0">
                          <div class="text-sm font-medium text-gray-900 truncate">{{ iface.name }}</div>
                          <div class="text-xs text-gray-500">{{ iface.type }}</div>
                        </div>

                        <div class="flex items-center gap-3">
                          <label class="inline-flex items-center gap-2">
                            <input
                              type="checkbox"
                              class="sr-only peer"
                              :disabled="mappingDeploying"
                              :checked="(serviceMappings?.[iface.name] || 'none') === 'hotspot'"
                              @change="(e) => setServiceMapping(iface.name, e.target.checked ? 'hotspot' : 'none')"
                            />
                            <div
                              class="relative w-9 h-5 rounded-full transition-colors"
                              :class="(serviceMappings?.[iface.name] || 'none') === 'hotspot' ? 'bg-blue-600' : 'bg-gray-200'"
                            >
                              <div
                                class="absolute top-[2px] left-[2px] w-4 h-4 bg-white rounded-full transition-transform"
                                :class="(serviceMappings?.[iface.name] || 'none') === 'hotspot' ? 'translate-x-4' : ''"
                              ></div>
                            </div>
                            <span class="text-[11px] font-medium text-gray-700">Hotspot</span>
                          </label>

                          <label class="inline-flex items-center gap-2">
                            <input
                              type="checkbox"
                              class="sr-only"
                              :disabled="mappingDeploying"
                              :checked="(serviceMappings?.[iface.name] || 'none') === 'pppoe'"
                              @change="(e) => setServiceMapping(iface.name, e.target.checked ? 'pppoe' : 'none')"
                            />
                            <div
                              class="relative w-9 h-5 rounded-full transition-colors"
                              :class="(serviceMappings?.[iface.name] || 'none') === 'pppoe' ? 'bg-indigo-600' : 'bg-gray-200'"
                            >
                              <div
                                class="absolute top-[2px] left-[2px] w-4 h-4 bg-white rounded-full transition-transform"
                                :class="(serviceMappings?.[iface.name] || 'none') === 'pppoe' ? 'translate-x-4' : ''"
                              ></div>
                            </div>
                            <span class="text-[11px] font-medium text-gray-700">PPPoE</span>
                          </label>

                          <label class="inline-flex items-center gap-2">
                            <input
                              type="checkbox"
                              class="sr-only"
                              :disabled="mappingDeploying"
                              :checked="(serviceMappings?.[iface.name] || 'none') === 'hybrid'"
                              @change="(e) => setServiceMapping(iface.name, e.target.checked ? 'hybrid' : 'none')"
                            />
                            <div
                              class="relative w-9 h-5 rounded-full transition-colors"
                              :class="(serviceMappings?.[iface.name] || 'none') === 'hybrid' ? 'bg-emerald-600' : 'bg-gray-200'"
                            >
                              <div
                                class="absolute top-[2px] left-[2px] w-4 h-4 bg-white rounded-full transition-transform"
                                :class="(serviceMappings?.[iface.name] || 'none') === 'hybrid' ? 'translate-x-4' : ''"
                              ></div>
                            </div>
                            <span class="text-[11px] font-medium text-gray-700">Hybrid</span>
                          </label>
                        </div>
                      </div>
                    </div>

                    <div v-if="mappingStatus" class="mt-3 text-sm text-gray-700">
                      <span class="font-semibold">Status:</span>
                      <span class="ml-1">{{ mappingStatus }}</span>
                    </div>

                    <div
                      v-if="mappingErrors && mappingErrors.length && provisioningProgress < 100"
                      class="mt-3 bg-red-50 border border-red-200 rounded-lg p-3"
                    >
                      <div class="text-xs font-semibold text-red-800 mb-1">Deployment Errors</div>
                      <div v-for="(err, idx) in mappingErrors" :key="idx" class="text-xs text-red-700">{{ err }}</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Available Interfaces Info -->
              <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h5 class="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                  Discovered Interfaces ({{ availableInterfaces.length }})
                </h5>
                <div class="grid grid-cols-2 gap-2">
                  <div v-for="iface in availableInterfaces" :key="iface.name" class="bg-gray-50 border border-gray-200 rounded p-2">
                    <div class="flex items-center justify-between">
                      <span class="text-sm font-medium text-gray-900">{{ iface.name }}</span>
                      <span class="text-xs text-gray-500 bg-white px-2 py-0.5 rounded">{{ iface.type }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Configuration Guide -->
              <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h5 class="text-sm font-bold text-gray-800 mb-3">💡 Next Steps</h5>
                <ol class="space-y-2 text-sm text-gray-700">
                  <li class="flex items-start gap-2">
                    <span class="font-bold text-blue-600">1.</span>
                    <span>Select service type per interface</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="font-bold text-blue-600">2.</span>
                    <span>Click Confirm & Deploy to apply all mappings</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="font-bold text-blue-600">3.</span>
                    <span>Wait for deployment status to complete</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="font-bold text-blue-600">4.</span>
                    <span>Monitor router status from the dashboard</span>
                  </li>
                </ol>
              </div>
            </div>

        </div>

        <!-- Real-time Logs -->
        <div class="border-t border-gray-100 bg-gray-50">
          <div class="px-4 pt-3 pb-2">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-semibold text-gray-600">Activity Logs</span>
              <button @click="clearLogs" class="text-[10px] text-blue-500 hover:text-blue-700 font-medium">Clear</button>
            </div>
            <div class="max-h-40 overflow-y-auto space-y-0.5 bg-slate-900 p-2.5 rounded-lg font-mono">
              <div v-for="(log, index) in provisioningLogs.slice().reverse()" :key="index" class="flex items-start gap-2 text-[11px]">
                <span class="text-slate-500 flex-shrink-0 w-14">{{ formatLogTime(log.timestamp) }}</span>
                <span :class="getLogLevelClass(log.level)" class="font-bold flex-shrink-0 w-12">{{ log.level.toUpperCase() }}</span>
                <span class="text-slate-300 flex-1 leading-tight">{{ log.message }}</span>
              </div>
              <div v-if="provisioningLogs.length === 0" class="text-[11px] text-slate-500 italic text-center py-2">No activity yet...</div>
            </div>
          </div>
        </div>

      <template #footer>
        <div class="flex gap-3">
            <!-- Stage 1: Initial - Create Router -->
            <button
              v-if="currentStage === 1 && !initialConfig"
              @click="$emit('close-form')"
              class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
            >
              Cancel
            </button>
            <button
              v-if="currentStage === 1 && !initialConfig"
              @click="createRouterWithConfig"
              :disabled="!routerName || formSubmitting"
              class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ formSubmitting ? 'Creating...' : 'Create Router' }}
            </button>

            <!-- Stage 1: After Config Created - Continue -->
            <button
              v-if="currentStage === 1 && initialConfig"
              @click="$emit('close-form')"
              class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
            >
              Close
            </button>
            <button
              v-if="currentStage === 1 && initialConfig"
              @click="continueToMonitoring"
              class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
            >
              Continue →
            </button>

            <!-- Stage 3: Deploying - Confirm & Deploy -->
            <button
              v-if="currentStage === 3 && provisioningProgress < 100"
              @click="$emit('close-form')"
              :disabled="mappingDeploying"
              class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              v-if="currentStage === 3 && provisioningProgress < 100"
              @click="confirmServiceMappingAndDeploy"
              :disabled="mappingDeploying"
              class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ mappingDeploying ? 'Deploying...' : 'Confirm & Deploy' }}
            </button>

            <!-- Stage 3: Complete - Add Another or Done -->
            <button
              v-if="currentStage === 3 && provisioningProgress >= 100"
              @click="resetForm; $emit('close-form')"
              class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
            >
              Add Another Router
            </button>
            <button
              v-if="currentStage === 3 && provisioningProgress >= 100"
              @click="$emit('close-form'); $emit('refresh-routers')"
              class="flex-1 px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors"
            >
              Done - View Dashboard
            </button>
        </div>
      </template>
    </SlideOverlay>
</template>

<script setup>
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useRouterProvisioning } from '@/modules/tenant/composables/useRouterProvisioning'

const props = defineProps({
  showFormOverlay: Boolean,
  loading: Boolean,
  formError: String,
})

const emit = defineEmits([
  'close-form',
  'retry',
  'copy-script',
  'refresh-routers',
])

const {
  // State
  routerName,
  formSubmitted,
  formSubmitting,
  currentStage,
  provisioningProgress,
  provisioningStatus,
  provisioningRouter,
  initialConfig,
  waitingForJobCompletion,
  provisioningLogs,
  enableHotspot,
  enablePPPoE,
  serviceScript,
  availableInterfaces,
  selectedHotspotInterfaces,
  selectedPPPoEInterfaces,
  serviceMappings,
  mappingDeploying,
  mappingStatus,
  mappingErrors,
  setServiceMapping,
  confirmServiceMappingAndDeploy,
  connectionStatus,
  deploymentFailed,
  deploymentTimedOut,
  hotspotConfig,
  pppoeConfig,
  
  // Computed
  currentStageText,
  deploymentStatusClass,
  deploymentStatus,
  connectionStatusClass,
  connectionStatusTextClass,
  combinedScript,
  vpnScript,
  
  // Methods
  createRouterWithConfig,
  continueToMonitoring,
  previousStage,
  generateServiceConfig,
  deployConfiguration,
  addLog,
  clearLogs,
  formatLogTime,
  getLogLevelClass,
  copyToClipboard,
  toggleInterfaceSelection,
  retryDeployment,
  resetForm,
} = useRouterProvisioning(props, emit)
</script>

<style scoped>
/* Shimmer keyframe — no Tailwind equivalent */
@keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
.animate-shimmer { animation: shimmer 2s infinite; }

/* Scrollbar */
::-webkit-scrollbar        { width: 8px; height: 8px; }
::-webkit-scrollbar-track  { background: rgba(31,41,55,0.5); border-radius: 4px; }
::-webkit-scrollbar-thumb  { background: rgba(75,85,99,0.8); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: rgba(107,114,128,0.9); }
</style>