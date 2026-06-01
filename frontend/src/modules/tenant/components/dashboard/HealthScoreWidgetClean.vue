<template>
  <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 transition-colors">
    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">ISP Health Score</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400">Explainable tenant health with event-driven recalculation</p>
      </div>
      <div class="flex items-center gap-3">
        <div class="relative w-16 h-16 rounded-full border-8 flex items-center justify-center" :class="ringClass">
          <span class="text-lg font-black" :class="scoreClass">{{ formattedScore }}</span>
        </div>
        <div class="text-right">
          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide" :class="badgeClass">{{ healthScore.grade }}</span>
          <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">{{ healthScore.calculatedAt ? formatTimeAgo(healthScore.calculatedAt) : 'Awaiting first snapshot' }}</p>
        </div>
      </div>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">{{ healthScore.summary || 'No health score snapshot available yet.' }}</p>

    <div class="grid gap-3 md:grid-cols-3 mb-4">
      <div v-for="factor in topFactors" :key="factor.key" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/40 p-3 min-w-0">
        <div class="flex items-center justify-between gap-3 mb-1">
          <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide truncate">{{ factor.label }}</span>
          <span class="text-xs font-bold" :class="factor.penalty > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">-{{ factor.penalty }}</span>
        </div>
        <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ factor.count }} signals</div>
        <div v-if="factor.evidence?.length" class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 truncate">{{ factor.evidence.join(', ') }}</div>
      </div>
    </div>

    <div v-if="history.length" class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
      <div class="px-3 py-2 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Recent trend</div>
      <div class="divide-y divide-slate-100 dark:divide-slate-700">
        <div v-for="item in history" :key="`${item.calculated_at}-${item.source_event}`" class="flex items-center justify-between gap-3 px-3 py-2">
          <div class="min-w-0">
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100 truncate">{{ item.source_event || 'snapshot' }}</p>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 truncate">{{ formatTimeAgo(item.calculated_at) }}</p>
          </div>
          <span class="text-sm font-bold" :class="gradeTextClass(item.grade)">{{ item.score }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  healthScore: {
    type: Object,
    required: true,
  },
  formatTimeAgo: {
    type: Function,
    required: true,
  },
})

const formattedScore = computed(() => Math.round(Number(props.healthScore.score ?? 0)))

const scoreClass = computed(() => {
  if (formattedScore.value >= 85) return 'text-emerald-600 dark:text-emerald-400'
  if (formattedScore.value >= 70) return 'text-blue-600 dark:text-blue-400'
  if (formattedScore.value >= 50) return 'text-amber-600 dark:text-amber-400'
  return 'text-rose-600 dark:text-rose-400'
})

const ringClass = computed(() => {
  if (formattedScore.value >= 85) return 'border-emerald-100 dark:border-emerald-900/40'
  if (formattedScore.value >= 70) return 'border-blue-100 dark:border-blue-900/40'
  if (formattedScore.value >= 50) return 'border-amber-100 dark:border-amber-900/40'
  return 'border-rose-100 dark:border-rose-900/40'
})

const badgeClass = computed(() => {
  if (props.healthScore.grade === 'healthy') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
  if (props.healthScore.grade === 'warning') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
  return 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'
})

const topFactors = computed(() => (Array.isArray(props.healthScore.factors) ? props.healthScore.factors : [])
  .filter((factor) => Number(factor?.penalty ?? 0) > 0)
  .slice(0, 3))

const history = computed(() => Array.isArray(props.healthScore.history) ? props.healthScore.history.slice(0, 5) : [])

const gradeTextClass = (grade) => {
  if (grade === 'healthy') return 'text-emerald-600 dark:text-emerald-400'
  if (grade === 'warning') return 'text-amber-600 dark:text-amber-400'
  return 'text-rose-600 dark:text-rose-400'
}
</script>
