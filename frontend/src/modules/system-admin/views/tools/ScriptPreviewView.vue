<template>
  <div class="space-y-6">

    <!-- ── Page Header + Open Button ── -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-slate-100 flex items-center gap-2">
          <Terminal class="w-5 h-5 text-emerald-500" />
          MikroTik Script Preview
        </h1>
        <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
          Same DB flow as production provisioning — skip VPN &amp; SSH. Copy the generated script to your CHR.
        </p>
      </div>
      <button
        @click="showOverlay = true"
        class="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition-colors shadow-sm"
      >
        <Zap class="w-4 h-4" />
        New Script Preview
      </button>
    </div>

    <!-- ═══════ SlideOverlay — mirrors production CreateRouterModal ═══════ -->
    <SlideOverlay
      :model-value="showOverlay"
      title="Script Preview"
      :subtitle="routerData.name || 'Generate a MikroTik configuration script'"
      icon="Terminal"
      width="50%"
      :close-on-backdrop="stage === 1"
      @update:modelValue="val => { if (!val) handleOverlayClose() }"
      @close="handleOverlayClose"
    >
      <!-- ── Progress Bar (matches production) ── -->
      <div class="px-4 py-2 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex-shrink-0 -mx-4 sm:-mx-6 -mt-4 sm:-mt-6 mb-4">
        <div class="flex items-center justify-between mb-1.5">
          <div class="flex items-center gap-1.5">
            <div class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-pulse"></div>
            <span class="text-xs font-semibold text-gray-700 dark:text-slate-300">Progress</span>
          </div>
          <span class="text-sm font-bold text-blue-600">{{ progressPercent }}%</span>
        </div>
        <div class="relative w-full bg-gray-200 rounded-full h-2 overflow-hidden">
          <div
            class="absolute inset-0 bg-gradient-to-r from-blue-500 to-indigo-600 rounded-full transition-all duration-500 ease-out"
            :style="{ width: progressPercent + '%' }"
          ></div>
        </div>
        <div class="flex justify-between mt-1.5 text-xs">
          <span class="text-gray-600 dark:text-slate-400">{{ stageLabel }}</span>
          <span class="text-gray-700 dark:text-slate-300">{{ statusText }}</span>
        </div>
      </div>

      <!-- ── Error banner ── -->
      <div v-if="error" class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-sm text-red-700 mb-4">
        <AlertTriangle class="w-4 h-4 flex-shrink-0 mt-0.5 text-red-500" />
        <span class="whitespace-pre-wrap font-mono text-xs">{{ error }}</span>
      </div>

      <!-- ═══ Stage 1: Create Router Identity ═══ -->
      <div v-if="stage === 1" class="space-y-4">
        <div class="text-center mb-4">
          <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center mx-auto mb-2">
            <Terminal class="w-6 h-6 text-white" />
          </div>
          <h4 class="text-base font-bold text-gray-800 dark:text-slate-100 mb-1">Create Preview Router</h4>
          <p class="text-gray-600 dark:text-slate-400 text-xs max-w-md mx-auto">Set up a temporary router identity for script generation</p>
        </div>

        <!-- Tenant (auto-selected: system landlord) -->
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-slate-300 mb-1.5 flex items-center gap-1.5">
            <Building2 class="h-3 w-3 text-blue-600" />
            Tenant
          </label>
          <div v-if="loadingTenants" class="block w-full px-3 py-2 text-sm bg-gray-100 border-2 border-gray-300 rounded-lg text-gray-500">
            Loading…
          </div>
          <div v-else class="block w-full px-3 py-2 text-sm bg-gray-50 dark:bg-slate-700 border-2 border-gray-300 dark:border-slate-600 rounded-lg text-gray-900 dark:text-slate-100 font-medium">
            {{ tenants.length ? tenants[0].name : 'No landlord tenant found' }}
          </div>
          <p class="mt-1 text-xs text-gray-500">Script preview uses the system landlord schema</p>
        </div>

        <!-- Router Name -->
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-slate-300 mb-1.5 flex items-center gap-1.5">
            <Tag class="h-3 w-3 text-blue-600" />
            Router Name
          </label>
          <div class="relative">
            <input
              v-model.trim="form.router_name"
              type="text"
              required
              class="block w-full px-3 py-2 text-sm bg-white dark:bg-slate-700 border-2 border-gray-300 dark:border-slate-600 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900 dark:text-slate-100 placeholder-gray-400 dark:placeholder-slate-500 transition-all"
              placeholder="e.g. test_chr_01"
            />
            <div v-if="form.router_name" class="absolute right-2 top-1/2 -translate-y-1/2">
              <CheckCircle class="h-4 w-4 text-green-500" />
            </div>
          </div>
          <p class="mt-1 text-xs text-gray-500">Will be prefixed with <code class="bg-gray-100 dark:bg-slate-700 px-1 rounded text-gray-700 dark:text-slate-300">preview_</code></p>
        </div>

        <!-- Router Model -->
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-slate-300 mb-1.5 flex items-center gap-1.5">
            <Cpu class="h-3 w-3 text-blue-600" />
            Router Model
          </label>
          <select
            v-model="form.router_model"
            class="block w-full px-3 py-2 text-sm bg-white dark:bg-slate-700 border-2 border-gray-300 dark:border-slate-600 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900 dark:text-slate-100 font-mono transition-all"
          >
            <option value="" disabled>Select a router model…</option>
            <optgroup v-for="group in routerModelGroups" :key="group.tier" :label="`${group.label} — ${group.description}`">
              <option v-for="m in group.models" :key="m.value" :value="m.value">{{ m.label }}</option>
            </optgroup>
          </select>
          <!-- Tier badge -->
          <div v-if="form.router_model && selectedTier" class="mt-2 flex items-center gap-2">
            <span
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold"
              :class="tierBadgeClass"
            >
              <span class="w-1.5 h-1.5 rounded-full" :class="tierDotClass"></span>
              {{ selectedTier.label }}
            </span>
            <span class="text-xs text-gray-500">{{ selectedTier.description }}</span>
          </div>
          <p v-else class="mt-1 text-xs text-gray-500">Determines script profile: low-end = minimal firewall, high-end = full firewall</p>
        </div>
      </div>

      <!-- ═══ Stage 2: Service Mapping (mirrors production Stage 3) ═══ -->
      <div v-if="stage === 2" class="space-y-4">
        <div class="text-center mb-4">
          <div class="w-12 h-12 bg-gradient-to-br from-green-600 to-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-2">
            <Settings class="w-6 h-6 text-white" />
          </div>
          <h4 class="text-base font-bold text-gray-800 dark:text-slate-100 mb-1">Service Mapping</h4>
          <p class="text-gray-600 dark:text-slate-400 text-xs">Choose one service type, assign interfaces to it, then generate the script</p>
        </div>

        <!-- Router Information -->
        <div class="grid grid-cols-2 gap-3">
          <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm">
            <h4 class="text-xs font-medium text-gray-600 dark:text-slate-400 uppercase tracking-wider mb-1">Router Name</h4>
            <p class="text-gray-900 dark:text-slate-100 font-medium">{{ routerData.name }}</p>
          </div>
          <div class="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm">
            <h4 class="text-xs font-medium text-gray-600 dark:text-slate-400 uppercase tracking-wider mb-1">Model</h4>
            <p class="text-gray-900 dark:text-slate-100 font-medium font-mono">{{ routerData.model }}</p>
          </div>
          <div class="col-span-2 flex items-center gap-2 px-3 py-2 rounded-lg" :class="routerData.tier === 'low_end' ? 'bg-amber-50 border border-amber-200' : routerData.tier === 'high_end' ? 'bg-emerald-50 border border-emerald-200' : 'bg-blue-50 border border-blue-200'">
            <span
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold"
              :class="routerData.tier === 'low_end' ? 'bg-amber-100 text-amber-800' : routerData.tier === 'high_end' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800'"
            >
              {{ routerData.tier_label }} Profile
            </span>
            <span class="text-xs" :class="routerData.tier === 'low_end' ? 'text-amber-700' : routerData.tier === 'high_end' ? 'text-emerald-700' : 'text-blue-700'">
              {{ routerData.tier === 'low_end' ? 'Minimal firewall (~7 rules), longer delays' : routerData.tier === 'high_end' ? 'Full firewall (~15 rules), minimal delays' : 'Full firewall (~15 rules), standard delays' }}
            </span>
          </div>
        </div>

        <!-- Step 2a: Choose service type for this router -->
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-lg p-4">
          <h5 class="text-sm font-bold text-gray-800 dark:text-slate-100 mb-3">① Select Service Type</h5>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="svc in serviceTypes"
              :key="svc.value"
              @click="selectServiceType(svc.value)"
              :disabled="configuringIface !== null"
              class="flex flex-col items-center gap-1.5 px-3 py-3 rounded-lg border-2 text-center transition-all"
              :class="selectedServiceType === svc.value
                ? svc.activeClass
                : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300'"
            >
              <component :is="svc.icon" class="w-5 h-5" />
              <span class="text-xs font-bold">{{ svc.label }}</span>
              <span class="text-[10px] leading-tight">{{ svc.hint }}</span>
            </button>
          </div>
        </div>

        <!-- Step 2b: Assign interfaces (only shown after service type is chosen) -->
        <div v-if="selectedServiceType" class="bg-white dark:bg-slate-800 border-2 border-gray-200 dark:border-slate-700 rounded-lg p-4">
          <h5 class="text-sm font-bold text-gray-800 dark:text-slate-100 mb-1">② Enable Interfaces for <span :class="serviceTypeColor">{{ serviceTypeLabel }}</span></h5>
          <p class="text-xs text-gray-500 dark:text-slate-400 mb-3">Toggle the interfaces that should carry this service. At least one is required.</p>

          <div class="rounded-lg border border-gray-200 dark:border-slate-700 overflow-hidden">
            <div
              v-for="iface in interfaces"
              :key="iface.name"
              class="flex items-center justify-between gap-3 px-3 py-2.5 border-b border-gray-100 last:border-b-0"
            >
              <div class="min-w-0">
                <div class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ iface.name }}</div>
                <div class="text-xs text-gray-500 dark:text-slate-400">{{ iface.type }}</div>
              </div>
              <div class="flex items-center gap-2">
                <Loader2 v-if="configuringIface === iface.name" class="w-4 h-4 text-blue-500 animate-spin" />
                <template v-else>
                  <button
                    @click="toggleIface(iface.name)"
                    class="relative w-10 h-5 rounded-full transition-colors focus:outline-none"
                    :class="configuredIfaces.has(iface.name) ? activeToggleClass : 'bg-gray-200'"
                  >
                    <span
                      class="absolute top-[2px] left-[2px] w-4 h-4 bg-white rounded-full shadow transition-transform"
                      :class="configuredIfaces.has(iface.name) ? 'translate-x-5' : ''"
                    />
                  </button>
                  <CheckCircle v-if="configuredIfaces.has(iface.name)" class="w-4 h-4 text-emerald-500" />
                  <span v-else class="w-4 h-4" />
                </template>
              </div>
            </div>
          </div>

          <div v-if="configuredCount > 0" class="mt-3 text-xs text-gray-600">
            <span class="font-semibold">{{ configuredCount }} interface(s)</span> assigned to <span class="font-semibold" :class="serviceTypeColor">{{ serviceTypeLabel }}</span>
          </div>
        </div>

        <!-- Discovered Interfaces Info -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
          <h5 class="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
            <Zap class="w-4 h-4 text-blue-600" />
            Available Interfaces ({{ interfaces.length }})
          </h5>
          <div class="grid grid-cols-2 gap-2">
            <div v-for="iface in interfaces" :key="iface.name" class="bg-gray-50 dark:bg-slate-700/50 border border-gray-200 dark:border-slate-700 rounded p-2">
              <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-900 dark:text-slate-100">{{ iface.name }}</span>
                <span class="text-xs text-gray-500 dark:text-slate-400 bg-white dark:bg-slate-700 px-2 py-0.5 rounded">{{ iface.type }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Stage 3: Script Output ═══ -->
      <div v-if="stage === 3" class="space-y-4">
        <div class="text-center mb-4">
          <div class="w-16 h-16 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
            <CheckCircle class="w-8 h-8 text-green-600" />
          </div>
          <h4 class="text-lg font-bold text-gray-800 mb-2">Script Generated</h4>
          <div class="flex items-center justify-center gap-2 mb-1">
            <span
              class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold"
              :class="scriptMeta.tier === 'low_end' ? 'bg-amber-100 text-amber-800' : scriptMeta.tier === 'high_end' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800'"
            >
              {{ scriptMeta.tier_label }} Profile
            </span>
          </div>
          <p class="text-gray-600 dark:text-slate-400 text-sm">{{ scriptMeta.lines }} lines · {{ formatBytes(scriptMeta.bytes) }} — Copy and test on your CHR</p>
        </div>

        <!-- Script output terminal -->
        <div class="bg-gray-950 rounded-xl border border-gray-700 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-800">
            <div class="flex items-center gap-2 text-gray-400 text-xs">
              <Terminal class="w-3.5 h-3.5 text-emerald-500" />
              <span>RouterOS Script — {{ routerData.name }} ({{ routerData.model }}) · {{ scriptMeta.tier_label }}</span>
            </div>
            <button
              @click="copyScript"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md transition-all"
              :class="copied
                ? 'bg-emerald-600 text-white'
                : 'bg-gray-800 text-gray-300 hover:bg-gray-700 border border-gray-700'"
            >
              <Check v-if="copied" class="w-3.5 h-3.5" />
              <Copy v-else class="w-3.5 h-3.5" />
              {{ copied ? 'Copied!' : 'Copy Script' }}
            </button>
          </div>
          <div class="max-h-[50vh] overflow-auto p-5">
            <pre class="text-xs text-gray-300 font-mono leading-relaxed whitespace-pre select-all">{{ script }}</pre>
          </div>
        </div>
      </div>

      <!-- ── Footer (matches production) ── -->
      <template #footer>
        <div class="flex gap-3">
          <!-- Stage 1: Cancel + Create Router -->
          <button
            v-if="stage === 1"
            @click="handleOverlayClose"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Cancel
          </button>
          <button
            v-if="stage === 1"
            @click="createRouter"
            :disabled="creatingRouter || !form.tenant_id || !form.router_name"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ creatingRouter ? 'Creating…' : 'Create Router' }}
          </button>

          <!-- Stage 2: Delete & Start Over + Generate Script -->
          <button
            v-if="stage === 2"
            @click="deletePreviewAndReset"
            :disabled="deleting"
            class="flex-1 px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
          >
            <span class="flex items-center justify-center gap-1.5">
              <Trash2 class="w-3.5 h-3.5" />
              {{ deleting ? 'Deleting…' : 'Delete & Start Over' }}
            </span>
          </button>
          <button
            v-if="stage === 2"
            @click="doGenerateScript"
            :disabled="generating || configuredCount === 0"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ generating ? 'Generating…' : 'Generate Script' }}
          </button>

          <!-- Stage 3: Back to Mapping + Delete & Done -->
          <button
            v-if="stage === 3"
            @click="stage = 2"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
          >
            Back to Mapping
          </button>
          <button
            v-if="stage === 3"
            @click="deletePreviewAndReset"
            :disabled="deleting"
            class="flex-1 px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
          >
            <span class="flex items-center justify-center gap-1.5">
              <Trash2 class="w-3.5 h-3.5" />
              {{ deleting ? 'Cleaning up…' : 'Delete & Start Over' }}
            </span>
          </button>
        </div>
      </template>
    </SlideOverlay>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useConfirmStore } from '@/stores/confirm'
import { useToast } from '@/modules/common/composables/useToast.js'
import {
  Terminal, Loader2, Zap, Copy, Check, AlertTriangle, Trash2, CheckCircle,
  Building2, Tag, Cpu, Settings, Wifi, Network, Combine
} from 'lucide-vue-next'

const confirmStore = useConfirmStore()
const { warning: showWarning } = useToast()

// ── State ──
const showOverlay = ref(false)
const stage = ref(1)
const error = ref(null)

const tenants = ref([])
const loadingTenants = ref(true)

const form = reactive({
  tenant_id: '',
  router_name: '',
  router_model: 'RB750Gr3',
})

const routerData = reactive({ id: null, name: '', model: '', tier: '', tier_label: '' })
const routerModelGroups = ref([])

// 4 fixed dummy interfaces (mirroring what the real router would expose)
const interfaces = [
  { name: 'ether1', type: 'ethernet' },
  { name: 'ether2', type: 'ethernet' },
  { name: 'ether3', type: 'ethernet' },
  { name: 'ether4', type: 'ethernet' },
]

const selectedServiceType = ref('')  // 'pppoe' | 'hotspot' | 'hybrid' — one per router
const configuredIfaces = ref(new Set())
const configuringIface = ref(null)

const serviceTypes = [
  {
    value: 'pppoe',
    label: 'PPPoE',
    hint: 'Username/password auth',
    icon: Network,
    activeClass: 'border-indigo-500 bg-indigo-50 text-indigo-700',
  },
  {
    value: 'hotspot',
    label: 'Hotspot',
    hint: 'Portal / voucher auth',
    icon: Wifi,
    activeClass: 'border-blue-500 bg-blue-50 text-blue-700',
  },
  {
    value: 'hybrid',
    label: 'Hybrid',
    hint: 'PPPoE + Hotspot on VLANs',
    icon: Combine,
    activeClass: 'border-emerald-500 bg-emerald-50 text-emerald-700',
  },
]

const serviceTypeLabel = computed(() => {
  const s = serviceTypes.find(s => s.value === selectedServiceType.value)
  return s ? s.label : ''
})

const serviceTypeColor = computed(() => {
  if (selectedServiceType.value === 'pppoe') return 'text-indigo-700'
  if (selectedServiceType.value === 'hotspot') return 'text-blue-700'
  if (selectedServiceType.value === 'hybrid') return 'text-emerald-700'
  return 'text-gray-700'
})

const activeToggleClass = computed(() => {
  if (selectedServiceType.value === 'pppoe') return 'bg-indigo-500'
  if (selectedServiceType.value === 'hotspot') return 'bg-blue-500'
  if (selectedServiceType.value === 'hybrid') return 'bg-emerald-500'
  return 'bg-gray-400'
})
const creatingRouter = ref(false)
const generating = ref(false)
const deleting = ref(false)
const copied = ref(false)

const script = ref('')
const scriptMeta = reactive({ lines: 0, bytes: 0, tier: '', tier_label: '' })

const configuredCount = computed(() => configuredIfaces.value.size)

const progressPercent = computed(() => {
  if (stage.value === 1) return creatingRouter.value ? 15 : 0
  if (stage.value === 2) return generating.value ? 65 : 40 + (configuredCount.value * 8)
  return 100
})

const stageLabel = computed(() => {
  const labels = { 1: 'Router Identity', 2: 'Service Mapping', 3: 'Script Generated' }
  return labels[stage.value]
})

const selectedTier = computed(() => {
  if (!form.router_model || !routerModelGroups.value.length) return null
  for (const group of routerModelGroups.value) {
    if (group.models.some(m => m.value === form.router_model)) {
      return group
    }
  }
  return null
})

const tierBadgeClass = computed(() => {
  const t = selectedTier.value?.tier
  if (t === 'low_end') return 'bg-amber-100 text-amber-800'
  if (t === 'high_end') return 'bg-emerald-100 text-emerald-800'
  return 'bg-blue-100 text-blue-800'
})

const tierDotClass = computed(() => {
  const t = selectedTier.value?.tier
  if (t === 'low_end') return 'bg-amber-500'
  if (t === 'high_end') return 'bg-emerald-500'
  return 'bg-blue-500'
})

const statusText = computed(() => {
  if (stage.value === 1) return creatingRouter.value ? 'Creating router…' : 'Enter router details'
  if (stage.value === 2) {
    if (generating.value) return 'Generating script…'
    if (configuringIface.value) return `Configuring ${configuringIface.value}…`
    return `${configuredCount.value} interface(s) mapped`
  }
  return 'Copy script and test on CHR'
})

// ── Fetch router models ──
const fetchRouterModels = async () => {
  try {
    const res = await axios.get('/system/script-preview/models')
    routerModelGroups.value = res.data.models || []
  } catch {
    // Fallback: hardcoded groups
    routerModelGroups.value = [
      { tier: 'low_end', label: 'Low End', description: 'Minimal firewall, ~7 rules', models: [
        { value: 'RB941-2nD', label: 'hAP lite (RB941-2nD)' },
        { value: 'RB750Gr3', label: 'hEX (RB750Gr3)' },
      ]},
      { tier: 'mid_range', label: 'Mid Range', description: 'Full firewall, ~15 rules', models: [
        { value: 'RB4011iGS+', label: 'RB4011iGS+' },
        { value: 'CHR', label: 'Cloud Hosted Router (CHR)' },
      ]},
      { tier: 'high_end', label: 'High End', description: 'Full firewall, minimal delays', models: [
        { value: 'CCR1036', label: 'CCR1036' },
        { value: 'CCR2004', label: 'CCR2004' },
      ]},
    ]
  }
}

// ── Fetch landlord tenant (only tenant available for script preview) ──
const fetchTenants = async () => {
  try {
    const res = await axios.get('/system/tenants', { params: { per_page: 200 } })
    const all = res.data.tenants?.data || res.data.tenants || []
    // Only the system landlord tenant should be available for script preview
    const landlord = all.filter(t => t.is_landlord)
    tenants.value = landlord.length ? landlord : all.filter(t => t.is_default)
    // Auto-select the landlord tenant
    if (tenants.value.length) {
      form.tenant_id = tenants.value[0].id
    }
  } catch {
    tenants.value = []
  } finally {
    loadingTenants.value = false
  }
}

// ── Step 1: Create router ──
const createRouter = async () => {
  error.value = null
  creatingRouter.value = true
  try {
    const res = await axios.post('/system/script-preview/router', {
      tenant_id: form.tenant_id,
      router_name: form.router_name,
      router_model: form.router_model || undefined,
    })
    routerData.id = res.data.router_id
    routerData.name = res.data.name
    routerData.model = res.data.model
    routerData.tier = res.data.tier
    routerData.tier_label = res.data.tier_label
    stage.value = 2
  } catch (err) {
    handleError(err)
  } finally {
    creatingRouter.value = false
  }
}

// ── Step 2a: Choose service type — clears all current interface assignments ──
const selectServiceType = async (type) => {
  if (selectedServiceType.value === type) return

  // Remove all currently configured interfaces from the previous service type
  if (configuredIfaces.value.size > 0) {
    const removePromises = [...configuredIfaces.value].map(iface =>
      axios.post(`/system/script-preview/${routerData.id}/configure`, {
        tenant_id: form.tenant_id,
        interface: iface,
        service_type: 'none',
        advanced_options: {},
      }).catch(() => {})
    )
    await Promise.all(removePromises)
    configuredIfaces.value = new Set()
  }

  selectedServiceType.value = type
  error.value = null
}

// ── Step 2b: Toggle an interface on/off for the selected service type ──
const toggleIface = async (ifaceName) => {
  if (!selectedServiceType.value) return
  error.value = null
  const isOn = configuredIfaces.value.has(ifaceName)
  const newType = isOn ? 'none' : selectedServiceType.value
  configuringIface.value = ifaceName

  try {
    const res = await axios.post(`/system/script-preview/${routerData.id}/configure`, {
      tenant_id: form.tenant_id,
      interface: ifaceName,
      service_type: newType,
      advanced_options: {},
    })
    if (res.data.success) {
      if (isOn) {
        configuredIfaces.value.delete(ifaceName)
      } else {
        configuredIfaces.value.add(ifaceName)
      }
      configuredIfaces.value = new Set(configuredIfaces.value)
    }
  } catch (err) {
    handleError(err)
  } finally {
    configuringIface.value = null
  }
}

// ── Step 3: Generate script ──
const doGenerateScript = async () => {
  error.value = null
  generating.value = true
  try {
    const res = await axios.post(`/system/script-preview/${routerData.id}/generate`, {
      tenant_id: form.tenant_id,
    })
    script.value = res.data.script
    scriptMeta.lines = res.data.lines
    scriptMeta.bytes = res.data.bytes
    scriptMeta.tier = res.data.tier
    scriptMeta.tier_label = res.data.tier_label
    stage.value = 3
  } catch (err) {
    handleError(err)
  } finally {
    generating.value = false
  }
}

// ── Cleanup ──
const deletePreviewAndReset = async () => {
  if (!routerData.id) {
    resetAll()
    return
  }
  const confirmed = await confirmStore.open({ title: 'Delete Preview Router', message: 'Delete the preview router and its services/pools from the database?', confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger' })
  if (!confirmed) return
  deleting.value = true
  try {
    await axios.delete(`/system/script-preview/${routerData.id}`, {
      data: { tenant_id: form.tenant_id },
    })
    resetAll()
  } catch (err) {
    handleError(err)
  } finally {
    deleting.value = false
  }
}

const handleOverlayClose = () => {
  showOverlay.value = false
  // Don't auto-reset — user might reopen. Only reset on explicit delete.
}

const resetAll = () => {
  stage.value = 1
  routerData.id = null
  routerData.name = ''
  routerData.model = ''
  routerData.tier = ''
  routerData.tier_label = ''
  selectedServiceType.value = ''
  configuredIfaces.value = new Set()
  script.value = ''
  scriptMeta.lines = 0
  scriptMeta.bytes = 0
  scriptMeta.tier = ''
  scriptMeta.tier_label = ''
  error.value = null
}

// ── Utilities ──
const handleError = (err) => {
  const data = err.response?.data
  if (data?.errors) {
    error.value = Object.values(data.errors).flat().join('\n')
  } else {
    error.value = data?.error || data?.message || 'Request failed'
  }
}

const copyScript = async () => {
  try {
    await navigator.clipboard.writeText(script.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2500)
  } catch {
    showWarning('Clipboard access denied — select all text and copy manually.')
  }
}

const formatBytes = (b) => {
  if (!b) return '0 B'
  if (b < 1024) return b + ' B'
  return (b / 1024).toFixed(1) + ' KB'
}

onMounted(() => {
  fetchTenants()
  fetchRouterModels()
})
</script>
