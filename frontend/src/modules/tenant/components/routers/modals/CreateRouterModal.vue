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

    <!-- Slide-in Overlay Panel (Right to Left) -->
    <div v-if="showFormOverlay" class="fixed inset-y-0 right-0 z-[9999] w-full sm:w-2/3 lg:w-1/2 xl:w-2/5 bg-white shadow-2xl flex flex-col transition-transform duration-300 ease-out"
         :class="showFormOverlay ? 'translate-x-0' : 'translate-x-full'">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex-shrink-0">
          <div class="flex items-center gap-2">
            <div class="p-1.5 bg-blue-100 rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <div>
              <h3 class="text-base font-semibold text-gray-800">Router Provisioning</h3>
              <p v-if="provisioningRouter" class="text-xs text-gray-500">{{ provisioningRouter.name }}</p>
              <p v-else class="text-xs text-gray-500">Configure your MikroTik router</p>
            </div>
          </div>
          <button
            type="button"
            @click="$emit('close-form')"
            :disabled="waitingForJobCompletion"
            class="p-1.5 rounded-lg hover:bg-white transition-colors text-gray-500 hover:text-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>

        <!-- Progress Bar - Compact -->
        <div class="px-4 py-2 border-b border-gray-200 bg-white flex-shrink-0">
          <div class="flex items-center justify-between mb-1.5">
            <div class="flex items-center gap-1.5">
              <div class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-pulse"></div>
              <span class="text-xs font-semibold text-gray-700">Progress</span>
            </div>
            <span class="text-sm font-bold text-blue-600">{{ Math.round(provisioningProgress) }}%</span>
          </div>
          <div class="relative w-full bg-gray-200 rounded-full h-2 overflow-hidden">
            <div
              class="absolute inset-0 bg-gradient-to-r from-blue-500 to-indigo-600 rounded-full transition-all duration-500 ease-out"
              :style="{ width: provisioningProgress + '%' }"
            ></div>
          </div>
          <div class="flex justify-between mt-1.5 text-xs">
            <span class="text-gray-600">{{ currentStageText }}</span>
            <span class="text-gray-700">{{ provisioningStatus }}</span>
          </div>
        </div>

        <!-- Main Content - Scrollable -->
        <div class="flex-1 overflow-y-auto p-4 bg-gray-50">
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

            <!-- Stage 3: Interface Discovery & Service Selection -->
            <div v-if="currentStage === 3" class="space-y-4">
              <div class="text-center mb-4">
                <div class="w-16 h-16 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                  </svg>
                </div>
                <h4 class="text-lg font-medium text-gray-800 mb-2">Configure Router Services</h4>
                <p class="text-gray-600 text-sm">Select the services and interfaces for your router configuration</p>
              </div>

              <!-- Router Information -->
              <div class="grid grid-cols-2 gap-3">
                <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                  <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-1">Router ID</h4>
                  <p class="text-gray-900 font-medium">{{ provisioningRouter?.id || 'N/A' }}</p>
                </div>
                <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                  <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-1">Status</h4>
                  <p class="text-gray-900 font-medium">{{ provisioningRouter?.status || 'Pending' }}</p>
                </div>
              </div>

              <!-- Service Selection - Two Column Layout -->
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Hotspot Service -->
                <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                  <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-3">
                      <label class="relative inline-flex items-center cursor-pointer">
                        <input
                          type="checkbox"
                          v-model="enableHotspot"
                          class="sr-only peer"
                        />
                        <div class="w-11 h-6 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                      </label>
                      <div>
                        <h3 class="text-sm font-medium text-gray-800">Hotspot Service</h3>
                        <p class="text-xs text-gray-600">WiFi hotspot with captive portal</p>
                      </div>
                    </div>
                    <div class="text-2xl">📶</div>
                  </div>

                  <!-- Hotspot Configuration -->
                  <div v-if="enableHotspot" class="space-y-3 border-t border-gray-200 pt-3">
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-2">Select Interfaces</label>
                      <div class="grid grid-cols-2 gap-2">
                        <label v-for="iface in availableInterfaces" :key="iface.name" class="flex items-center space-x-2 p-2 rounded hover:bg-gray-50 border border-gray-200">
                          <input
                            type="checkbox"
                            :checked="selectedHotspotInterfaces.includes(iface.name)"
                            @change="toggleInterfaceSelection('hotspot', iface.name)"
                            class="rounded border-gray-300 text-green-600 focus:ring-green-500"
                          />
                          <div class="flex-1 min-w-0">
                            <span class="text-sm text-gray-900 block truncate">{{ iface.name }}</span>
                            <span class="text-xs text-gray-500">({{ iface.type }})</span>
                          </div>
                        </label>
                      </div>
                    </div>

                    <!-- Captive Portal Configuration -->
                    <div class="space-y-2">
                      <h4 class="text-sm font-medium text-gray-700">Captive Portal Settings</h4>
                      <div class="space-y-2">
                        <div>
                          <label class="block text-xs font-medium text-gray-600 mb-1">Portal Title</label>
                          <input
                            v-model="hotspotConfig.portalTitle"
                            type="text"
                            placeholder="Welcome to our WiFi"
                            class="block w-full text-sm rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500 p-2"
                          />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-600 mb-1">Login Methods</label>
                          <select
                            v-model="hotspotConfig.loginMethod"
                            class="block w-full text-sm rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500 p-2"
                          >
                            <option value="mac">MAC Address</option>
                            <option value="http-chap">HTTP CHAP</option>
                            <option value="https">HTTPS</option>
                          </select>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- PPPoE Service -->
                <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                  <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-3">
                      <label class="relative inline-flex items-center cursor-pointer">
                        <input
                          type="checkbox"
                          v-model="enablePPPoE"
                          class="sr-only peer"
                        />
                        <div class="w-11 h-6 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                      </label>
                      <div>
                        <h3 class="text-sm font-medium text-gray-800">PPPoE Service</h3>
                        <p class="text-xs text-gray-600">Point-to-Point Protocol over Ethernet</p>
                      </div>
                    </div>
                    <div class="text-2xl">🌐</div>
                  </div>

                  <!-- PPPoE Configuration -->
                  <div v-if="enablePPPoE" class="space-y-3 border-t border-gray-200 pt-3">
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-2">Select Interfaces</label>
                      <div class="grid grid-cols-2 gap-2">
                        <label v-for="iface in availableInterfaces" :key="iface.name" class="flex items-center space-x-2 p-2 rounded hover:bg-gray-50 border border-gray-200">
                          <input
                            type="checkbox"
                            :checked="selectedPPPoEInterfaces.includes(iface.name)"
                            @change="toggleInterfaceSelection('pppoe', iface.name)"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                          />
                          <div class="flex-1 min-w-0">
                            <span class="text-sm text-gray-900 block truncate">{{ iface.name }}</span>
                            <span class="text-xs text-gray-500">({{ iface.type }})</span>
                          </div>
                        </label>
                      </div>
                    </div>

                    <!-- PPPoE Settings -->
                    <div class="space-y-2">
                      <h4 class="text-sm font-medium text-gray-700">PPPoE Settings</h4>
                      <div class="space-y-2">
                        <div>
                          <label class="block text-xs font-medium text-gray-600 mb-1">Service Name</label>
                          <input
                            v-model="pppoeConfig.serviceName"
                            type="text"
                            placeholder="pppoe-service"
                            class="block w-full text-sm rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2"
                          />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-600 mb-1">IP Pool Range</label>
                          <input
                            v-model="pppoeConfig.ipPool"
                            type="text"
                            placeholder="192.168.2.100-192.168.2.200"
                            class="block w-full text-sm rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2"
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Stage 4: Configuration Generation & Deployment -->
            <div v-if="currentStage === 4" class="space-y-4">
              <div class="text-center mb-4">
                <div class="w-16 h-16 bg-purple-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                  </svg>
                </div>
                <h4 class="text-lg font-medium text-gray-800 mb-2">Deploy Configuration</h4>
                <p class="text-gray-600 text-sm">Review and deploy the service configuration to your router</p>
              </div>

              <!-- Deployment Progress -->
              <div v-if="deploymentStatus" class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                  <span class="text-sm font-semibold text-gray-700">Deployment Status</span>
                  <div class="flex items-center space-x-2">
                    <span :class="deploymentStatusClass" class="text-sm font-medium">{{ deploymentStatus }}</span>
                    <div v-if="waitingForJobCompletion" class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                  </div>
                </div>
                <div v-if="deploymentProgress > 0" class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                  <div :class="deploymentProgressClass" class="h-2.5 rounded-full transition-all duration-300" :style="{ width: deploymentProgress + '%' }"></div>
                </div>
                <p class="text-xs text-gray-600">{{ deploymentMessage }}</p>

                <!-- Retry Button for Failed Deployments -->
                <div v-if="deploymentStatus === 'Failed'" class="flex justify-center mt-3">
                  <button
                    @click="retryDeployment"
                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors flex items-center space-x-2 shadow-sm"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span>Retry Deployment</span>
                  </button>
                </div>
              </div>
            </div>
        </div>

        <!-- Real-time Logs -->
        <div class="border-t border-gray-200 bg-gray-50">
          <div class="p-4">
            <div class="flex items-center justify-between mb-3">
              <h5 class="text-sm font-semibold text-gray-800">Activity Logs</h5>
              <button @click="clearLogs" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Clear</button>
            </div>
            <div class="max-h-48 overflow-y-auto space-y-1 bg-white p-3 rounded-lg border border-gray-200">
              <div v-for="(log, index) in provisioningLogs.slice().reverse()" :key="index" class="flex items-start space-x-2 text-xs">
                <span class="text-gray-500 font-mono text-[10px]">{{ formatLogTime(log.timestamp) }}</span>
                <span :class="getLogLevelClass(log.level)" class="font-semibold">{{ log.level.toUpperCase() }}</span>
                <span class="text-gray-700 flex-1">{{ log.message }}</span>
              </div>
              <div v-if="provisioningLogs.length === 0" class="text-xs text-gray-500 italic text-center py-2">No activity yet...</div>
            </div>
          </div>
        </div>

        <!-- Action Buttons - Compact -->
        <div class="border-t border-gray-200 bg-white px-4 py-2.5 flex justify-between items-center flex-shrink-0">
          <div>
            <button
              v-if="!formSubmitting && currentStage > 1 && currentStage < 4"
              @click="previousStage"
              class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors"
            >
              ← Previous
            </button>
          </div>
          <div class="flex space-x-2">
            <button
              @click="$emit('close-form')"
              :disabled="waitingForJobCompletion"
              class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Cancel
            </button>
            <button
              v-if="currentStage === 1 && !initialConfig"
              @click="createRouterWithConfig"
              :disabled="!routerName || formSubmitting"
              class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ formSubmitting ? 'Creating...' : 'Create Router' }}
            </button>
            <button
              v-if="currentStage === 1 && initialConfig"
              @click="continueToMonitoring"
              class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
            >
              Continue →
            </button>
            <button
              v-if="currentStage === 3"
              @click="generateServiceConfig"
              :disabled="(!enableHotspot && !enablePPPoE) || formSubmitting"
              class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ formSubmitting ? 'Generating...' : 'Generate' }}
            </button>
            <button
              v-if="currentStage === 4 && !deploymentFailed"
              @click="deployConfiguration"
              :disabled="!serviceScript || formSubmitting || waitingForJobCompletion"
              class="px-3 py-1.5 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-1.5"
            >
              <span v-if="waitingForJobCompletion" class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
              <span>{{ waitingForJobCompletion ? 'Waiting...' : formSubmitting ? 'Deploying...' : 'Deploy' }}</span>
            </button>
            <button
              v-if="currentStage === 4 && deploymentFailed"
              @click="retryDeployment"
              :disabled="formSubmitting"
              class="px-3 py-1.5 text-xs font-medium text-white bg-red-600 hover:bg-red-700 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-1.5"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              <span>Retry Deployment</span>
            </button>
            <button
              v-if="currentStage === 5 && deploymentTimedOut"
              @click="retryDeployment"
              :disabled="formSubmitting"
              class="px-3 py-1.5 text-xs font-medium text-white bg-yellow-600 hover:bg-yellow-700 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-1.5"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              <span>Retry Check</span>
            </button>
          </div>
        </div>
      </div>
</template>

<script setup>
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
/* Shimmer animation for progress bar */
@keyframes shimmer {
  0% {
    transform: translateX(-100%);
  }
  100% {
    transform: translateX(100%);
  }
}

.animate-shimmer {
  animation: shimmer 2s infinite;
}

/* Smooth transitions */
* {
  transition-property: background-color, border-color, color, fill, stroke, opacity, box-shadow, transform;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}

/* Custom scrollbar */
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: rgba(31, 41, 55, 0.5);
  border-radius: 4px;
}

::-webkit-scrollbar-thumb {
  background: rgba(75, 85, 99, 0.8);
  border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
  background: rgba(107, 114, 128, 0.9);
}

/* Pulse animation for status indicators */
@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

.animate-pulse {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Fade in animation */
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.space-y-4 > * {
  animation: fadeIn 0.3s ease-out;
}

/* Hover effects for interactive elements */
button:not(:disabled):hover {
  transform: translateY(-1px);
}

button:not(:disabled):active {
  transform: translateY(0);
}

/* Focus styles */
input:focus,
select:focus,
textarea:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
}

/* Loading spinner enhancement */
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>