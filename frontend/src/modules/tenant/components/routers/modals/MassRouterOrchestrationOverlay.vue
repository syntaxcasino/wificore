<template>
  <SlideOverlay
    :model-value="visible"
    title="Mass Router Change Orchestration"
    subtitle="Preview batched changes before execution"
    icon="Workflow"
    width="68%"
    gradient
    no-padding
    @update:model-value="val => { if (!val) $emit('close') }"
    @close="$emit('close')"
  >
    <div class="p-5 bg-slate-50 space-y-4">
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-1 space-y-4">
          <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-800 mb-3">Preview Inputs</h4>
            <label class="block text-xs font-medium text-slate-500 mb-1">Template</label>
            <select v-model="localTemplate" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.name }}</option>
            </select>
            <p class="mt-2 text-xs text-slate-500">{{ selectedTemplate?.description || 'Select a template to preview its rollout path.' }}</p>

            <div class="grid grid-cols-2 gap-3 mt-4">
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Change Type</label>
                <select v-model="localChangeType" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <option value="apply_service_configs">Apply Service Configs</option>
                  <option value="deploy_service_config">Deploy Service Config</option>
                  <option value="verify_connectivity">Verify Connectivity</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Batch Size</label>
                <input v-model.number="localBatchSize" type="number" min="1" max="50" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mt-4">
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-500">Routers</p>
                <p class="font-bold text-slate-900">{{ routers.length }}</p>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-500">Supported</p>
                <p class="font-bold text-slate-900">{{ preview?.supported_count ?? 0 }}</p>
              </div>
            </div>

            <div class="mt-4 space-y-2">
              <button
                type="button"
                class="w-full inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                :disabled="loading || routers.length === 0"
                @click="emitPreview"
              >
                <svg v-if="loading" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                {{ loading ? 'Generating Preview...' : 'Generate Preview' }}
              </button>
              <button
                type="button"
                class="w-full inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                :disabled="loading || deploying || routers.length === 0 || !selectedTemplate?.can_execute"
                @click="emitDeploy"
              >
                <svg v-if="deploying" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                {{ deploying ? 'Queueing Deployment...' : (selectedTemplate?.can_execute ? 'Deploy Changes' : 'Preview Only') }}
              </button>
            </div>
          </div>

          <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
            <h4 class="text-sm font-semibold text-slate-800 mb-2">Selected Template</h4>
            <p class="text-sm font-medium text-slate-900">{{ selectedTemplate?.name || 'No template selected' }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ selectedTemplate?.description || 'Templates are loaded from the config-driven marketplace.' }}</p>
            <div v-if="selectedTemplate" class="mt-2 rounded-xl border px-3 py-2 text-xs" :class="selectedTemplate.can_execute ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'">
              <p class="font-semibold uppercase tracking-wide">{{ selectedTemplate.execution_mode || 'preview_only' }}</p>
              <p class="mt-1">{{ selectedTemplate.execution_reason || 'Template execution details unavailable.' }}</p>
            </div>
            <div class="mt-3 flex flex-wrap gap-1">
              <span v-for="tag in (selectedTemplate?.tags || []).slice(0, 4)" :key="tag" class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ tag }}</span>
            </div>
          </div>
        </div>

        <div class="xl:col-span-2 space-y-4">
          <div v-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ error }}</div>
          <div v-if="deployError" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ deployError }}</div>

          <div v-if="preview" class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm space-y-4">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
              <div>
                <h4 class="text-base font-semibold text-slate-900">Preview Plan</h4>
                <p class="text-sm text-slate-500">{{ preview.execution_strategy?.ordering || 'Deterministic ordering' }} · {{ preview.execution_strategy?.batching || 'sequential' }}</p>
              </div>
              <div class="flex gap-2 text-xs">
                <span class="px-2 py-1 rounded-full bg-indigo-100 text-indigo-700">{{ preview.router_count }} routers</span>
                <span class="px-2 py-1 rounded-full bg-emerald-100 text-emerald-700">{{ preview.supported_count }} supported</span>
                <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700">{{ preview.warning_count }} warnings</span>
              </div>
            </div>

            <div v-if="preview.warnings?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 mb-2">Warnings</p>
              <ul class="space-y-1 text-sm text-amber-800">
                <li v-for="warning in preview.warnings" :key="warning">{{ warning }}</li>
              </ul>
            </div>

            <div class="space-y-2 max-h-[28rem] overflow-y-auto pr-1">
              <div v-for="plan in preview.router_plans || []" :key="plan.router_id" class="rounded-xl border border-slate-200 p-3">
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <p class="font-semibold text-slate-900">{{ plan.name }}</p>
                    <p class="text-xs text-slate-500">{{ plan.vendor }} · {{ plan.version_profile || 'n/a' }} · {{ plan.vendor_profile || 'n/a' }}</p>
                  </div>
                  <span class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-full" :class="plan.supported ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">{{ plan.supported ? 'supported' : 'blocked' }}</span>
                </div>
                <ul v-if="plan.warnings?.length" class="mt-2 space-y-1 text-xs text-slate-600">
                  <li v-for="warning in plan.warnings" :key="warning">• {{ warning }}</li>
                </ul>
              </div>
            </div>
          </div>

          <div v-if="deployResult" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm text-sm text-emerald-900">
            <h4 class="text-base font-semibold">Deployment Queued</h4>
            <p class="mt-1 text-emerald-800">{{ deployResult.queued_count ?? 0 }} router jobs queued · {{ deployResult.skipped_count ?? 0 }} skipped</p>
            <div v-if="deployResult.warnings?.length" class="mt-3 rounded-xl border border-emerald-200 bg-white/60 p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 mb-2">Deployment warnings</p>
              <ul class="space-y-1 text-xs text-emerald-800">
                <li v-for="warning in deployResult.warnings" :key="warning">• {{ warning }}</li>
              </ul>
            </div>
          </div>

          <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
            Generate a preview to inspect batch ordering, vendor compatibility, and rollout warnings.
          </div>
        </div>
      </div>
    </div>
  </SlideOverlay>
</template>

<script>
export default {
  name: 'MassRouterOrchestrationOverlay',
  props: {
    visible: { type: Boolean, default: false },
    routers: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    preview: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    deploying: { type: Boolean, default: false },
    error: { type: String, default: '' },
    deployError: { type: String, default: '' },
    deployResult: { type: Object, default: null },
  },
  emits: ['close', 'preview', 'deploy'],
  data() {
    return {
      localTemplate: '',
      localChangeType: 'apply_service_configs',
      localBatchSize: 5,
    }
  },
  computed: {
    selectedTemplate() {
      return this.templates.find((template) => template.id === this.localTemplate) || this.templates[0] || null
    },
  },
  watch: {
    templates: {
      immediate: true,
      handler(value) {
        if (!this.localTemplate && Array.isArray(value) && value.length > 0) {
          this.localTemplate = value[0].id
        }
      },
    },
  },
  methods: {
    emitPreview() {
      this.$emit('preview', {
        template: this.localTemplate,
        change_type: this.localChangeType,
        batch_size: this.localBatchSize,
      })
    },
    emitDeploy() {
      this.$emit('deploy', {
        template: this.localTemplate,
        change_type: this.localChangeType,
        batch_size: this.localBatchSize,
      })
    },
  },
}
</script>
