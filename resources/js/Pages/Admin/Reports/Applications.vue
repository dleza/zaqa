<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { router } from '@inertiajs/vue3'
import { BarChart3 } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string; status: string; applicant_type: string }
  dashboard: any
  table: any
  status_options: Array<{ value: string; label: string }>
  applicant_type_options: Array<{ value: string; label: string }>
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const status = ref(props.filters.status ?? '')
const applicantType = ref(props.filters.applicant_type ?? '')

watch([range, from, to, status, applicantType], () => {
  router.get(
    '/admin/reports/applications',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
      status: status.value || null,
      applicant_type: applicantType.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
  status: status.value,
  applicant_type: applicantType.value,
}))

const s = computed(() => props.dashboard?.summary ?? {})
const statusLabels = computed(() => (props.dashboard?.by_status ?? []).map((x: any) => x.label))
const statusValues = computed(() => (props.dashboard?.by_status ?? []).map((x: any) => x.value))
const subLabels = computed(() => props.dashboard?.submissions_over_time?.labels ?? [])
const subValues = computed(() => props.dashboard?.submissions_over_time?.values ?? [])
const appTypeLabels = computed(() => props.dashboard?.applicant_type?.labels ?? [])
const appTypeValues = computed(() => props.dashboard?.applicant_type?.values ?? [])
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BarChart3 class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Applications overview</h1>
        <p class="mt-1 max-w-2xl text-sm text-text-muted">
          Cohort by application created date; submission timeline uses submitted date within the same filters.
        </p>
      </div>
      <ReportExportBar export-path="/admin/reports/applications/export" :query="exportQuery" />
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
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Status</label>
        <select v-model="status" class="zaqa-input mt-1 h-10 min-w-[11rem]">
          <option value="">All</option>
          <option v-for="o in status_options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Applicant type</label>
        <select v-model="applicantType" class="zaqa-input mt-1 h-10 min-w-[10rem]">
          <option value="">All</option>
          <option v-for="o in applicant_type_options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-2xl border border-border bg-gradient-to-br from-[#0076BD]/10 to-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Total (created in period)</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.total ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Draft</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.draft ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Submitted / resubmitted</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.submitted ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Approved / completed</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.approved_or_completed ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Sent back</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.sent_back ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Rejected</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.rejected ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm sm:col-span-2">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">In progress / pending payment</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ s.in_progress ?? 0 }}</div>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
      <DashboardChart
        chart-key="app-status"
        title="By status"
        type="doughnut"
        :labels="statusLabels"
        :values="statusValues"
      />
      <DashboardChart
        chart-key="app-submissions"
        title="Submissions over time"
        type="line"
        :labels="subLabels"
        :values="subValues"
      />
      <DashboardChart
        chart-key="app-applicant-type"
        title="Applicant type"
        type="bar"
        :labels="appTypeLabels"
        :values="appTypeValues"
      />
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Detail</div>
        <div class="mt-1 text-xs text-text-muted">Paginated — same filters as above.</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Number</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Applicant type</th>
              <th class="px-5 py-3 text-left">Submitted</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="a in table.data" :key="a.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ a.application_number }}</td>
              <td class="px-5 py-3 text-text-primary">{{ a.current_status }}</td>
              <td class="px-5 py-3 text-text-primary">{{ a.applicant_type }}</td>
              <td class="px-5 py-3 text-text-muted">{{ a.submitted_at ? new Date(a.submitted_at).toLocaleString() : '—' }}</td>
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
