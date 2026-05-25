<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { Banknote, FileText } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  payment: any
  webhooks: Array<any>
}>()

function badgeClass(s: string) {
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed' || s === 'expired' || s === 'unknown') return 'zaqa-badge-danger'
  if (s === 'awaiting_finance_review' || s === 'pending_confirmation' || s === 'initiated' || s === 'pending') return 'zaqa-badge-warning'
  return 'zaqa-badge-secondary'
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Banknote class="h-4 w-4" aria-hidden="true" />
          Finance
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Payment #{{ payment.id }}</h1>
        <p class="mt-1 text-sm text-text-muted">Provider status, invoice linkage, and proof (if manual).</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Link href="/admin/finance/payments" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to payments</Link>
        <Link v-if="payment.method === 'bank_deposit' || payment.method === 'bank_transfer'" :href="`/admin/finance/payment-proofs/${payment.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          Proof detail
        </Link>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Summary</div>
              <div class="mt-1 text-lg font-semibold text-text-primary">{{ formatMoneyFromCents(payment.amount_cents, payment.currency) }}</div>
              <div class="mt-1 text-xs text-text-muted">Method: {{ payment.method.replaceAll('_', ' ') }} · Provider: {{ payment.provider ?? '—' }}</div>
            </div>
            <span class="zaqa-badge" :class="badgeClass(payment.status)">{{ payment.status }}</span>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.application?.application_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ payment.application?.is_foreign ? 'Foreign' : 'Local' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Invoice</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.invoice?.invoice_number ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Invoice status: {{ payment.invoice?.status ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Applicant</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.applicant?.name ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ payment.applicant?.email ?? payment.applicant?.phone ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">References</div>
              <div class="mt-1 text-xs text-text-muted">Ref: {{ payment.provider_reference ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">TX: {{ payment.provider_transaction_id ?? '—' }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Timeline (payment)</div>
          <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Initiated</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.initiated_at ? new Date(payment.initiated_at).toLocaleString() : '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirmed</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.confirmed_at ? new Date(payment.confirmed_at).toLocaleString() : '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Failed</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.failed_at ? new Date(payment.failed_at).toLocaleString() : '—' }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Rejected</div>
              <div class="mt-1 font-semibold text-text-primary">{{ payment.rejected_at ? new Date(payment.rejected_at).toLocaleString() : '—' }}</div>
            </div>
          </div>

          <div v-if="payment.reviewed_at || payment.review_comment || payment.rejection_reason" class="mt-4 rounded-xl border border-border bg-surface-muted p-4 text-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Manual review</div>
            <div class="mt-1 text-sm text-text-primary">Reviewed at: {{ payment.reviewed_at ? new Date(payment.reviewed_at).toLocaleString() : '—' }}</div>
            <div class="mt-1 text-sm text-text-primary">Reviewer: {{ payment.reviewed_by ?? '—' }}</div>
            <div v-if="payment.review_comment" class="mt-2 whitespace-pre-wrap text-sm text-text-primary">Comment: {{ payment.review_comment }}</div>
            <div v-if="payment.rejection_reason" class="mt-2 whitespace-pre-wrap text-sm text-danger">Reason: {{ payment.rejection_reason }}</div>
          </div>
        </div>

        <div v-if="(payment.attempts ?? []).length > 0" class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Gateway attempts</div>
          <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-4 py-3 text-left">ID</th>
                  <th class="px-4 py-3 text-left">Gateway</th>
                  <th class="px-4 py-3 text-left">Reference</th>
                  <th class="px-4 py-3 text-left">Mobile</th>
                  <th class="px-4 py-3 text-left">Status</th>
                  <th class="px-4 py-3 text-left">Code</th>
                  <th class="px-4 py-3 text-left">Message</th>
                  <th class="px-4 py-3 text-left">Queried</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="a in payment.attempts" :key="a.id">
                  <td class="px-4 py-3 font-semibold text-text-primary">#{{ a.id }}</td>
                  <td class="px-4 py-3 text-text-primary">{{ a.gateway }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ a.payment_reference ?? '—' }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ a.mobile_number ?? '—' }}</td>
                  <td class="px-4 py-3">
                    <span class="zaqa-badge" :class="badgeClass(a.status)">{{ a.status }}</span>
                  </td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ a.response_code ?? '—' }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">
                    <span v-if="a.response_message">{{ a.response_message }}</span>
                    <span v-else>—</span>
                  </td>
                  <td class="px-4 py-3 text-xs text-text-muted">
                    {{ a.last_queried_at ? new Date(a.last_queried_at).toLocaleString() : '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Webhook / return events</div>
          <div v-if="webhooks.length === 0" class="mt-3 text-sm text-text-muted">No webhook/return logs recorded for this payment.</div>
          <div v-else class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-4 py-3 text-left">Provider</th>
                  <th class="px-4 py-3 text-left">Event</th>
                  <th class="px-4 py-3 text-left">Status</th>
                  <th class="px-4 py-3 text-left">Received</th>
                  <th class="px-4 py-3 text-left">Processed</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="w in webhooks" :key="w.id">
                  <td class="px-4 py-3 font-semibold text-text-primary">{{ w.provider }}</td>
                  <td class="px-4 py-3 text-text-primary">{{ w.event_type }}</td>
                  <td class="px-4 py-3 text-text-primary">{{ w.process_status }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ w.received_at ? new Date(w.received_at).toLocaleString() : '—' }}</td>
                  <td class="px-4 py-3 text-xs text-text-muted">{{ w.processed_at ? new Date(w.processed_at).toLocaleString() : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <aside class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Proof (manual)</div>
          <div v-if="payment.proof_document" class="mt-3">
            <div class="rounded-xl border border-border bg-surface-muted p-4 text-sm">
              <div class="font-semibold text-text-primary">{{ payment.proof_document.original_name }}</div>
              <div class="mt-3 flex flex-wrap gap-2">
                <a :href="payment.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                  <FileText class="h-4 w-4" aria-hidden="true" />
                  Preview
                </a>
                <a :href="payment.proof_document.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Download</a>
              </div>
            </div>
          </div>
          <div v-else class="mt-3 text-sm text-text-muted">No proof document for this payment.</div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Raw payload</div>
          <div v-if="payment.raw_payload" class="mt-3 overflow-hidden rounded-xl border border-border bg-surface-muted">
            <pre class="max-h-[22rem] overflow-auto p-4 text-xs text-text-primary">{{ JSON.stringify(payment.raw_payload, null, 2) }}</pre>
          </div>
          <div v-else class="mt-3 text-sm text-text-muted">No raw payload stored.</div>
        </div>
      </aside>
    </div>
  </AdminLayout>
</template>
