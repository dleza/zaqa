<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import { router } from '@inertiajs/vue3'
import { Users } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string; verifier_id: number | null }
  dashboard: any
  verifier_options: Array<{ id: number; name: string }>
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const verifierId = ref(props.filters.verifier_id ? String(props.filters.verifier_id) : '')

watch([range, from, to, verifierId], () => {
  router.get(
    '/admin/reports/verifiers',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
      verifier_id: verifierId.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
  verifier_id: verifierId.value,
}))

const wLabels = computed(() => props.dashboard?.workload_chart?.labels ?? [])
const wValues = computed(() => props.dashboard?.workload_chart?.values ?? [])
const cLabels = computed(() => props.dashboard?.completed_chart?.labels ?? [])
const cValues = computed(() => props.dashboard?.completed_chart?.values ?? [])
const pLabels = computed(() => props.dashboard?.pending_chart?.labels ?? [])
const pValues = computed(() => props.dashboard?.pending_chart?.values ?? [])

function formatDuration(sec: number | null | undefined) {
  if (sec === null || sec === undefined) return '—'
  const s = Math.max(0, Math.floor(sec))
  const m = Math.floor(s / 60)
  const h = Math.floor(m / 60)
  if (h > 0) return `${h}h ${m % 60}m`
  if (m > 0) return `${m}m`
  return `${s}s`
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Users class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Verifier performance</h1>
        <p class="mt-1 text-sm text-text-muted">Based on assignment events and qualification review timestamps.</p>
      </div>
      <ReportExportBar export-path="/admin/reports/verifiers/export" :query="exportQuery" />
    </div>

    <div class="mt-6 flex flex-col gap-3 rounded-2xl border border-border bg-surface-muted/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Range</label>
        <select v-model="range" class="zaqa-input mt-1 h-10 min-w-[10rem]">
          <option value="last7">Last 7 days</option>
          <option value="last30">Last 30 days</option>
          <option value="last90">Last 90 days</option>
          <option value="ytd">Year to date</option>
          <option value="custom">Custom</option>
        </select>
      </div>
      <div v-if="range === 'custom'">
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">From</label>
        <input v-model="from" type="date" class="zaqa-input mt-1 h-10" />
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">To</label>
        <input v-model="to" type="date" class="zaqa-input mt-1 h-10" />
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Verifier</label>
        <select v-model="verifierId" class="zaqa-input mt-1 h-10 min-w-[12rem]">
          <option value="">All</option>
          <option v-for="o in verifier_options" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
        </select>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
      <DashboardChart
        chart-key="v-w"
        title="Assignments received"
        type="bar"
        :labels="wLabels"
        :values="wValues"
      />
      <DashboardChart
        chart-key="v-c"
        title="Reviews completed"
        type="bar"
        :labels="cLabels"
        :values="cValues"
      />
      <DashboardChart
        chart-key="v-p"
        title="Pending workload"
        type="bar"
        :labels="pLabels"
        :values="pValues"
      />
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">By verifier</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Verifier</th>
              <th class="px-5 py-3 text-right">Assignments</th>
              <th class="px-5 py-3 text-right">Completed</th>
              <th class="px-5 py-3 text-right">Pending</th>
              <th class="px-5 py-3 text-right">Avg review</th>
              <th class="px-5 py-3 text-right">Returned</th>
              <th class="px-5 py-3 text-right">Rejected</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="r in dashboard.rows" :key="r.user_id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ r.name }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.assignments }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.completed }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.pending }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ formatDuration(r.avg_review_seconds) }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.returned }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.rejected }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="!dashboard.rows?.length" class="px-5 py-8 text-center text-sm text-text-muted">No verifier activity in this range.</div>
      </div>
    </div>
  </AdminLayout>
</template>
