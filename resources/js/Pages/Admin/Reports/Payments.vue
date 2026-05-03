<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DashboardChart from '@/Components/Admin/DashboardChart.vue'
import ReportExportBar from '@/Components/Reports/ReportExportBar.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { router } from '@inertiajs/vue3'
import { Banknote } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  filters: { range: string; from: string; to: string; invoice_status: string }
  dashboard: any
  table: any
  invoice_status_options: Array<{ value: string; label: string }>
}>()

const range = ref(props.filters.range ?? 'last30')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const invoiceStatus = ref(props.filters.invoice_status ?? '')

watch([range, from, to, invoiceStatus], () => {
  router.get(
    '/admin/reports/payments',
    {
      range: range.value,
      from: range.value === 'custom' ? from.value || null : null,
      to: to.value || null,
      invoice_status: invoiceStatus.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const exportQuery = computed(() => ({
  range: range.value,
  from: range.value === 'custom' ? from.value : '',
  to: to.value,
  invoice_status: invoiceStatus.value,
}))

const sum = computed(() => props.dashboard?.summary ?? {})
const revLabels = computed(() => props.dashboard?.revenue_by_month?.labels ?? [])
const revValues = computed(() => props.dashboard?.revenue_by_month?.values ?? [])
const puLabels = computed(() => props.dashboard?.paid_vs_unpaid?.labels ?? [])
const puValues = computed(() => props.dashboard?.paid_vs_unpaid?.values ?? [])
const psLabels = computed(() => props.dashboard?.primary_vs_supplementary?.labels ?? [])
const psValues = computed(() => props.dashboard?.primary_vs_supplementary?.values ?? [])

function zmw(cents: number) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZMW' }).format((cents ?? 0) / 100)
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Reports
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Payments & revenue</h1>
        <p class="mt-1 text-sm text-text-muted">Read-only invoice aggregates. Revenue uses paid invoices by paid date.</p>
      </div>
      <ReportExportBar export-path="/admin/reports/payments/export" :query="exportQuery" />
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
        <label class="block text-xs font-semibold uppercase tracking-wider text-text-muted">Invoice status</label>
        <select v-model="invoiceStatus" class="zaqa-input mt-1 h-10 min-w-[11rem]">
          <option value="">All</option>
          <option v-for="o in invoice_status_options" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-2xl border border-border bg-gradient-to-br from-emerald-500/10 to-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Invoices (issued in period)</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.invoices_generated ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Paid</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.paid_invoices ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Unpaid (issued)</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.unpaid_invoices ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Finance review queue</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.payments_awaiting_finance_review ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Total paid (period)</div>
        <div class="mt-2 text-xl font-semibold text-text-primary">{{ zmw(sum.total_paid_amount_cents ?? 0) }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Outstanding (issued)</div>
        <div class="mt-2 text-xl font-semibold text-text-primary">{{ zmw(sum.outstanding_balance_cents ?? 0) }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Primary</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.primary_invoices ?? 0 }}</div>
      </div>
      <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Supplementary</div>
        <div class="mt-2 text-2xl font-semibold text-text-primary">{{ sum.supplementary_invoices ?? 0 }}</div>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
      <DashboardChart
        chart-key="pay-rev"
        title="Paid revenue by month (ZMW)"
        type="bar"
        :labels="revLabels"
        :values="revValues"
      />
      <DashboardChart chart-key="pay-pu" title="Paid vs unpaid" type="doughnut" :labels="puLabels" :values="puValues" />
      <DashboardChart
        chart-key="pay-ps"
        title="Primary vs supplementary"
        type="bar"
        :labels="psLabels"
        :values="psValues"
      />
    </div>

    <div class="mt-8 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Invoices</div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Invoice</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Amount</th>
              <th class="px-5 py-3 text-left">Issued</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="inv in table.data" :key="inv.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-semibold text-text-primary">{{ inv.invoice_number }}</td>
              <td class="px-5 py-3 text-text-primary">{{ inv.status }}</td>
              <td class="px-5 py-3 text-right text-text-primary">{{ zmw(inv.amount_cents) }}</td>
              <td class="px-5 py-3 text-text-muted">{{ inv.issued_at ? new Date(inv.issued_at).toLocaleString() : '—' }}</td>
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
