<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { CheckCircle2, AlertCircle, ArrowDownToLine, CreditCard, Eye } from 'lucide-vue-next'

const props = defineProps<{
  payments: Array<{
    id: number
    method: string
    status: string
    currency: string
    amount_cents: number
    provider: string
    provider_reference: string | null
    created_at: string | null
    confirmed_at: string | null
    rejection_reason: string | null
    application: { id: number; application_number: string } | null
    invoice: { id: number; invoice_number: string } | null
    proof_document: { id: number; preview_url: string; download_url: string } | null
  }>
  summary: { total_cents: number; confirmed_cents: number; count: number }
}>()

const hasItems = computed(() => props.payments && props.payments.length > 0)

function money(cents: number, currency: string) {
  const value = (cents ?? 0) / 100
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format(value)
}
</script>

<template>
  <ApplicantLayout>
    <div class="space-y-6">
      <div>
        <h2 class="text-2xl font-semibold tracking-tight text-text-primary">Payments</h2>
        <p class="mt-1 text-sm text-text-muted">Payment transactions made for your verification applications.</p>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-border bg-surface p-4 shadow-sm">
          <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Payments</div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ summary.count }}</div>
        </div>
        <div class="rounded-2xl border border-border bg-surface p-4 shadow-sm">
          <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Confirmed total</div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ money(summary.confirmed_cents, 'ZMW') }}</div>
        </div>
        <div class="rounded-2xl border border-border bg-surface p-4 shadow-sm">
          <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">All attempts total</div>
          <div class="mt-2 text-2xl font-semibold text-text-primary">{{ money(summary.total_cents, 'ZMW') }}</div>
        </div>
      </div>

      <div v-if="!hasItems" class="rounded-2xl border border-border bg-surface p-8 text-center shadow-sm">
        <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 text-brand">
          <CreditCard class="h-5 w-5" aria-hidden="true" />
        </div>
        <div class="mt-4 text-base font-semibold text-text-primary">No payments yet</div>
        <div class="mt-1 text-sm text-text-muted">Payments you make for verification applications will appear here.</div>
        <div class="mt-5">
          <Link href="/applicant/invoices" class="zaqa-btn zaqa-btn-primary h-11 px-6">View invoices</Link>
        </div>
      </div>

      <div v-else>
        <!-- Desktop table -->
        <div class="hidden overflow-hidden rounded-2xl border border-border bg-surface shadow-sm md:block">
          <table class="min-w-full divide-y divide-border/60 text-sm">
            <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wider text-text-muted">
              <tr class="text-left">
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3">Method</th>
                <th class="px-5 py-3">Reference</th>
                <th class="px-5 py-3">Amount</th>
                <th class="px-5 py-3">Application</th>
                <th class="px-5 py-3">Invoice</th>
                <th class="px-5 py-3">Date</th>
                <th class="px-5 py-3">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="p in payments" :key="p.id" class="hover:bg-surface-muted/60">
                <td class="px-5 py-3">
                  <span
                    class="zaqa-badge"
                    :class="p.status === 'confirmed' ? 'zaqa-badge-success' : p.status === 'rejected' || p.status === 'failed' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'"
                  >
                    <component :is="p.status === 'confirmed' ? CheckCircle2 : AlertCircle" class="h-4 w-4" aria-hidden="true" />
                    {{ p.status }}
                  </span>
                </td>
                <td class="px-5 py-3 text-text-primary">{{ p.method }}</td>
                <td class="px-5 py-3 text-text-muted">{{ p.provider_reference || `PAY-${p.id}` }}</td>
                <td class="px-5 py-3 font-semibold text-text-primary">{{ money(p.amount_cents, p.currency) }}</td>
                <td class="px-5 py-3">
                  <Link v-if="p.application" :href="`/applicant/applications/${p.application.id}`" class="zaqa-link">
                    {{ p.application.application_number }}
                  </Link>
                  <span v-else class="text-text-muted">—</span>
                </td>
                <td class="px-5 py-3 text-text-muted">{{ p.invoice?.invoice_number ?? '—' }}</td>
                <td class="px-5 py-3 text-text-muted">
                  {{ p.confirmed_at ? new Date(p.confirmed_at).toLocaleString() : p.created_at ? new Date(p.created_at).toLocaleString() : '—' }}
                </td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap gap-3">
                    <a v-if="p.proof_document" :href="p.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-link inline-flex items-center gap-1">
                      <Eye class="h-4 w-4" aria-hidden="true" />
                      Preview
                    </a>
                    <a v-if="p.proof_document" :href="p.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-link inline-flex items-center gap-1">
                      <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
                      Download
                    </a>
                    <span v-if="p.rejection_reason" class="text-xs text-danger">Rejected: {{ p.rejection_reason }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile cards -->
        <div class="space-y-3 md:hidden">
          <div v-for="p in payments" :key="p.id" class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-sm font-semibold text-text-primary">{{ p.method }}</div>
                <div class="mt-1 text-xs text-text-muted">{{ p.provider_reference || `PAY-${p.id}` }}</div>
              </div>
              <span
                class="zaqa-badge"
                :class="p.status === 'confirmed' ? 'zaqa-badge-success' : p.status === 'rejected' || p.status === 'failed' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'"
              >
                {{ p.status }}
              </span>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-text-muted">
              <div>
                <div class="font-semibold uppercase tracking-wider">Amount</div>
                <div class="mt-1 text-sm font-semibold text-text-primary">{{ money(p.amount_cents, p.currency) }}</div>
              </div>
              <div>
                <div class="font-semibold uppercase tracking-wider">Date</div>
                <div class="mt-1 text-sm text-text-primary">
                  {{ p.confirmed_at ? new Date(p.confirmed_at).toLocaleDateString() : p.created_at ? new Date(p.created_at).toLocaleDateString() : '—' }}
                </div>
              </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2">
              <Link v-if="p.application" :href="`/applicant/applications/${p.application.id}`" class="zaqa-btn zaqa-btn-secondary h-10 w-full justify-center px-3 text-xs">
                Application
              </Link>
              <Link v-else href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary h-10 w-full justify-center px-3 text-xs">Applications</Link>

              <a v-if="p.proof_document" :href="p.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-10 w-full justify-center px-3 text-xs">
                Preview
              </a>
              <a v-if="p.proof_document" :href="p.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-10 w-full justify-center px-3 text-xs">
                Download
              </a>
            </div>

            <div v-if="p.rejection_reason" class="mt-3 rounded-xl border border-danger/20 bg-danger/10 px-3 py-2 text-xs text-danger">
              Rejected: {{ p.rejection_reason }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>

