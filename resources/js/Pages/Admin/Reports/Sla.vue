<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import { router } from '@inertiajs/vue3'
import { BarChart3, Clock, Timer } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string }
  overall: any
  level2: Array<any>
  level1: Array<any>
  qualification_metrics?: any
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

watch([range, from, to], () => {
  router.get(
    '/admin/reports/sla',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function formatDuration(sec: number | null | undefined) {
  if (sec === null || sec === undefined) return '—'
  const s = Math.max(0, Math.floor(sec))
  const m = Math.floor(s / 60)
  const h = Math.floor(m / 60)
  const d = Math.floor(h / 24)
  if (d > 0) return `${d}d ${h % 24}h`
  if (h > 0) return `${h}h ${m % 60}m`
  if (m > 0) return `${m}m`
  return `${s}s`
}

const onTimePct = computed(() => props.overall?.on_time_pct ?? 0)

const qm = computed(() => props.qualification_metrics ?? {})
const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
}))
const complianceLabels = computed(() => ['Within SLA (apps)', 'Late vs deadline'])
const complianceValues = computed(() => [
  qm.value?.compliance?.within_sla ?? 0,
  qm.value?.compliance?.overdue ?? 0,
])
const trendLabels = computed(() => qm.value?.trend_months?.labels ?? [])
const trendValues = computed(() => qm.value?.trend_months?.values ?? [])
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BarChart3 class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Turnaround & SLA</h1>
        <p class="mt-1 text-sm text-text-muted">
          Application decision SLA, qualification timing aggregates, and reviewer throughput (CSV export is aggregated).
        </p>
      </div>
      <ReportExportBar export-path="/admin/reports/sla/export" :query="exportQuery" />
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
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm lg:col-span-3">
        <div class="text-sm font-semibold text-text-primary">Qualification timing (aggregated)</div>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
          <div class="rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Submit → assignment</div>
            <div class="mt-2 font-semibold text-text-primary">{{ formatDuration(qm.avg_submission_to_assignment_sec) }}</div>
          </div>
          <div class="rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Assignment → review</div>
            <div class="mt-2 font-semibold text-text-primary">{{ formatDuration(qm.avg_assignment_to_review_sec) }}</div>
          </div>
          <div class="rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Review → certificate</div>
            <div class="mt-2 font-semibold text-text-primary">{{ formatDuration(qm.avg_review_to_certificate_sec) }}</div>
          </div>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Overdue qualifications</div>
            <div class="mt-2 text-2xl font-semibold text-text-primary">{{ qm.overdue_qualifications ?? 0 }}</div>
          </div>
          <div class="rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Active past application deadline</div>
            <div class="mt-2 text-2xl font-semibold text-text-primary">{{ qm.active_qualifications_past_deadline ?? 0 }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <DashboardChart
        chart-key="sla-compliance"
        title="Application SLA outcomes (submitted in window)"
        type="doughnut"
        :labels="complianceLabels"
        :values="complianceValues"
      />
      <DashboardChart
        chart-key="sla-trend"
        title="Avg decision turnaround (hours) by submission month"
        type="line"
        :labels="trendLabels"
        :values="trendValues"
      />
    </div>

    <div v-if="(qm.overdue_by_verifier ?? []).length" class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Overdue by verifier</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Verifier</th>
              <th class="px-5 py-3 text-right">Count</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="(r, idx) in qm.overdue_by_verifier" :key="idx" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ r.name }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.count }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="flex items-center justify-between gap-3">
          <div class="text-sm font-semibold text-text-primary">Decisions</div>
          <Timer class="h-4 w-4 text-text-muted" aria-hidden="true" />
        </div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ overall.decisions_total ?? 0 }}</div>
        <div class="mt-1 text-xs text-text-muted">Approved + rejected within period</div>
      </div>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="flex items-center justify-between gap-3">
          <div class="text-sm font-semibold text-text-primary">On-time %</div>
          <Clock class="h-4 w-4 text-text-muted" aria-hidden="true" />
        </div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ onTimePct }}%</div>
        <div class="mt-1 text-xs text-text-muted">{{ overall.on_time ?? 0 }} on-time • {{ overall.late ?? 0 }} late</div>
        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-surface-muted">
          <div class="h-full rounded-full bg-emerald-500/60 transition-[width]" :style="{ width: `${Math.min(100, onTimePct)}%` }" />
        </div>
      </div>

      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="text-sm font-semibold text-text-primary">Decision turnaround</div>
        <div class="mt-2 grid grid-cols-2 gap-3 text-sm">
          <div class="rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Avg</div>
            <div class="mt-1 font-semibold text-text-primary">{{ formatDuration(overall.turnaround_avg_sec) }}</div>
          </div>
          <div class="rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Median</div>
            <div class="mt-1 font-semibold text-text-primary">{{ formatDuration(overall.turnaround_median_sec) }}</div>
          </div>
        </div>
        <div class="mt-3 text-xs text-text-muted">Late avg: {{ formatDuration(overall.late_avg_sec) }} • Late median: {{ formatDuration(overall.late_median_sec) }}</div>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Level 2 decision performance</div>
          <div class="mt-1 text-xs text-text-muted">Grouped by the staff user who approved/rejected.</div>
        </div>

        <div v-if="level2.length === 0" class="px-5 py-6 text-sm text-text-muted">No decisions in this period.</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
              <tr>
                <th class="px-5 py-3 text-left">Reviewer</th>
                <th class="px-5 py-3 text-right">Decisions</th>
                <th class="px-5 py-3 text-right">On-time %</th>
                <th class="px-5 py-3 text-right">Avg</th>
                <th class="px-5 py-3 text-right">Median</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="r in level2" :key="r.reviewer_user_id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3">
                  <div class="font-semibold text-text-primary">{{ r.reviewer_name ?? `#${r.reviewer_user_id}` }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">Approved {{ r.approved }} • Rejected {{ r.rejected }}</div>
                </td>
                <td class="px-5 py-3 text-right font-semibold text-text-primary">{{ r.decisions_total }}</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ r.on_time_pct }}%</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ formatDuration(r.turnaround_avg_sec) }}</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ formatDuration(r.turnaround_median_sec) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div class="border-b border-border bg-surface-muted px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Level 1 throughput</div>
          <div class="mt-1 text-xs text-text-muted">Assignments received and completed handoffs to Level 2.</div>
        </div>

        <div v-if="level1.length === 0" class="px-5 py-6 text-sm text-text-muted">No Level 1 activity in this period.</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
              <tr>
                <th class="px-5 py-3 text-left">Reviewer</th>
                <th class="px-5 py-3 text-right">Assigned</th>
                <th class="px-5 py-3 text-right">Completed</th>
                <th class="px-5 py-3 text-right">Avg (assign → complete)</th>
                <th class="px-5 py-3 text-right">Median</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="r in level1" :key="r.reviewer_user_id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3 font-semibold text-text-primary">{{ r.reviewer_name ?? `#${r.reviewer_user_id}` }}</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ r.assignments_received }}</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ r.completed_handoffs }}</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ formatDuration(r.assignment_to_complete_avg_sec) }}</td>
                <td class="px-5 py-3 text-right text-text-primary">{{ formatDuration(r.assignment_to_complete_median_sec) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

