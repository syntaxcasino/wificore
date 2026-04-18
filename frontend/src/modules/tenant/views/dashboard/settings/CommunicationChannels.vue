<template>
  <div class="flex flex-col h-full bg-slate-50 dark:bg-slate-900 overflow-hidden">
    <!-- Header -->
    <div class="flex-shrink-0 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 shadow-sm">
      <div class="px-4 md:px-6 py-3 md:py-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 md:gap-6">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 md:w-11 md:h-11 bg-gradient-to-br from-emerald-600 to-teal-600 rounded-xl flex items-center justify-center shadow-lg">
              <MessageSquare class="h-5 w-5 md:h-6 md:w-6 text-white" />
            </div>
            <div>
              <h2 class="text-lg md:text-xl font-bold text-slate-900 dark:text-slate-100">Communication Channels</h2>
              <p class="text-xs text-slate-500 mt-0.5 hidden md:block">Configure SMS, WhatsApp & Email messaging</p>
            </div>
          </div>

          <!-- Stats & Actions -->
          <div class="flex items-center justify-between md:justify-end gap-2 md:gap-3">
            <div class="hidden md:flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg border border-slate-200">
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                <span class="text-xs font-semibold text-slate-700">{{ activeChannels.length }}</span>
              </div>
              <span class="text-slate-300">|</span>
              <span class="text-xs font-semibold text-blue-600">{{ totalChannels }}</span>
            </div>

            <button @click="fetchChannels" :disabled="loading"
              class="inline-flex items-center gap-1.5 px-2 md:px-3 py-2 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-all">
              <RefreshCw :class="loading ? 'animate-spin' : ''" class="w-4 h-4" />
              <span class="hidden md:inline">Refresh</span>
            </button>
            <button @click="openCreateOverlay"
              class="inline-flex items-center gap-1.5 px-3 md:px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 rounded-lg hover:from-emerald-700 hover:to-teal-700 transition-all shadow-md hover:shadow-lg">
              <Plus class="w-4 h-4" />
              <span class="hidden sm:inline">Add Channel</span>
              <span class="sm:hidden">Add</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 min-h-0 overflow-y-auto p-4 md:p-6">
      <!-- Loading -->
      <div v-if="loading && !channels.length" class="p-6 space-y-4">
        <div class="animate-pulse space-y-4">
          <div v-for="i in 3" :key="i" class="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
            <div class="flex items-center space-x-4">
              <div class="w-12 h-12 bg-gray-200 rounded-lg"></div>
              <div class="flex-1 space-y-2">
                <div class="h-4 bg-gray-200 rounded w-1/4"></div>
                <div class="h-3 bg-gray-200 rounded w-1/3"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-else-if="error && !channels.length" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-center">{{ error }}</p>
        <button @click="fetchChannels" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
      </div>

      <!-- Empty State -->
      <div v-else-if="!channels.length" class="flex flex-col items-center justify-center gap-4 p-12 text-slate-400">
        <MessageSquare class="w-16 h-16 opacity-30" />
        <div class="text-center">
          <p class="text-lg font-medium text-slate-600">No communication channels configured</p>
          <p class="text-sm text-slate-400 mt-1">Add SMS, WhatsApp, or Email channels to send notifications</p>
        </div>
        <button @click="openCreateOverlay" class="mt-2 px-4 py-2 text-sm font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition-colors">
          <Plus class="w-4 h-4 inline mr-1" /> Add First Channel
        </button>
      </div>

      <!-- Channels Table -->
      <div v-else class="px-4 md:px-6 pt-2 pb-2">
        <!-- Desktop Table -->
        <div class="hidden md:block bg-white border border-slate-200 shadow-sm overflow-hidden flex-col">
          <table class="w-full">
            <thead>
              <tr class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Channel</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Provider</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Sender</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Test</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="ch in channels" :key="ch.id" class="hover:bg-slate-50/50 transition-colors">
                <td class="px-4 py-3">
                  <div class="flex items-center gap-3">
                    <div :class="getTypeIconBg(ch.type)" class="w-9 h-9 rounded-lg flex items-center justify-center">
                      <component :is="getTypeIcon(ch.type)" class="w-4 h-4 text-white" />
                    </div>
                    <div>
                      <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ ch.name }}</div>
                      <div v-if="ch.is_default" class="text-[10px] font-medium text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded inline-block mt-0.5">DEFAULT</div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <span :class="getTypeBadge(ch.type)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">{{ ch.type }}</span>
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 capitalize">{{ formatProvider(ch.provider) }}</td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-400">{{ ch.sender_id || ch.phone_number || '—' }}</td>
                <td class="px-4 py-3 text-center">
                  <span :class="ch.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                    {{ ch.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <span v-if="ch.last_test_status === 'success'" class="text-emerald-500"><CheckCircle class="w-4 h-4 inline" /></span>
                  <span v-else-if="ch.last_test_status === 'failed'" class="text-red-500"><XCircle class="w-4 h-4 inline" /></span>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openViewOverlay(ch)" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="View">
                      <Eye class="w-4 h-4" />
                    </button>
                    <button @click="openEditOverlay(ch)" class="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" title="Edit">
                      <Pencil class="w-4 h-4" />
                    </button>
                    <button @click="confirmDelete(ch)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Cards -->
        <div class="md:hidden space-y-3">
          <div v-for="ch in channels" :key="ch.id" class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 shadow-sm p-4 cursor-pointer" @click="openViewOverlay(ch)">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-center gap-3">
                <div :class="getTypeIconBg(ch.type)" class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0">
                  <component :is="getTypeIcon(ch.type)" class="w-4 h-4 text-white" />
                </div>
                <div>
                  <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ ch.name }}</div>
                  <div class="text-xs text-slate-500 capitalize">{{ ch.type }} / {{ formatProvider(ch.provider) }}</div>
                </div>
              </div>
              <span :class="ch.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                {{ ch.is_active ? 'Active' : 'Off' }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- CREATE OVERLAY -->
    <SlideOverlay v-model="showCreateOverlay" title="Add Communication Channel" subtitle="Configure a new messaging channel" icon="Plus" width="60%">
      <form @submit.prevent="handleCreate" class="space-y-5">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Channel Name *</label>
          <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm" placeholder="e.g. Main SMS Gateway" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Type *</label>
            <select v-model="form.type" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" @change="onTypeChange">
              <option value="">Select type...</option>
              <option value="sms">SMS</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="email">Email</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Provider *</label>
            <select v-model="form.provider" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm">
              <option value="">Select provider...</option>
              <option v-for="p in availableProviders" :key="p" :value="p">{{ formatProvider(p) }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Sender ID</label>
            <input v-model="form.sender_id" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" placeholder="TRAIDNET" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
            <input v-model="form.phone_number" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" placeholder="+254..." />
          </div>
        </div>

        <!-- Dynamic Credentials -->
        <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
          <h4 class="text-sm font-semibold text-slate-700 mb-3">Provider Credentials</h4>
          <div class="space-y-3">
            <div v-for="field in credentialFields" :key="field.key">
              <label class="block text-xs font-medium text-slate-600 mb-1">{{ field.label }}</label>
              <input v-model="form.credentials[field.key]" :type="field.secret ? 'password' : 'text'" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" :placeholder="field.placeholder || ''" />
            </div>
          </div>
        </div>

        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input v-model="form.is_active" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
            <span class="text-sm text-slate-700">Active</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input v-model="form.is_default" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
            <span class="text-sm text-slate-700">Set as default for this type</span>
          </label>
        </div>

        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ formError }}</div>
      </form>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showCreateOverlay = false"
            type="button"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Cancel
          </button>
          <button
            @click="handleCreate"
            :disabled="formSubmitting"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ formSubmitting ? 'Creating...' : 'Create Channel' }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- EDIT OVERLAY -->
    <SlideOverlay v-model="showEditOverlay" title="Edit Communication Channel" subtitle="Update channel configuration" icon="Pencil" width="60%">
      <form @submit.prevent="handleUpdate" class="space-y-5">
        <div>
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Channel Name *</label>
          <input v-model="editForm.name" type="text" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Type *</label>
            <select v-model="editForm.type" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" @change="onEditTypeChange">
              <option value="sms">SMS</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="email">Email</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Provider *</label>
            <select v-model="editForm.provider" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm">
              <option v-for="p in editAvailableProviders" :key="p" :value="p">{{ formatProvider(p) }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Sender ID</label>
            <input v-model="editForm.sender_id" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
            <input v-model="editForm.phone_number" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" />
          </div>
        </div>

        <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
          <h4 class="text-sm font-semibold text-slate-700 mb-3">Provider Credentials</h4>
          <p class="text-xs text-slate-500 mb-3">Leave blank to keep existing credentials</p>
          <div class="space-y-3">
            <div v-for="field in editCredentialFields" :key="field.key">
              <label class="block text-xs font-medium text-slate-600 mb-1">{{ field.label }}</label>
              <input v-model="editForm.credentials[field.key]" :type="field.secret ? 'password' : 'text'" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" :placeholder="field.placeholder || 'Leave blank to keep current'" />
            </div>
          </div>
        </div>

        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input v-model="editForm.is_active" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
            <span class="text-sm text-slate-700">Active</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input v-model="editForm.is_default" type="checkbox" class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500" />
            <span class="text-sm text-slate-700">Set as default</span>
          </label>
        </div>

        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{{ formError }}</div>
      </form>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showEditOverlay = false"
            type="button"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Cancel
          </button>
          <button
            @click="handleUpdate"
            :disabled="formSubmitting"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ formSubmitting ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- VIEW OVERLAY -->
    <SlideOverlay v-model="showViewOverlay" title="Channel Details" :subtitle="selectedChannel?.name || ''" icon="Eye" width="60%">
      <div v-if="selectedChannel" class="space-y-6">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Name</div>
            <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ selectedChannel.name }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Type</div>
            <div class="mt-1"><span :class="getTypeBadge(selectedChannel.type)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">{{ selectedChannel.type }}</span></div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Provider</div>
            <div class="mt-1 text-sm text-slate-700 capitalize">{{ formatProvider(selectedChannel.provider) }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Status</div>
            <div class="mt-1">
              <span :class="selectedChannel.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                {{ selectedChannel.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Sender ID</div>
            <div class="mt-1 text-sm text-slate-700">{{ selectedChannel.sender_id || '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Phone Number</div>
            <div class="mt-1 text-sm text-slate-700">{{ selectedChannel.phone_number || '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Default</div>
            <div class="mt-1 text-sm text-slate-700">{{ selectedChannel.is_default ? 'Yes' : 'No' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Credentials</div>
            <div class="mt-1 text-sm text-slate-700">{{ selectedChannel.has_credentials ? 'Configured' : 'Not set' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Last Test</div>
            <div class="mt-1 text-sm text-slate-700">
              <span v-if="selectedChannel.last_test_status === 'success'" class="text-emerald-600">Passed</span>
              <span v-else-if="selectedChannel.last_test_status === 'failed'" class="text-red-600">Failed</span>
              <span v-else class="text-slate-400">Never tested</span>
            </div>
          </div>
          <div>
            <div class="text-xs font-medium text-slate-500 uppercase tracking-wider">Created</div>
            <div class="mt-1 text-sm text-slate-700">{{ formatDate(selectedChannel.created_at) }}</div>
          </div>
        </div>

        <!-- Test Message -->
        <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
          <h4 class="text-sm font-semibold text-slate-700 mb-3">Send Test Message</h4>
          <div class="flex gap-3">
            <input v-model="testRecipient" type="text" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 text-sm" :placeholder="selectedChannel.type === 'email' ? 'test@example.com' : '+254712345678'" />
            <button @click="handleSendTest" :disabled="testSending || !testRecipient" class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors">
              <span v-if="testSending">Sending...</span>
              <span v-else>Send Test</span>
            </button>
          </div>
          <div v-if="testResult" :class="testResult.success ? 'text-emerald-600' : 'text-red-600'" class="mt-2 text-xs">{{ testResult.message }}</div>
        </div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="showViewOverlay = false"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Close
          </button>
          <button
            @click="openEditOverlay(selectedChannel)"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
          >
            <Pencil class="w-4 h-4 inline mr-1" /> Edit
          </button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { MessageSquare, Plus, RefreshCw, AlertCircle, Eye, Pencil, Trash2, CheckCircle, XCircle, Smartphone, Mail, MessageCircle } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useCommunicationChannels } from '@/modules/tenant/composables/data/useCommunicationChannels'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'

const authStore = useAuthStore()
const { subscribeToPrivateChannel, unsubscribeFromChannel } = useBroadcasting()
const confirmStore = useConfirmStore()

const {
  channels,
  loading,
  error,
  activeChannels,
  totalChannels,
  fetchChannels,
  fetchProviders,
  createChannel,
  updateChannel,
  deleteChannel,
  sendTestMessage,
  providers,
} = useCommunicationChannels()

// Overlay state
const showCreateOverlay = ref(false)
const showEditOverlay = ref(false)
const showViewOverlay = ref(false)
const selectedChannel = ref(null)
const formSubmitting = ref(false)
const formError = ref(null)

// Test message state
const testRecipient = ref('')
const testSending = ref(false)
const testResult = ref(null)

// Create form
const form = ref({
  name: '',
  type: '',
  provider: '',
  sender_id: '',
  phone_number: '',
  is_active: true,
  is_default: false,
  credentials: {},
})

// Edit form
const editForm = ref({
  name: '',
  type: '',
  provider: '',
  sender_id: '',
  phone_number: '',
  is_active: true,
  is_default: false,
  credentials: {},
})

// Provider lists
const providerMap = {
  sms: ['africastalking', 'twilio', 'custom'],
  whatsapp: ['twilio', 'whatsapp_business', 'custom'],
  email: ['smtp', 'mailgun', 'sendgrid', 'custom'],
}

const availableProviders = computed(() => providerMap[form.value.type] || [])
const editAvailableProviders = computed(() => providerMap[editForm.value.type] || [])

// Credential field definitions per provider
const getCredFields = (provider) => {
  const fields = {
    africastalking: [
      { key: 'username', label: 'Username', placeholder: 'sandbox or production username' },
      { key: 'api_key', label: 'API Key', secret: true },
      { key: 'environment', label: 'Environment', placeholder: 'sandbox or production' },
    ],
    twilio: [
      { key: 'account_sid', label: 'Account SID', placeholder: 'ACxxxxxxx' },
      { key: 'auth_token', label: 'Auth Token', secret: true },
    ],
    whatsapp_business: [
      { key: 'access_token', label: 'Access Token', secret: true },
      { key: 'phone_number_id', label: 'Phone Number ID', placeholder: 'From Meta Business' },
    ],
    custom: [
      { key: 'api_key', label: 'API Key', secret: true },
      { key: 'api_endpoint', label: 'API Endpoint', placeholder: 'https://api.example.com/send' },
    ],
    smtp: [
      { key: 'host', label: 'SMTP Host', placeholder: 'smtp.gmail.com' },
      { key: 'port', label: 'SMTP Port', placeholder: '587' },
      { key: 'username', label: 'Username' },
      { key: 'password', label: 'Password', secret: true },
    ],
    mailgun: [
      { key: 'api_key', label: 'API Key', secret: true },
      { key: 'domain', label: 'Domain', placeholder: 'mg.example.com' },
    ],
    sendgrid: [
      { key: 'api_key', label: 'API Key', secret: true },
    ],
  }
  return fields[provider] || fields.custom
}

const credentialFields = computed(() => getCredFields(form.value.provider))
const editCredentialFields = computed(() => getCredFields(editForm.value.provider))

const onTypeChange = () => {
  form.value.provider = ''
  form.value.credentials = {}
}

const onEditTypeChange = () => {
  editForm.value.provider = ''
  editForm.value.credentials = {}
}

// Helpers
const formatProvider = (p) => {
  const map = { africastalking: "Africa's Talking", twilio: 'Twilio', whatsapp_business: 'WhatsApp Business', custom: 'Custom API', smtp: 'SMTP', mailgun: 'Mailgun', sendgrid: 'SendGrid' }
  return map[p] || p
}

const getTypeIcon = (type) => {
  return { sms: Smartphone, whatsapp: MessageCircle, email: Mail }[type] || MessageSquare
}

const getTypeIconBg = (type) => {
  return { sms: 'bg-blue-500', whatsapp: 'bg-emerald-500', email: 'bg-purple-500' }[type] || 'bg-slate-500'
}

const getTypeBadge = (type) => {
  return { sms: 'bg-blue-100 text-blue-700', whatsapp: 'bg-emerald-100 text-emerald-700', email: 'bg-purple-100 text-purple-700' }[type] || 'bg-slate-100 text-slate-700'
}

const formatDate = (d) => d ? new Date(d).toLocaleDateString() : '—'

// Overlay handlers
const openCreateOverlay = () => {
  form.value = { name: '', type: '', provider: '', sender_id: '', phone_number: '', is_active: true, is_default: false, credentials: {} }
  formError.value = null
  showCreateOverlay.value = true
}

const openEditOverlay = (ch) => {
  showViewOverlay.value = false
  editForm.value = {
    id: ch.id,
    name: ch.name,
    type: ch.type,
    provider: ch.provider,
    sender_id: ch.sender_id || '',
    phone_number: ch.phone_number || '',
    is_active: ch.is_active,
    is_default: ch.is_default,
    credentials: {},
  }
  formError.value = null
  showEditOverlay.value = true
}

const openViewOverlay = (ch) => {
  selectedChannel.value = ch
  testRecipient.value = ''
  testResult.value = null
  showViewOverlay.value = true
}

const handleCreate = async () => {
  formSubmitting.value = true
  formError.value = null
  try {
    await createChannel(form.value)
    showCreateOverlay.value = false
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to create channel'
  } finally {
    formSubmitting.value = false
  }
}

const handleUpdate = async () => {
  formSubmitting.value = true
  formError.value = null
  try {
    const payload = { ...editForm.value }
    delete payload.id
    // Remove empty credentials (keep existing)
    if (payload.credentials) {
      const filtered = {}
      for (const [k, v] of Object.entries(payload.credentials)) {
        if (v && v.trim()) filtered[k] = v
      }
      if (Object.keys(filtered).length === 0) {
        delete payload.credentials
      } else {
        payload.credentials = filtered
      }
    }
    await updateChannel(editForm.value.id, payload)
    showEditOverlay.value = false
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to update channel'
  } finally {
    formSubmitting.value = false
  }
}

const confirmDelete = async (ch) => {
  const ok = await confirmStore.open({ title: 'Delete Channel', message: `Delete channel "${ch.name}"? This cannot be undone.`, confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger' })
  if (!ok) return
  try {
    await deleteChannel(ch.id)
  } catch (err) {
    console.error('Failed to delete channel:', err)
  }
}

const handleSendTest = async () => {
  if (!selectedChannel.value || !testRecipient.value) return
  testSending.value = true
  testResult.value = null
  try {
    const res = await sendTestMessage(selectedChannel.value.id, testRecipient.value)
    testResult.value = { success: true, message: res.message || 'Test message queued. Check WebSocket for result.' }
  } catch (err) {
    testResult.value = { success: false, message: err.response?.data?.message || 'Failed to send test' }
  } finally {
    testSending.value = false
  }
}

// Lifecycle
let channelName = null

onMounted(async () => {
  await Promise.all([fetchChannels(), fetchProviders()])

  // Subscribe to tenant-scoped settings events
  const tenantId = authStore.tenantId
  if (tenantId) {
    channelName = `tenant.${tenantId}.settings`
    subscribeToPrivateChannel(channelName, {
      '.CommunicationChannelCreated': () => fetchChannels(),
      '.CommunicationChannelUpdated': () => fetchChannels(),
      '.CommunicationChannelDeleted': () => fetchChannels(),
      '.TestMessageSent': (data) => {
        if (selectedChannel.value && data.channel_id === selectedChannel.value.id) {
          testResult.value = { success: data.status === 'success', message: data.message }
          testSending.value = false
        }
        fetchChannels()
      },
    })
  }
})

onUnmounted(() => {
  if (channelName) {
    unsubscribeFromChannel(channelName)
  }
})
</script>
