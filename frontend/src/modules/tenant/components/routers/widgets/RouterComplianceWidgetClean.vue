<template>
  <div class="space-y-4">
    <div v-if="loading" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full border-2 border-slate-200 border-t-indigo-500 animate-spin"></div>
        <div>
          <p class="text-sm font-semibold text-slate-700">Loading router compliance...</p>
          <p class="text-xs text-slate-500">Checking baseline controls and snapshot freshness.</p>
        </div>
      </div>
    </div>

    <div v-else-if="error" class="bg-rose-50 border border-rose-200 text-rose-700 p-4 rounded-xl">
      <p class="text-sm font-medium">{{ error }}</p>
      <button
        type="button"
        class="mt-3 px-3 py-1.5 text-xs font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700"
        @click="$emit('refresh')"
      >
        Retry
      </button>
    </div>

    <div v-else class="space-y-4">
      <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Compliance Score</div>
            <div class="flex items-end gap-3">
              <div class="text-4xl font-black text-slate-900">{{ report?.score ?? '—' }}</div>
              <div class="pb-1 text-sm font-semibold" :class="gradeClass">{{ report?.grade || '—' }}</div>
            </div>
            <p class="text-sm text-slate-600 mt-2">{{ report?.summary || 'No compliance snapshot available yet.' }}</p>
          </div>
          <div class="text-right">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Status</div>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wide" :class="statusClass">
              {{ report?.status || 'unknown' }}
            </span>
            <div v-if="report?.evaluated_at" class="text-xs text-slate-500 mt-2">{{ formatDate(report.evaluated_at) }}</div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
          <div class="text-sm font-semibold text-slate-700 mb-3">Missing Controls</div>
          <div v-if="missingControls.length" class="space-y-2">
            <div v-for="item in missingControls" :key="item" class="flex items-center gap-2 text-sm text-rose-700">
              <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
              <span>{{ item }}</span>
            </div>
          </div>
          <p v-else class="text-sm text-emerald-700">All baseline controls passed.</p>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
          <div class="text-sm font-semibold text-slate-700 mb-3">Passed Controls</div>
          <div v-if="passedControls.length" class="flex flex-wrap gap-2">
            <span v-for="item in passedControls" :key="item" class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
              {{ formatControl(item) }}
            </span>
          </div>
          <p v-else class="text-sm text-slate-500">No compliance data yet.</p>
        </div>
      </div>

      <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-3">
          <div class="text-sm font-semibold text-slate-700">Detailed Checks</div>
          <button
            type="button"
            class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200"
            @click="$emit('refresh')"
          >
            Refresh
          </button>
        </div>
        <div class="space-y-3">
          <div v-for="check in checks" :key="check.key" class="flex items-start justify-between gap-4 p-3 rounded-lg border" :class="check.passed ? 'border-emerald-200 bg-emerald-50/50' : 'border-rose-200 bg-rose-50/50'">
            <div>
              <div class="text-sm font-semibold text-slate-800">{{ check.label }}</div>
              <div class="text-xs text-slate-500 mt-1">Weight: {{ check.weight }}%</div>
            </div>
            <div class="text-right">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide" :class="check.passed ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'">
                {{ check.passed ? 'Pass' : 'Fail' }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'RouterComplianceWidgetClean',
  emits: ['refresh'],
  props: {
    report: {
      type: Object,
      default: null,
    },
    loading: Boolean,
    error: String,
  },
  computed: {
    checks() {
      return Array.isArray(this.report?.checks) ? this.report.checks : []
    },
    missingControls() {
      return Array.isArray(this.report?.missing_controls) ? this.report.missing_controls : []
    },
    passedControls() {
      return Array.isArray(this.report?.passed_controls) ? this.report.passed_controls : []
    },
    statusClass() {
      const status = String(this.report?.status || '').toLowerCase()
      return {
        'bg-emerald-100 text-emerald-700': status === 'compliant',
        'bg-amber-100 text-amber-700': status === 'warning',
        'bg-rose-100 text-rose-700': status === 'non_compliant',
        'bg-slate-100 text-slate-600': !status || status === 'unknown',
      }
    },
    gradeClass() {
      const grade = String(this.report?.grade || '').toUpperCase()
      return {
        'text-emerald-600': grade.startsWith('A'),
        'text-amber-600': grade.startsWith('B') || grade.startsWith('C'),
        'text-rose-600': grade === 'D' || !grade,
      }
    },
  },
  methods: {
    formatControl(control) {
      return String(control || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    },
    formatDate(value) {
      try {
        return new Date(value).toLocaleString()
      } catch {
        return value
      }
    },
  },
}
</script>
