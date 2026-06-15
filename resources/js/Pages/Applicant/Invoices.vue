<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { ArrowRight, Eye, FileDown, FileText, Receipt, Wallet } from 'lucide-vue-next'

const props = defineProps<{
  invoices: Array<{
    id: number
    invoice_number: string
    currency: string
    amount_cents: number
    status: string
    issued_at: string | null
    paid_at: string | null
    application: { id: number; application_number: string; current_status: string } | null
    download_url: string
  }>
}>()

const hasInvoices = computed(() => props.invoices && props.invoices.length > 0)

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

function invoiceBadgeClass(status: string) {
  const s = (status ?? '').toLowerCase()
  if (s === 'paid') return 'zaqa-badge-success'
  if (s === 'void') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">Invoices</h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
              Invoices issued for your applications. Open <strong class="text-text-primary">View</strong> for amounts, dates, linked application,
              and payment history.
            </p>
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

        <div v-if="!hasInvoices" class="rounded-3xl border border-dashed border-border bg-surface-muted/40 px-8 py-14 text-center">
          <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 text-brand">
            <Receipt class="h-7 w-7" aria-hidden="true" />
          </div>
          <div class="mt-5 text-lg font-semibold text-text-primary">No invoices yet</div>
          <div class="mt-2 text-sm text-text-muted">Invoices are created when you progress through an application’s billing step.</div>
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
                  <th class="px-5 py-4">Invoice</th>
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
                  <td class="px-5 py-4">
                    <div class="flex items-center gap-2 font-mono text-sm font-semibold text-text-primary">
                      <FileText class="h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
                      {{ inv.invoice_number }}
                    </div>
                  </td>
                  <td class="px-5 py-4">
                    <Link
                      v-if="inv.application"
                      :href="`/applicant/applications/${inv.application.id}`"
                      class="zaqa-link font-mono text-xs font-semibold"
                    >
                      {{ inv.application.application_number }}
                    </Link>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-5 py-4 font-semibold tabular-nums text-text-primary">{{ money(inv.amount_cents, inv.currency) }}</td>
                  <td class="px-5 py-4">
                    <span class="zaqa-badge text-xs capitalize" :class="invoiceBadgeClass(inv.status)">{{ inv.status }}</span>
                  </td>
                  <td class="px-5 py-4 text-xs text-text-muted">{{ formatWhen(inv.issued_at) }}</td>
                  <td class="px-5 py-4 text-xs text-text-muted">{{ formatWhen(inv.paid_at) }}</td>
                  <td class="px-5 py-4 text-right">
                    <div class="inline-flex items-center gap-2">
                      <a
                        :href="inv.download_url"
                        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <FileDown class="h-3.5 w-3.5" aria-hidden="true" />
                        Download
                      </a>
                      <Link
                        :href="`/applicant/invoices/${inv.id}`"
                        class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold"
                      >
                        <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                        View
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
                  <div class="flex items-center gap-2 font-mono text-sm font-semibold text-text-primary">
                    <Receipt class="h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
                    {{ inv.invoice_number }}
                  </div>
                  <div class="mt-1 text-xs text-text-muted">
                    <Link v-if="inv.application" :href="`/applicant/applications/${inv.application.id}`" class="zaqa-link font-mono font-semibold">
                      {{ inv.application.application_number }}
                    </Link>
                    <span v-else>—</span>
                  </div>
                </div>
                <span class="zaqa-badge shrink-0 text-[10px] capitalize" :class="invoiceBadgeClass(inv.status)">{{ inv.status }}</span>
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
                    Download
                  </a>
                  <Link
                    :href="`/applicant/invoices/${inv.id}`"
                    class="zaqa-btn zaqa-btn-primary inline-flex flex-1 items-center justify-center gap-2 py-2.5 text-xs font-semibold"
                  >
                    <Eye class="h-4 w-4" aria-hidden="true" />
                    View invoice
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
