<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { router } from '@inertiajs/vue3'
import { Building2 } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string; awarding_institution_id: number | null; foreign_qualification: string }
  dashboard: any
  table: any
  institution_options: Array<{ id: number; name: string }>
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const institutionId = ref(props.filters.awarding_institution_id ? String(props.filters.awarding_institution_id) : '')
const foreignQual = ref(props.filters.foreign_qualification ?? '')

watch([range, from, to, institutionId, foreignQual], () => {
  router.get(
    '/admin/reports/awarding-institutions',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
      awarding_institution_id: institutionId.value || null,
      foreign_qualification: foreignQual.value === '' ? null : foreignQual.value,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
  awarding_institution_id: institutionId.value,
  foreign_qualification: foreignQual.value,
}))

const topNames = computed(() => (props.dashboard?.top_institutions ?? []).map((x: any) => x.name))
const topCounts = computed(() => (props.dashboard?.top_institutions ?? []).map((x: any) => x.count))
const lfLabels = computed(() => props.dashboard?.local_foreign_qualifications?.labels ?? [])
const lfValues = computed(() => props.dashboard?.local_foreign_qualifications?.values ?? [])
const sum = computed(() => props.dashboard?.summary ?? {})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Building2 class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Awarding institutions</h1>
        <p class="mt-1 text-sm text-text-muted">Qualification volume and consent coverage (cohort by qualification created date).</p>
      </div>
      <ReportExportBar export-path="/admin/reports/awarding-institutions/export" :query="exportQuery" />
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
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Institution</label>
        <select v-model="institutionId" class="zaqa-input mt-1 h-10 min-w-[12rem]">
          <option value="">All</option>
          <option v-for="o in institution_options" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification</label>
        <select v-model="foreignQual" class="zaqa-input mt-1 h-10 min-w-[10rem]">
          <option value="">All</option>
          <option value="0">Local</option>
          <option value="1">Foreign</option>
        </select>
      </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
      <div class="rounded-2xl border border-border bg-gradient-to-br from-[#0076BD]/10 to-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualifications</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.qualifications_total ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Linked institution</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.with_institution_id ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Local qual.</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.local_qualifications ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Foreign qual.</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.foreign_qualifications ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-amber-500/10 p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Institutions missing consent</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ dashboard.institutions_missing_consent ?? 0 }}</div>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
      <DashboardChart chart-key="ai-top" title="Top institutions" type="bar" :labels="topNames" :values="topCounts" />
      <DashboardChart chart-key="ai-lf" title="Local vs foreign" type="doughnut" :labels="lfLabels" :values="lfValues" />
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Returned / rejected by institution</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-right">Returned</th>
              <th class="px-5 py-3 text-right">Rejected</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="(r, idx) in dashboard.returned_rejected_by_institution" :key="idx" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ r.name }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.returned }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ r.rejected }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Detail</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-left">State</th>
              <th class="px-5 py-3 text-left">Foreign</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="q in table.data" :key="q.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ q.institution?.name ?? q.awarding_institution_name }}</td>
              <td class="px-5 py-3 text-text-primary">{{ q.verification_state }}</td>
              <td class="px-5 py-3 text-text-primary">{{ q.is_foreign_qualification ? 'Yes' : 'No' }}</td>
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
