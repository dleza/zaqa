<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Banknote, Search, FileText } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'

type Paged<T> = {
  data: T[]
  links?: Array<any>
  meta?: { total?: number }
  total?: number
}

const props = defineProps<{
  filters: any
  payments: Paged<any>
}>()

const q = ref<string>(props.filters?.q ?? '')
const status = ref<string>(props.filters?.status ?? '')
const method = ref<string>(props.filters?.method ?? '')
const provider = ref<string>(props.filters?.provider ?? '')
const initiatedFrom = ref<string>(props.filters?.initiated_from ?? '')
const initiatedTo = ref<string>(props.filters?.initiated_to ?? '')
const confirmedFrom = ref<string>(props.filters?.confirmed_from ?? '')
const confirmedTo = ref<string>(props.filters?.confirmed_to ?? '')

watch([q, status, method, provider, initiatedFrom, initiatedTo, confirmedFrom, confirmedTo], () => {
  router.get(
    '/admin/finance/payments',
    {
      q: q.value || null,
      status: status.value || null,
      method: method.value || null,
      provider: provider.value || null,
      initiated_from: initiatedFrom.value || null,
      initiated_to: initiatedTo.value || null,
      confirmed_from: confirmedFrom.value || null,
      confirmed_to: confirmedTo.value || null,
    },
    { preserveScroll: true, preserveState: true, replace: true },
  )
})

function badgeClass(s: string) {
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed' || s === 'expired') return 'zaqa-badge-danger'
  if (s === 'awaiting_finance_review' || s === 'pending_confirmation' || s === 'initiated') return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
}

const totalCount = computed(() => {
  const p: any = props.payments as any
  const total = p?.meta?.total ?? p?.total
  return typeof total === 'number' ? total : Array.isArray(p?.data) ? p.data.length : 0
})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Finance
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Processed payments</h1>
        <p class="mt-1 text-sm text-text-muted">Online provider payments and manually confirmed bank payments.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/finance" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to finance</Link>
        <Link href="/admin/finance/payment-proofs" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Payment proofs</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-3 rounded-2xl border border-border bg-surface p-4 shadow-sm lg:grid-cols-6">
      <label class="lg:col-span-2">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Search</div>
        <div class="mt-2 flex items-center gap-2 rounded-xl border border-border bg-surface-muted px-3 py-2">
          <Search class="h-4 w-4 text-text-muted" aria-hidden="true" />
          <input v-model="q" class="w-full bg-transparent text-sm outline-none" placeholder="Applicant, application #, invoice #, reference…" />
        </div>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Status</div>
        <select v-model="status" class="zaqa-input mt-2 h-10">
          <option value="">All</option>
          <option value="confirmed">Confirmed</option>
          <option value="awaiting_finance_review">Awaiting finance review</option>
          <option value="pending_confirmation">Pending confirmation</option>
          <option value="initiated">Initiated</option>
          <option value="rejected">Rejected</option>
          <option value="failed">Failed</option>
          <option value="expired">Expired</option>
        </select>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Method</div>
        <select v-model="method" class="zaqa-input mt-2 h-10">
          <option value="">All</option>
          <option value="card">Card</option>
          <option value="mobile_money">Mobile Money</option>
          <option value="bank_deposit">Bank deposit</option>
          <option value="bank_transfer">Bank transfer</option>
        </select>
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Provider</div>
        <input v-model="provider" class="zaqa-input mt-2 h-10" placeholder="e.g. test" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Initiated from</div>
        <input v-model="initiatedFrom" type="date" class="zaqa-input mt-2 h-10" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Initiated to</div>
        <input v-model="initiatedTo" type="date" class="zaqa-input mt-2 h-10" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirmed from</div>
        <input v-model="confirmedFrom" type="date" class="zaqa-input mt-2 h-10" />
      </label>
      <label>
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirmed to</div>
        <input v-model="confirmedTo" type="date" class="zaqa-input mt-2 h-10" />
      </label>
      <div class="lg:col-span-6 text-xs text-text-muted">Showing {{ totalCount }} record(s).</div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div v-if="payments.data.length === 0" class="p-6 text-sm text-text-muted">No payments found for the selected filters.</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Applicant</th>
              <th class="px-5 py-3 text-left">Invoice</th>
              <th class="px-5 py-3 text-left">Method</th>
              <th class="px-5 py-3 text-left">Provider</th>
              <th class="px-5 py-3 text-left">Reference</th>
              <th class="px-5 py-3 text-right">Amount</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="p in payments.data" :key="p.id">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ p.application?.application_number ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ p.application?.is_foreign ? 'Foreign' : 'Local' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ p.applicant?.name ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ p.applicant?.email ?? p.applicant?.phone ?? '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ p.invoice?.invoice_number ?? '—' }}</div>
                <div v-if="p.invoice?.status" class="mt-0.5 text-xs text-text-muted">Invoice: {{ p.invoice.status }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ (p.method ?? '').replaceAll('_', ' ') }}</td>
              <td class="px-5 py-3 text-text-primary">{{ p.provider ?? '—' }}</td>
              <td class="px-5 py-3">
                <div class="text-xs text-text-muted">{{ p.provider_reference ?? '—' }}</div>
                <div v-if="p.provider_transaction_id" class="mt-0.5 text-xs text-text-muted">TX: {{ p.provider_transaction_id }}</div>
              </td>
              <td class="px-5 py-3 text-right font-semibold text-text-primary">{{ formatMoneyFromCents(p.amount_cents, p.currency) }}</td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="badgeClass(p.status)">{{ p.status }}</span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex flex-wrap justify-end gap-2">
                  <Link :href="`/admin/finance/payments/${p.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Open</Link>
                  <a v-if="p.proof_document" :href="p.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    <FileText class="h-4 w-4" aria-hidden="true" />
                    Proof
                  </a>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminPagination :links="payments.links ?? []" />
  </AdminLayout>
</template>

