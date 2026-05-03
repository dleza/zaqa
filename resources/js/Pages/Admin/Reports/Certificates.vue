<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { router } from '@inertiajs/vue3'
import { BadgeCheck } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string; qualification_type_id: number | null; awarding_institution_id: number | null }
  dashboard: any
  table: any
  qualification_type_options: Array<{ id: number; name: string }>
  institution_options: Array<{ id: number; name: string }>
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const qtId = ref(props.filters.qualification_type_id ? String(props.filters.qualification_type_id) : '')
const aiId = ref(props.filters.awarding_institution_id ? String(props.filters.awarding_institution_id) : '')

watch([range, from, to, qtId, aiId], () => {
  router.get(
    '/admin/reports/certificates',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
      qualification_type_id: qtId.value || null,
      awarding_institution_id: aiId.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
  qualification_type_id: qtId.value,
  awarding_institution_id: aiId.value,
}))

const sum = computed(() => props.dashboard?.summary ?? {})
const monthLabels = computed(() => props.dashboard?.by_month?.labels ?? [])
const monthValues = computed(() => props.dashboard?.by_month?.values ?? [])
const typeLabels = computed(() => (props.dashboard?.by_qualification_type ?? []).map((x: any) => x.label))
const typeValues = computed(() => (props.dashboard?.by_qualification_type ?? []).map((x: any) => x.value))
const instNames = computed(() => (props.dashboard?.by_institution ?? []).map((x: any) => x.name))
const instCounts = computed(() => (props.dashboard?.by_institution ?? []).map((x: any) => x.count))
const stLabels = computed(() => props.dashboard?.by_status?.labels ?? [])
const stValues = computed(() => props.dashboard?.by_status?.values ?? [])
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <BadgeCheck class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Certificates issued</h1>
        <p class="mt-1 text-sm text-text-muted">Issued certificates only — read-only analytics.</p>
      </div>
      <ReportExportBar export-path="/admin/reports/certificates/export" :query="exportQuery" />
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
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification type</label>
        <select v-model="qtId" class="zaqa-input mt-1 h-10 min-w-[12rem]">
          <option value="">All</option>
          <option v-for="o in qualification_type_options" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Institution</label>
        <select v-model="aiId" class="zaqa-input mt-1 h-10 min-w-[12rem]">
          <option value="">All</option>
          <option v-for="o in institution_options" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
        </select>
      </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-2xl border border-border bg-gradient-to-br from-emerald-500/10 to-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Total</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ sum.total ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Issued</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ sum.issued ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Reissued</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ sum.reissued ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Revoked</div>
        <div class="mt-2 text-3xl font-semibold text-text-primary">{{ sum.revoked ?? 0 }}</div>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
      <DashboardChart
        chart-key="cert-month"
        title="Issued by month"
        type="line"
        :labels="monthLabels"
        :values="monthValues"
      />
      <DashboardChart chart-key="cert-status" title="By status" type="doughnut" :labels="stLabels" :values="stValues" />
      <DashboardChart
        chart-key="cert-type"
        title="By qualification type"
        type="bar"
        :labels="typeLabels"
        :values="typeValues"
      />
      <DashboardChart
        chart-key="cert-inst"
        title="Top awarding institutions"
        type="bar"
        :labels="instNames"
        :values="instCounts"
      />
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Certificates</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Certificate #</th>
              <th class="px-5 py-3 text-left">Issued</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Qualification</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="c in table.data" :key="c.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ c.certificate_number }}</td>
              <td class="px-5 py-3 text-text-muted">{{ c.issued_at ? new Date(c.issued_at).toLocaleString() : '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ c.status }}</td>
              <td class="px-5 py-3 text-text-primary">{{ c.qualification?.title }}</td>
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
