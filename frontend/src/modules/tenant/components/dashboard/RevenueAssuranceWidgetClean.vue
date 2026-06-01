<template>
  <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 transition-colors space-y-4">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">Revenue Assurance</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400">Leakage detection and business KPI snapshot</p>
      </div>
      <div class="text-right">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Score</div>
        <div class="text-3xl font-black" :class="scoreClass">{{ revenueAssurance.score }}</div>
        <div class="text-xs font-semibold uppercase tracking-wide mt-1" :class="statusClass">{{ revenueAssurance.status || 'unknown' }}</div>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div v-for="tile in kpiTiles" :key="tile.label" class="rounded-xl border border-slate-200 dark:border-slate-700 p-3 bg-slate-50 dark:bg-slate-900/40">
        <div class="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400 font-semibold">{{ tile.label }}</div>
        <div class="text-lg font-bold text-slate-900 dark:text-slate-100 mt-1">{{ tile.value }}</div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
        <div class="flex items-center justify-between mb-3">
          <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Leakage Signals</h4>
          <span class="text-xs text-slate-500 dark:text-slate-400">{{ findings.length }} rule(s)</span>
        </div>
        <div v-if="findings.length" class="space-y-2">
          <div v-for="finding in findings" :key="finding.key" class="flex items-start justify-between gap-3 rounded-lg border px-3 py-2" :class="findingBorderClass(finding.severity)">
            <div>
              <div class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ finding.label }}</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Severity: {{ finding.severity }} · Count: {{ finding.count }}</div>
            </div>
            <div class="text-right text-xs text-slate-500 dark:text-slate-400 max-w-[11rem] truncate">
              {{ firstEvidence(finding.evidence) }}
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-emerald-700 dark:text-emerald-400">No leakage indicators detected.</p>
      </div>

      <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
        <div class="flex items-center justify-between mb-3">
          <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Business KPIs</h4>
          <span class="text-xs text-slate-500 dark:text-slate-400">Fresh from dashboard stats</span>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div v-for="tile in kpiSummary" :key="tile.label" class="rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700 p-3">
            <div class="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400 font-semibold">{{ tile.label }}</div>
            <div class="text-base font-bold text-slate-900 dark:text-slate-100 mt-1">{{ tile.value }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
      <div class="flex items-center justify-between mb-3">
        <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Summary</h4>
        <span class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(revenueAssurance.generatedAt) }}</span>
      </div>
      <p class="text-sm text-slate-600 dark:text-slate-300">{{ revenueAssurance.summary || 'Revenue assurance data is loading.' }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({
  revenueAssurance: {
    type: Object,
    required: true,
  },
  formatCurrency: {
    type: Function,
    required: true,
  },
})

const findings = computed(() => Array.isArray(props.revenueAssurance.findings) ? props.revenueAssurance.findings : [])
const kpis = computed(() => props.revenueAssurance.kpis || {})

const kpiTiles = computed(() => ([
  { label: 'MRR', value: props.formatCurrency(kpis.value.mrr || 0) },
  { label: 'ARR', value: props.formatCurrency(kpis.value.arr || 0) },
  { label: 'ARPU', value: props.formatCurrency(kpis.value.arpu || 0) },
  { label: 'Failed Payments', value: `${Number(kpis.value.failed_payment_rate || 0).toFixed(1)}%` },
]))

const kpiSummary = computed(() => ([
  { label: 'Daily Revenue', value: props.formatCurrency(kpis.value.daily_revenue || 0) },
  { label: 'Churn Rate', value: `${Number(kpis.value.churn_rate || 0).toFixed(1)}%` },
  { label: 'Active Subs', value: `${kpis.value.active_subscribers || 0}` },
  { label: 'Monthly Payments', value: `${kpis.value.monthly_completed_count || 0}` },
]))

const scoreClass = computed(() => ({
  'text-emerald-600 dark:text-emerald-400': props.revenueAssurance.score >= 85,
  'text-amber-600 dark:text-amber-400': props.revenueAssurance.score >= 70 && props.revenueAssurance.score < 85,
  'text-rose-600 dark:text-rose-400': props.revenueAssurance.score < 70,
}))

const statusClass = computed(() => ({
  'text-emerald-700 dark:text-emerald-400': props.revenueAssurance.status === 'healthy',
  'text-amber-700 dark:text-amber-400': props.revenueAssurance.status === 'warning',
  'text-rose-700 dark:text-rose-400': props.revenueAssurance.status === 'critical',
}))

const findingBorderClass = (severity) => ({
  'border-rose-200 dark:border-rose-900/40 bg-rose-50/60 dark:bg-rose-900/10': severity === 'critical',
  'border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-900/10': severity === 'high',
  'border-slate-200 dark:border-slate-700 bg-slate-50/60 dark:bg-slate-900/30': severity !== 'critical' && severity !== 'high',
})

const firstEvidence = (evidence) => {
  if (!Array.isArray(evidence) || evidence.length === 0) return 'No evidence'
  return String(evidence[0])
}

const formatDate = (value) => {
  if (!value) return 'Freshly computed'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return value
  }
}
</script>
