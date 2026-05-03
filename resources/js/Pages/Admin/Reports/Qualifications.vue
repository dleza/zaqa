<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import ReportStackedBarChart from '@/Components/Reports/ReportStackedBarChart.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { router } from '@inertiajs/vue3'
import { GraduationCap } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string; verification_state: string; qualification_type_id: number | null }
  dashboard: any
  table: any
  qualification_type_options: Array<{ id: number; name: string }>
  verification_state_options: Array<{ value: string; label: string }>
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const verificationState = ref(props.filters.verification_state ?? '')
const qualificationTypeId = ref(props.filters.qualification_type_id ? String(props.filters.qualification_type_id) : '')

watch([range, from, to, verificationState, qualificationTypeId], () => {
  router.get(
    '/admin/reports/qualifications',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
      verification_state: verificationState.value || null,
      qualification_type_id: qualificationTypeId.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
  verification_state: verificationState.value,
  qualification_type_id: qualificationTypeId.value,
}))

const sum = computed(() => props.dashboard?.summary ?? {})
const typeLabels = computed(() => (props.dashboard?.by_type ?? []).map((x: any) => x.label))
const typeValues = computed(() => (props.dashboard?.by_type ?? []).map((x: any) => x.value))
const lfLabels = computed(() => props.dashboard?.local_foreign?.labels ?? [])
const lfValues = computed(() => props.dashboard?.local_foreign?.values ?? [])
const stateLabels = computed(() => (props.dashboard?.by_state ?? []).map((x: any) => x.label))
const stateValues = computed(() => (props.dashboard?.by_state ?? []).map((x: any) => x.value))
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <GraduationCap class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Qualification verification</h1>
        <p class="mt-1 max-w-2xl text-sm text-text-muted">Qualification-based counts (not application aggregates).</p>
      </div>
      <ReportExportBar export-path="/admin/reports/qualifications/export" :query="exportQuery" />
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
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">State</label>
        <select v-model="verificationState" class="zaqa-input mt-1 h-10 min-w-[12rem]">
          <option value="">All</option>
          <option v-for="o in verification_state_options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification type</label>
        <select v-model="qualificationTypeId" class="zaqa-input mt-1 h-10 min-w-[12rem]">
          <option value="">All</option>
          <option v-for="o in qualification_type_options" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
        </select>
      </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
      <div class="rounded-2xl border border-border bg-gradient-to-br from-[#0076BD]/10 to-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Total</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.total ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Returned</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.returned_for_amendment ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Approved path</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.approved ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Rejected</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.rejected ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Local</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.local ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Foreign</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.foreign ?? 0 }}</div>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
      <DashboardChart
        chart-key="qt-type"
        title="By qualification type"
        type="bar"
        :labels="typeLabels"
        :values="typeValues"
      />
      <DashboardChart chart-key="qt-lf" title="Local vs foreign" type="doughnut" :labels="lfLabels" :values="lfValues" />
      <DashboardChart
        chart-key="qt-state"
        title="By verification state"
        type="bar"
        :labels="stateLabels"
        :values="stateValues"
      />
    </div>

    <div class="mt-6">
      <ReportStackedBarChart
        chart-key="qt-stacked"
        title="New qualifications by month & state"
        :labels="dashboard.stacked_by_month?.labels ?? []"
        :datasets="dashboard.stacked_by_month?.datasets ?? []"
      />
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Detail</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Ref</th>
              <th class="px-5 py-3 text-left">Type</th>
              <th class="px-5 py-3 text-left">State</th>
              <th class="px-5 py-3 text-left">Created</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="q in table.data" :key="q.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ q.verification_reference_number }}</td>
              <td class="px-5 py-3 text-text-primary">{{ q.qualification_type_label ?? q.qualification_type }}</td>
              <td class="px-5 py-3 text-text-primary">{{ q.verification_state }}</td>
              <td class="px-5 py-3 text-text-muted">{{ q.created_at ? new Date(q.created_at).toLocaleString() : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="border-t border-border px-5 py-4">
        <AdminPagination :links="table.links ?? []" />
      </div>
    </div>
  </AdminLayout>
</template>
