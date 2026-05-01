<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import {
  ArrowLeft,
  Building2,
  CalendarClock,
  CreditCard,
  Globe,
  Link2,
  Receipt,
} from 'lucide-vue-next'

const props = defineProps<{
  invoice: {
    id: number
    invoice_number: string
    currency: string
    amount_cents: number
    status: string
    issued_at: string | null
    due_at: string | null
    paid_at: string | null
    fee_label_snapshot: string | null
    processing_days_snapshot: number | null
    is_foreign_snapshot: boolean
    application: {
      id: number
      application_number: string
      current_status: string
      show_url: string
      edit_url: string
      track_url: string
      can_edit: boolean
    } | null
    payments: Array<{
      id: number
      method: string
      status: string
      currency: string
      amount_cents: number
      confirmed_at: string | null
      created_at: string | null
      show_url: string
    }>
  }
}>()

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}

function formatWhen(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return iso
  }
}

function invoiceBadgeClass(s: string) {
  const x = (s ?? '').toLowerCase()
  if (x === 'paid') return 'zaqa-badge-success'
  if (x === 'void') return 'zaqa-badge-danger'
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
        <div class="absolute -left-16 top-0 h-56 w-56 rounded-full bg-brand/10 blur-3xl" />
        <div class="absolute right-0 top-20 h-64 w-64 rounded-full bg-accent/10 blur-3xl" />
      </div>

      <div class="zaqa-wizard-shell">
        <Link
          href="/applicant/invoices"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          All invoices
        </Link>

        <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Invoice</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">
              {{ invoice.invoice_number }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
              Fee invoice for your verification application. Payments recorded against this invoice are listed below.
            </p>
          </div>
          <span class="zaqa-badge inline-flex w-fit shrink-0 items-center gap-1 self-start text-sm capitalize" :class="invoiceBadgeClass(invoice.status)">
            {{ invoice.status }}
          </span>
        </div>

        <div
          class="mt-8 overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-[0_20px_50px_-12px_rgba(11,58,102,0.12)] ring-1 ring-black/[0.04]"
        >
          <div
            class="border-b border-border/70 bg-gradient-to-br from-brand-dark via-brand-dark to-brand px-6 py-8 text-text-on-dark sm:px-10"
          >
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
              <div class="flex items-start gap-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/10">
                  <Receipt class="h-7 w-7 text-white" aria-hidden="true" />
                </span>
                <div>
                  <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-white/70">Amount due</div>
                  <div class="mt-2 font-mono text-3xl font-bold tracking-tight text-white">
                    {{ money(invoice.amount_cents, invoice.currency) }}
                  </div>
                  <div class="mt-3 flex flex-wrap gap-2 text-xs text-white/80">
                    <span v-if="invoice.fee_label_snapshot" class="rounded-lg bg-white/10 px-2 py-1">{{ invoice.fee_label_snapshot }}</span>
                    <span class="inline-flex items-center gap-1 rounded-lg bg-white/10 px-2 py-1">
                      <Globe class="h-3 w-3" aria-hidden="true" />
                      {{ invoice.is_foreign_snapshot ? 'Foreign fee snapshot' : 'Local fee snapshot' }}
                    </span>
                  </div>
                </div>
              </div>
              <dl class="grid gap-3 text-sm lg:text-right">
                <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Issued</dt>
                  <dd class="mt-1 font-semibold text-white">{{ formatWhen(invoice.issued_at) }}</dd>
                </div>
                <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Paid</dt>
                  <dd class="mt-1 font-semibold text-white">{{ formatWhen(invoice.paid_at) }}</dd>
                </div>
              </dl>
            </div>
          </div>

          <div class="divide-y divide-border/70 px-6 py-8 sm:px-10">
            <section class="pb-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
                  <Link2 class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Application</h2>
                  <p class="text-xs text-text-muted">This invoice is linked to the following verification application.</p>
                </div>
              </div>
              <div v-if="invoice.application" class="mt-6 rounded-2xl border border-border/80 bg-surface-muted/60 p-5">
                <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                  <Building2 class="h-3.5 w-3.5" aria-hidden="true" />
                  Application reference
                </div>
                <div class="mt-2 font-mono text-xl font-semibold text-text-primary">{{ invoice.application.application_number }}</div>
                <div class="mt-1 text-xs capitalize text-text-muted">Status: {{ invoice.application.current_status?.replace(/_/g, ' ') }}</div>
                <div class="mt-4 flex flex-wrap gap-2">
                  <Link :href="invoice.application.show_url" class="zaqa-btn zaqa-btn-primary px-3 py-2 text-xs font-semibold">
                    View application
                  </Link>
                  <Link :href="invoice.application.track_url" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs font-semibold">
                    Track
                  </Link>
                  <Link
                    v-if="invoice.application.can_edit"
                    :href="invoice.application.edit_url"
                    class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs font-semibold"
                  >
                    Edit
                  </Link>
                </div>
              </div>
              <p v-else class="mt-4 text-sm text-text-muted">No application linked.</p>
            </section>

            <section class="pb-8 pt-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-surface-muted text-text-primary">
                  <CalendarClock class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Dates & processing</h2>
                  <p class="text-xs text-text-muted">Issued, due, and service snapshot.</p>
                </div>
              </div>
              <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Due date</dt>
                  <dd class="mt-1.5 text-sm font-semibold text-text-primary">{{ formatWhen(invoice.due_at) }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Processing days (snapshot)</dt>
                  <dd class="mt-1.5 text-sm font-semibold text-text-primary">
                    {{ invoice.processing_days_snapshot != null ? `${invoice.processing_days_snapshot} days` : '—' }}
                  </dd>
                </div>
              </dl>
            </section>

            <section class="pt-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/15 text-violet-900">
                  <CreditCard class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Payments on this invoice</h2>
                  <p class="text-xs text-text-muted">Open a payment to see provider references and proof documents.</p>
                </div>
              </div>

              <div v-if="invoice.payments.length === 0" class="mt-6 rounded-2xl border border-dashed border-border bg-surface-muted/40 px-5 py-8 text-center text-sm text-text-muted">
                No payment attempts recorded yet.
              </div>
              <div v-else class="mt-6 overflow-hidden rounded-2xl border border-border/80">
                <table class="min-w-full divide-y divide-border/60 text-sm">
                  <thead class="bg-surface-muted text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                    <tr>
                      <th class="px-4 py-3">Payment</th>
                      <th class="px-4 py-3">Method</th>
                      <th class="px-4 py-3">Amount</th>
                      <th class="px-4 py-3">Status</th>
                      <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-border/60 bg-surface">
                    <tr v-for="p in invoice.payments" :key="p.id" class="hover:bg-surface-muted/50">
                      <td class="px-4 py-3 font-mono text-xs font-semibold text-text-primary">#{{ p.id }}</td>
                      <td class="px-4 py-3 capitalize text-text-primary">{{ humanMethod(p.method) }}</td>
                      <td class="px-4 py-3 font-semibold text-text-primary">{{ money(p.amount_cents, p.currency) }}</td>
                      <td class="px-4 py-3">
                        <span class="zaqa-badge zaqa-badge-info text-[10px] capitalize">{{ p.status }}</span>
                      </td>
                      <td class="px-4 py-3 text-right">
                        <Link :href="p.show_url" class="zaqa-btn zaqa-btn-secondary inline-flex px-3 py-1.5 text-xs font-semibold">
                          View
                        </Link>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
