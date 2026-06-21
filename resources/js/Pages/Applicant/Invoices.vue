<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { ArrowRight, Eye, FileDown, FileText, Receipt, Wallet } from 'lucide-vue-next'

type BillingDocument = {
  id: number
  invoice_number: string
  document_type?: 'quotation' | 'invoice' | string
  document_title?: string
  document_number?: string
  download_label?: string
  quotation_number?: string | null
  expires_at?: string | null
  converted_to_invoice_at?: string | null
  currency: string
  amount_cents: number
  status: string
  issued_at: string | null
  paid_at: string | null
  application: { id: number; application_number: string; current_status: string } | null
  download_url: string
}

const props = defineProps<{
  invoices: BillingDocument[]
}>()

const hasDocuments = computed(() => props.invoices && props.invoices.length > 0)

const quotationCount = computed(
  () => props.invoices.filter((row) => row.document_type === 'quotation').length,
)
const invoiceCount = computed(
  () => props.invoices.filter((row) => row.document_type === 'invoice').length,
)

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

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(new Date(iso))
  } catch {
    return '—'
  }
}

function displayNumber(row: BillingDocument): string {
  return (row.document_number ?? row.invoice_number ?? '').toString().trim() || '—'
}

function isQuotation(row: BillingDocument): boolean {
  return row.document_type === 'quotation'
}

function isConvertedInvoice(row: BillingDocument): boolean {
  return row.document_type === 'invoice' && !!(row.converted_to_invoice_at || row.quotation_number)
}

function typeBadgeClass(row: BillingDocument): string {
  if (isQuotation(row)) return 'zaqa-badge-warning'
  return 'zaqa-badge-success'
}

function typeLabel(row: BillingDocument): string {
  if (row.document_title) return row.document_title
  return isQuotation(row) ? 'Quotation' : 'Invoice'
}

function lifecycleHint(row: BillingDocument): string | null {
  if (isQuotation(row)) {
    if ((row.status ?? '').toLowerCase() === 'expired') return 'This quotation has expired.'
    if (row.expires_at) return `Expires ${formatDate(row.expires_at)}`
    return 'Awaiting payment'
  }

  if (isConvertedInvoice(row) && row.quotation_number) {
    const converted = row.converted_to_invoice_at
      ? ` · converted ${formatDate(row.converted_to_invoice_at)}`
      : ''
    return `Converted from quotation ${row.quotation_number}${converted}`
  }

  if (row.document_type === 'invoice') return 'Issued invoice'

  return null
}

function statusLabel(status: string): string {
  const s = (status ?? '').toLowerCase()
  if (s === 'paid') return 'Paid'
  if (s === 'issued') return 'Pending payment'
  if (s === 'expired') return 'Expired'
  if (s === 'void') return 'Cancelled'
  return s.replaceAll('_', ' ')
}

function statusBadgeClass(status: string) {
  const s = (status ?? '').toLowerCase()
  if (s === 'paid') return 'zaqa-badge-success'
  if (s === 'void' || s === 'expired') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
}

function downloadLabel(row: BillingDocument): string {
  return row.download_label ?? (isQuotation(row) ? 'Download quotation' : 'Download invoice')
}

function viewLabel(row: BillingDocument): string {
  return isQuotation(row) ? 'View quotation' : 'View invoice'
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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">Invoices &amp; quotations</h1>
           
            <div v-if="hasDocuments" class="mt-4 flex flex-wrap gap-2 text-xs">
              <span class="rounded-full border border-warning/30 bg-warning/10 px-3 py-1 font-semibold text-warning">
                {{ quotationCount }} quotation{{ quotationCount === 1 ? '' : 's' }}
              </span>
              <span class="rounded-full border border-success/30 bg-success/10 px-3 py-1 font-semibold text-success">
                {{ invoiceCount }} invoice{{ invoiceCount === 1 ? '' : 's' }}
              </span>
            </div>
          </div>
          <Link
            href="/applicant/payments"
            class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 self-start px-4 py-2.5 text-sm font-semibold"
          >
            <Wallet class="h-4 w-4 opacity-80" aria-hidden="true" />
            Payments
            <ArrowRight class="h-4 w-4 opacity-70" aria-hidden="true" />
          </Link>
        </div>

        <div v-if="!hasDocuments" class="rounded-3xl border border-dashed border-border bg-surface-muted/40 px-8 py-14 text-center">
          <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 text-brand">
            <Receipt class="h-7 w-7" aria-hidden="true" />
          </div>
          <div class="mt-5 text-lg font-semibold text-text-primary">No quotations or invoices yet</div>
          <div class="mt-2 text-sm text-text-muted">
            A quotation is created when you reach an application’s payment step. It becomes an invoice once payment is confirmed.
          </div>
          <div class="mt-8">
            <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-primary px-6 py-2.5 text-sm font-semibold">Go to applications</Link>
          </div>
        </div>

        <div v-else>
          <!-- Desktop -->
          <div class="hidden overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-sm md:block">
            <table class="min-w-full divide-y divide-border/60 text-sm">
              <thead class="bg-gradient-to-r from-surface-muted to-surface-muted/80 text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                <tr>
                  <th class="px-5 py-4">Type</th>
                  <th class="px-5 py-4">Reference</th>
                  <th class="px-5 py-4">Application</th>
                  <th class="px-5 py-4">Amount</th>
                  <th class="px-5 py-4">Status</th>
                  <th class="px-5 py-4">Issued</th>
                  <th class="px-5 py-4">Paid</th>
                  <th class="px-5 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="inv in invoices" :key="inv.id" class="transition hover:bg-surface-muted/50">
                  <td class="px-5 py-4 align-top">
                    <span class="zaqa-badge text-xs" :class="typeBadgeClass(inv)">{{ typeLabel(inv) }}</span>
                    <p v-if="lifecycleHint(inv)" class="mt-1.5 max-w-[12rem] text-[11px] leading-snug text-text-muted">
                      {{ lifecycleHint(inv) }}
                    </p>
                  </td>
                  <td class="px-5 py-4 align-top">
                    <div class="flex items-center gap-2 font-mono text-sm font-semibold text-text-primary">
                      <FileText class="h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
                      {{ displayNumber(inv) }}
                    </div>
                  </td>
                  <td class="px-5 py-4 align-top">
                    <Link
                      v-if="inv.application"
                      :href="`/applicant/applications/${inv.application.id}`"
                      class="zaqa-link font-mono text-xs font-semibold"
                    >
                      {{ inv.application.application_number }}
                    </Link>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-5 py-4 align-top font-semibold tabular-nums text-text-primary">{{ money(inv.amount_cents, inv.currency) }}</td>
                  <td class="px-5 py-4 align-top">
                    <span class="zaqa-badge text-xs" :class="statusBadgeClass(inv.status)">{{ statusLabel(inv.status) }}</span>
                  </td>
                  <td class="px-5 py-4 align-top text-xs text-text-muted">{{ formatWhen(inv.issued_at) }}</td>
                  <td class="px-5 py-4 align-top text-xs text-text-muted">{{ formatWhen(inv.paid_at) }}</td>
                  <td class="px-5 py-4 text-right align-top">
                    <div class="inline-flex items-center gap-2">
                      <a
                        :href="inv.download_url"
                        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <FileDown class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ downloadLabel(inv) }}
                      </a>
                      <Link
                        :href="`/applicant/invoices/${inv.id}`"
                        class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ viewLabel(inv) }}
                      </Link>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Mobile -->
          <div class="space-y-4 md:hidden">
            <div
              v-for="inv in invoices"
              :key="inv.id"
              class="overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-sm"
            >
              <div class="flex items-start justify-between gap-3 border-b border-border/60 bg-surface-muted/50 px-5 py-4">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="zaqa-badge text-[10px]" :class="typeBadgeClass(inv)">{{ typeLabel(inv) }}</span>
                    <span class="zaqa-badge shrink-0 text-[10px]" :class="statusBadgeClass(inv.status)">{{ statusLabel(inv.status) }}</span>
                  </div>
                  <div class="mt-2 flex items-center gap-2 font-mono text-sm font-semibold text-text-primary">
                    <Receipt class="h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
                    {{ displayNumber(inv) }}
                  </div>
                  <p v-if="lifecycleHint(inv)" class="mt-1 text-xs leading-snug text-text-muted">{{ lifecycleHint(inv) }}</p>
                  <div class="mt-1 text-xs text-text-muted">
                    <Link v-if="inv.application" :href="`/applicant/applications/${inv.application.id}`" class="zaqa-link font-mono font-semibold">
                      {{ inv.application.application_number }}
                    </Link>
                    <span v-else>—</span>
                  </div>
                </div>
              </div>
              <div class="space-y-3 px-5 py-4">
                <div class="flex items-baseline justify-between">
                  <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Amount</span>
                  <span class="text-lg font-bold tabular-nums text-text-primary">{{ money(inv.amount_cents, inv.currency) }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                  <div>
                    <div class="font-semibold uppercase tracking-wider text-text-muted">Issued</div>
                    <div class="mt-1 text-text-primary">{{ formatWhen(inv.issued_at) }}</div>
                  </div>
                  <div>
                    <div class="font-semibold uppercase tracking-wider text-text-muted">Paid</div>
                    <div class="mt-1 text-text-primary">{{ formatWhen(inv.paid_at) }}</div>
                  </div>
                </div>
                <div class="flex flex-wrap gap-2">
                  <a
                    :href="inv.download_url"
                    class="zaqa-btn zaqa-btn-secondary inline-flex flex-1 items-center justify-center gap-1.5 py-2.5 text-xs font-semibold"
                  >
                    <FileDown class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ downloadLabel(inv) }}
                  </a>
                  <Link
                    :href="`/applicant/invoices/${inv.id}`"
                    class="zaqa-btn zaqa-btn-primary inline-flex flex-1 items-center justify-center gap-2 py-2.5 text-xs font-semibold"
                  >
                    <Eye class="h-4 w-4" aria-hidden="true" />
                    {{ viewLabel(inv) }}
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
