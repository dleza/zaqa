<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import { Link, router } from '@inertiajs/vue3'
import { BarChart3 } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string }
  dashboard: {
    summary: { assigned: number; processed: number }
    assigned_chart: { labels: string[]; values: number[] }
    processed_chart: { labels: string[]; values: number[] }
    recent_processed: Array<{ id: number; title: string; subtitle: string; href: string }>
  }
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

watch([range, from, to], () => {
  router.get(
    '/admin/reports/my-performance',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const assignedLabels = computed(() => props.dashboard?.assigned_chart?.labels ?? [])
const assignedValues = computed(() => props.dashboard?.assigned_chart?.values ?? [])
const processedLabels = computed(() => props.dashboard?.processed_chart?.labels ?? [])
const processedValues = computed(() => props.dashboard?.processed_chart?.values ?? [])
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BarChart3 class="h-4 w-4" aria-hidden="true" />
          My reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">My performance</h1>
        <p class="mt-1 max-w-2xl text-sm text-text-muted">
          Assignment and Level 1 processing history for your account. Use longer ranges here — the dashboard shows a fast 30-day snapshot only.
        </p>
      </div>
      <Link href="/admin/dashboard" class="zaqa-btn zaqa-btn-secondary h-10 px-4 text-sm">Back to dashboard</Link>
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

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Assigned to me</div>
        <div class="mt-2 text-3xl font-bold tabular-nums text-text-primary">{{ dashboard.summary.assigned }}</div>
        <p class="mt-1 text-xs text-text-muted">Distinct qualifications assigned in the selected period.</p>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Processed</div>
        <div class="mt-2 text-3xl font-bold tabular-nums text-text-primary">{{ dashboard.summary.processed }}</div>
        <p class="mt-1 text-xs text-text-muted">Level 1 reviews you completed in the selected period.</p>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
      <DashboardChart
        chart-key="l1-assigned-range"
        title="Assignments by day"
        type="bar"
        :labels="assignedLabels"
        :values="assignedValues"
      />
      <DashboardChart
        chart-key="l1-processed-range"
        title="Processed by day"
        type="bar"
        :labels="processedLabels"
        :values="processedValues"
      />
    </div>

    <div class="mt-8 rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border px-5 py-4">
        <h2 class="text-sm font-semibold text-text-primary">Recently processed in this range</h2>
      </div>
      <div v-if="!dashboard.recent_processed.length" class="px-5 py-8 text-center text-sm text-text-muted">
        No completed Level 1 reviews in this period.
      </div>
      <ul v-else class="divide-y divide-border/70">
        <li v-for="row in dashboard.recent_processed" :key="row.id" class="px-5 py-3">
          <Link :href="row.href" class="group block">
            <div class="text-sm font-semibold text-text-primary group-hover:text-[#0076BD]">{{ row.title }}</div>
            <div class="mt-0.5 text-xs text-text-muted">{{ row.subtitle }}</div>
          </Link>
        </li>
      </ul>
    </div>
  </AdminLayout>
</template>
