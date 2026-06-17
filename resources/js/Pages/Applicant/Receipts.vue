<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { ArrowDownToLine, ArrowRight, CheckCircle2, Eye, Receipt, Wallet } from 'lucide-vue-next'

const props = defineProps<{
  receipts: Array<{
    id: number
    receipt_number_display: string
    method: string
    currency: string
    amount_cents: number
    provider_reference: string | null
    confirmed_at: string | null
    application: { id: number; application_number: string } | null
    invoice: { id: number; invoice_number: string } | null
    show_url: string
    receipt_download_url: string | null
  }>
  summary: { count: number; total_cents: number }
}>()

const hasItems = computed(() => props.receipts && props.receipts.length > 0)

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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">Receipts</h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
              Official receipts for successful payments only. Download a PDF for your records or open
              <strong class="text-text-primary">View</strong> to preview the receipt on screen.
            </p>
          </div>
          <Link
            href="/applicant/payments"
            class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 self-start px-4 py-2.5 text-sm font-semibold"
          >
            <Wallet class="h-4 w-4 opacity-80" aria-hidden="true" />
            All payments
            <ArrowRight class="h-4 w-4 opacity-70" aria-hidden="true" />
          </Link>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div
            class="rounded-3xl border border-border/80 bg-surface p-5 shadow-[0_8px_30px_-8px_rgba(0,0,0,0.06)] ring-1 ring-black/[0.03]"
          >
            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-text-muted">
              <Receipt class="h-4 w-4 text-brand" aria-hidden="true" />
              Successful receipts
            </div>
            <div class="mt-3 text-3xl font-bold tabular-nums text-text-primary">{{ summary.count }}</div>
          </div>
          <div
            class="rounded-3xl border border-border/80 bg-surface p-5 shadow-[0_8px_30px_-8px_rgba(0,0,0,0.06)] ring-1 ring-black/[0.03]"
          >
            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-text-muted">
              <CheckCircle2 class="h-4 w-4 text-emerald-700" aria-hidden="true" />
              Total confirmed
            </div>
            <div class="mt-3 text-3xl font-bold tabular-nums text-text-primary">{{ money(summary.total_cents, 'ZMW') }}</div>
          </div>
        </div>

        <div v-if="!hasItems" class="rounded-3xl border border-dashed border-border bg-surface-muted/40 px-8 py-14 text-center">
          <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 text-brand">
            <Receipt class="h-7 w-7" aria-hidden="true" />
          </div>
          <div class="mt-5 text-lg font-semibold text-text-primary">No receipts yet</div>
          <div class="mt-2 text-sm text-text-muted">
            Receipts appear here once a payment is confirmed. Pending or failed attempts stay on the payments page.
          </div>
          <div class="mt-8">
            <Link href="/applicant/payments" class="zaqa-btn zaqa-btn-primary px-6 py-2.5 text-sm font-semibold">View payments</Link>
          </div>
        </div>

        <div v-else>
          <div class="hidden overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-sm md:block">
            <table class="min-w-full divide-y divide-border/60 text-sm">
              <thead class="bg-gradient-to-r from-surface-muted to-surface-muted/80 text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                <tr>
                  <th class="px-5 py-4">Receipt</th>
                  <th class="px-5 py-4">Method</th>
                  <th class="px-5 py-4">Amount</th>
                  <th class="px-5 py-4">Application</th>
                  <th class="px-5 py-4">Invoice</th>
                  <th class="px-5 py-4">Confirmed</th>
                  <th class="px-5 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="r in receipts" :key="r.id" class="transition hover:bg-surface-muted/50">
                  <td class="px-5 py-4 font-mono text-sm font-semibold text-text-primary">{{ r.receipt_number_display }}</td>
                  <td class="px-5 py-4 capitalize text-text-primary">{{ humanMethod(r.method) }}</td>
                  <td class="px-5 py-4 font-semibold tabular-nums text-text-primary">{{ money(r.amount_cents, r.currency) }}</td>
                  <td class="px-5 py-4">
                    <Link v-if="r.application" :href="`/applicant/applications/${r.application.id}`" class="zaqa-link font-mono text-xs font-semibold">
                      {{ r.application.application_number }}
                    </Link>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-5 py-4">
                    <Link
                      v-if="r.invoice"
                      :href="`/applicant/invoices/${r.invoice.id}`"
                      class="zaqa-link font-mono text-xs font-semibold"
                    >
                      {{ r.invoice.invoice_number }}
                    </Link>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-5 py-4 text-xs text-text-muted">{{ formatWhen(r.confirmed_at) }}</td>
                  <td class="px-5 py-4 text-right">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                      <Link
                        :href="r.show_url"
                        class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                        View
                      </Link>
                      <a
                        v-if="r.receipt_download_url"
                        :href="r.receipt_download_url"
                        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <ArrowDownToLine class="h-3.5 w-3.5" aria-hidden="true" />
                        Download
                      </a>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="space-y-4 md:hidden">
            <div
              v-for="r in receipts"
              :key="r.id"
              class="overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-sm"
            >
              <div class="flex items-start justify-between gap-3 border-b border-border/60 bg-surface-muted/50 px-5 py-4">
                <div class="min-w-0">
                  <div class="font-mono text-sm font-semibold text-text-primary">{{ r.receipt_number_display }}</div>
                  <div class="mt-1 capitalize text-xs text-text-muted">{{ humanMethod(r.method) }}</div>
                </div>
                <span class="zaqa-badge zaqa-badge-success shrink-0 text-[10px]">Confirmed</span>
              </div>
              <div class="space-y-4 px-5 py-4">
                <div class="flex items-baseline justify-between gap-4">
                  <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Amount</span>
                  <span class="text-lg font-bold tabular-nums text-text-primary">{{ money(r.amount_cents, r.currency) }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                  <div>
                    <div class="font-semibold uppercase tracking-wider text-text-muted">Application</div>
                    <Link v-if="r.application" :href="`/applicant/applications/${r.application.id}`" class="zaqa-link mt-1 block font-mono font-semibold">
                      {{ r.application.application_number }}
                    </Link>
                    <span v-else class="mt-1 block text-text-muted">—</span>
                  </div>
                  <div>
                    <div class="font-semibold uppercase tracking-wider text-text-muted">Invoice</div>
                    <Link v-if="r.invoice" :href="`/applicant/invoices/${r.invoice.id}`" class="zaqa-link mt-1 block font-mono font-semibold">
                      {{ r.invoice.invoice_number }}
                    </Link>
                    <span v-else class="mt-1 block text-text-muted">—</span>
                  </div>
                </div>
                <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Confirmed</div>
                <div class="text-sm text-text-primary">{{ formatWhen(r.confirmed_at) }}</div>
                <div class="flex flex-wrap gap-2">
                  <Link
                    :href="r.show_url"
                    class="zaqa-btn zaqa-btn-primary inline-flex flex-1 items-center justify-center gap-2 py-2.5 text-xs font-semibold"
                  >
                    <Eye class="h-4 w-4" aria-hidden="true" />
                    View receipt
                  </Link>
                  <a
                    v-if="r.receipt_download_url"
                    :href="r.receipt_download_url"
                    class="zaqa-btn zaqa-btn-secondary inline-flex flex-1 items-center justify-center gap-2 py-2.5 text-xs font-semibold"
                  >
                    <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
                    Download PDF
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
