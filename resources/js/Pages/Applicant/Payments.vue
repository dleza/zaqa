<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
  AlertCircle,
  ArrowDownToLine,
  ArrowRight,
  CheckCircle2,
  CreditCard,
  Eye,
  Receipt,
  ReceiptText,
  TrendingUp,
  Wallet,
} from 'lucide-vue-next'

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
    receipt_download_url: string | null
  }>
  summary: { total_cents: number; confirmed_cents: number; count: number }
}>()

const hasItems = computed(() => props.payments && props.payments.length > 0)

function money(cents: number, currency: string) {
  const value = (cents ?? 0) / 100
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format(value)
}

function formatWhen(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return '—'
  }
}

function statusBadgeClass(status: string) {
  const s = (status ?? '').toLowerCase()
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
}

function humanMethod(m: string) {
  return (m ?? '').replace(/_/g, ' ')
}
</script>

<template>
  <ApplicantLayout>
    <div class="relative min-h-[40vh]">
      <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-16 top-0 h-64 w-64 rounded-full bg-brand/10 blur-3xl" />
        <div class="absolute right-0 top-20 h-72 w-72 rounded-full bg-accent/10 blur-3xl" />
      </div>

      <div class="zaqa-wizard-shell space-y-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Billing</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">Payments</h1>
        
          </div>
          <div class="flex flex-wrap gap-2 self-start">
            <Link
              href="/applicant/receipts"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold"
            >
              <Receipt class="h-4 w-4 opacity-80" aria-hidden="true" />
              Receipts
              <ArrowRight class="h-4 w-4 opacity-70" aria-hidden="true" />
            </Link>
            <Link
              href="/applicant/invoices"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold"
            >
              <ReceiptText class="h-4 w-4 opacity-80" aria-hidden="true" />
              Invoices
              <ArrowRight class="h-4 w-4 opacity-70" aria-hidden="true" />
            </Link>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div
            class="rounded-3xl border border-border/80 bg-surface p-5 shadow-[0_8px_30px_-8px_rgba(0,0,0,0.06)] ring-1 ring-black/[0.03]"
          >
            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-text-muted">
              <Wallet class="h-4 w-4 text-brand" aria-hidden="true" />
              Payment attempts
            </div>
            <div class="mt-3 text-3xl font-bold tabular-nums text-text-primary">{{ summary.count }}</div>
          </div>
          <div
            class="rounded-3xl border border-border/80 bg-surface p-5 shadow-[0_8px_30px_-8px_rgba(0,0,0,0.06)] ring-1 ring-black/[0.03]"
          >
            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-text-muted">
              <TrendingUp class="h-4 w-4 text-emerald-700" aria-hidden="true" />
              Confirmed total
            </div>
            <div class="mt-3 text-3xl font-bold tabular-nums text-text-primary">{{ money(summary.confirmed_cents, 'ZMW') }}</div>
          </div>
          <div
            class="rounded-3xl border border-border/80 bg-surface p-5 shadow-[0_8px_30px_-8px_rgba(0,0,0,0.06)] ring-1 ring-black/[0.03]"
          >
            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-text-muted">
              <CreditCard class="h-4 w-4 text-text-muted" aria-hidden="true" />
              All attempts total
            </div>
            <div class="mt-3 text-3xl font-bold tabular-nums text-text-primary">{{ money(summary.total_cents, 'ZMW') }}</div>
          </div>
        </div>

        <div v-if="!hasItems" class="rounded-3xl border border-dashed border-border bg-surface-muted/40 px-8 py-14 text-center">
          <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 text-brand">
            <CreditCard class="h-7 w-7" aria-hidden="true" />
          </div>
          <div class="mt-5 text-lg font-semibold text-text-primary">No payments yet</div>
          <div class="mt-2 text-sm text-text-muted">When you pay for an application, each attempt appears here with a detailed view.</div>
          <div class="mt-8">
            <Link href="/applicant/invoices" class="zaqa-btn zaqa-btn-primary px-6 py-2.5 text-sm font-semibold">Browse invoices</Link>
          </div>
        </div>

        <div v-else>
          <!-- Desktop -->
          <div class="hidden overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-sm md:block">
            <table class="min-w-full divide-y divide-border/60 text-sm">
              <thead class="bg-gradient-to-r from-surface-muted to-surface-muted/80 text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                <tr>
                  <th class="px-5 py-4">Status</th>
                  <th class="px-5 py-4">Method</th>
                  <th class="px-5 py-4">Reference</th>
                  <th class="px-5 py-4">Amount</th>
                  <th class="px-5 py-4">Application</th>
                  <th class="px-5 py-4">Invoice</th>
                  <th class="px-5 py-4">When</th>
                  <th class="px-5 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="p in payments" :key="p.id" class="transition hover:bg-surface-muted/50">
                  <td class="px-5 py-4">
                    <span class="zaqa-badge inline-flex items-center gap-1 text-xs capitalize" :class="statusBadgeClass(p.status)">
                      <component :is="p.status === 'confirmed' ? CheckCircle2 : AlertCircle" class="h-3.5 w-3.5" aria-hidden="true" />
                      {{ p.status }}
                    </span>
                  </td>
                  <td class="px-5 py-4 capitalize text-text-primary">{{ humanMethod(p.method) }}</td>
                  <td class="max-w-[140px] truncate px-5 py-4 font-mono text-xs text-text-muted" :title="p.provider_reference || ''">
                    {{ p.provider_reference || `PAY-${p.id}` }}
                  </td>
                  <td class="px-5 py-4 font-semibold tabular-nums text-text-primary">{{ money(p.amount_cents, p.currency) }}</td>
                  <td class="px-5 py-4">
                    <Link v-if="p.application" :href="`/applicant/applications/${p.application.id}`" class="zaqa-link font-mono text-xs font-semibold">
                      {{ p.application.application_number }}
                    </Link>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-5 py-4">
                    <Link
                      v-if="p.invoice"
                      :href="`/applicant/invoices/${p.invoice.id}`"
                      class="zaqa-link font-mono text-xs font-semibold"
                    >
                      {{ p.invoice.invoice_number }}
                    </Link>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-5 py-4 text-xs text-text-muted">{{ formatWhen(p.confirmed_at || p.created_at) }}</td>
                  <td class="px-5 py-4 text-right">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                      <Link
                        :href="`/applicant/payments/${p.id}`"
                        class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                        View
                      </Link>
                      <a
                        v-if="p.receipt_download_url"
                        :href="p.receipt_download_url"
                        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <ArrowDownToLine class="h-3.5 w-3.5" aria-hidden="true" />
                        Download receipt
                      </a>
                      <a
                        v-if="p.proof_document"
                        :href="p.proof_document.preview_url"
                        target="_blank"
                        rel="noopener"
                        class="zaqa-link text-xs font-semibold"
                      >
                        Proof
                      </a>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Mobile -->
          <div class="space-y-4 md:hidden">
            <div
              v-for="p in payments"
              :key="p.id"
              class="overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-sm"
            >
              <div class="flex items-start justify-between gap-3 border-b border-border/60 bg-surface-muted/50 px-5 py-4">
                <div class="min-w-0">
                  <div class="font-semibold capitalize text-text-primary">{{ humanMethod(p.method) }}</div>
                  <div class="mt-1 font-mono text-xs text-text-muted">{{ p.provider_reference || `PAY-${p.id}` }}</div>
                </div>
                <span class="zaqa-badge shrink-0 text-[10px] capitalize" :class="statusBadgeClass(p.status)">{{ p.status }}</span>
              </div>
              <div class="space-y-4 px-5 py-4">
                <div class="flex items-baseline justify-between gap-4">
                  <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Amount</span>
                  <span class="text-lg font-bold tabular-nums text-text-primary">{{ money(p.amount_cents, p.currency) }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                  <div>
                    <div class="font-semibold uppercase tracking-wider text-text-muted">Application</div>
                    <Link v-if="p.application" :href="`/applicant/applications/${p.application.id}`" class="zaqa-link mt-1 block font-mono font-semibold">
                      {{ p.application.application_number }}
                    </Link>
                    <span v-else class="mt-1 block text-text-muted">—</span>
                  </div>
                  <div>
                    <div class="font-semibold uppercase tracking-wider text-text-muted">Invoice</div>
                    <Link v-if="p.invoice" :href="`/applicant/invoices/${p.invoice.id}`" class="zaqa-link mt-1 block font-mono font-semibold">
                      {{ p.invoice.invoice_number }}
                    </Link>
                    <span v-else class="mt-1 block text-text-muted">—</span>
                  </div>
                </div>
                <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Recorded</div>
                <div class="text-sm text-text-primary">{{ formatWhen(p.confirmed_at || p.created_at) }}</div>
                <div class="flex flex-wrap gap-2">
                  <Link
                    :href="`/applicant/payments/${p.id}`"
                    class="zaqa-btn zaqa-btn-primary inline-flex flex-1 items-center justify-center gap-2 py-2.5 text-xs font-semibold"
                  >
                    <Eye class="h-4 w-4" aria-hidden="true" />
                    View payment
                  </Link>
                  <a
                    v-if="p.receipt_download_url"
                    :href="p.receipt_download_url"
                    class="zaqa-btn zaqa-btn-secondary inline-flex flex-1 items-center justify-center gap-2 py-2.5 text-xs font-semibold"
                  >
                    <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
                    Download receipt
                  </a>
                  <a
                    v-if="p.proof_document"
                    :href="p.proof_document.preview_url"
                    target="_blank"
                    rel="noopener"
                    class="zaqa-btn zaqa-btn-secondary inline-flex flex-1 items-center justify-center py-2.5 text-xs font-semibold"
                  >
                    Proof
                  </a>
                </div>
                <div v-if="p.rejection_reason" class="rounded-xl border border-danger/20 bg-danger/10 px-3 py-2 text-xs text-danger">
                  {{ p.rejection_reason }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
