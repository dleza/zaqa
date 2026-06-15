<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import {
  AlertCircle,
  ArrowDownToLine,
  ArrowLeft,
  Building2,
  CalendarClock,
  CheckCircle2,
  CreditCard,
  Eye,
  FileText,
  Hash,
  Link2,
  Route,
} from 'lucide-vue-next'

const props = defineProps<{
  payment: {
    id: number
    method: string
    status: string
    currency: string
    amount_cents: number
    provider: string
    provider_reference: string | null
    provider_transaction_id: string | null
    mobile_number: string | null
    created_at: string | null
    initiated_at: string | null
    confirmed_at: string | null
    failed_at: string | null
    rejected_at: string | null
    rejection_reason: string | null
    review_comment: string | null
    application: {
      id: number
      application_number: string
      current_status: string
      show_url: string
      edit_url: string
      track_url: string
      can_edit: boolean
    } | null
    invoice: {
      id: number
      invoice_number: string
      status: string
      show_url: string
    } | null
    proof_document: {
      id: number
      original_name: string | null
      preview_url: string
      download_url: string
    } | null
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

function statusBadgeClass(s: string) {
  const x = (s ?? '').toLowerCase()
  if (x === 'confirmed') return 'zaqa-badge-success'
  if (x === 'rejected' || x === 'failed') return 'zaqa-badge-danger'
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
          href="/applicant/payments"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          All payments
        </Link>

        <div class="mt-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Payment</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">
              {{ humanMethod(payment.method) }}
              <span class="text-text-muted">·</span>
              {{ money(payment.amount_cents, payment.currency) }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
              Full record of this payment attempt. Open your application or invoice for related context.
            </p>
          </div>
          <span class="zaqa-badge inline-flex w-fit items-center gap-1.5 self-start text-sm" :class="statusBadgeClass(payment.status)">
            <CheckCircle2 v-if="payment.status === 'confirmed'" class="h-4 w-4" aria-hidden="true" />
            <AlertCircle v-else class="h-4 w-4" aria-hidden="true" />
            {{ payment.status }}
          </span>
        </div>

        <div
          class="mt-8 overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-[0_20px_50px_-12px_rgba(11,58,102,0.12)] ring-1 ring-black/[0.04]"
        >
          <div
            class="border-b border-border/70 bg-gradient-to-br from-brand-dark via-brand-dark to-brand px-6 py-8 text-text-on-dark sm:px-10"
          >
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
              <div>
                <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-white/70">Payment ID</div>
                <div class="mt-2 font-mono text-2xl font-bold text-white">#{{ payment.id }}</div>
                <div class="mt-3 flex flex-wrap gap-2 text-sm text-white/85">
                  <span v-if="payment.provider_reference" class="rounded-lg bg-white/10 px-2 py-1 font-mono text-xs">
                    {{ payment.provider_reference }}
                  </span>
                  <span v-else class="text-white/70">Reference pending</span>
                </div>
              </div>
              <div class="rounded-xl border border-white/20 bg-white/10 px-5 py-4 backdrop-blur-sm">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Amount</div>
                <div class="mt-1 text-2xl font-bold text-white">{{ money(payment.amount_cents, payment.currency) }}</div>
              </div>
            </div>
          </div>

          <div class="divide-y divide-border/70 px-6 py-8 sm:px-10">
            <!-- Related navigation -->
            <section class="pb-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
                  <Link2 class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Related records</h2>
                  <p class="text-xs text-text-muted">Jump to the application or invoice this payment belongs to.</p>
                </div>
              </div>
              <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div
                  v-if="payment.application"
                  class="rounded-2xl border border-border/80 bg-surface-muted/60 p-5"
                >
                  <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                    <Building2 class="h-3.5 w-3.5" aria-hidden="true" />
                    Application
                  </div>
                  <div class="mt-2 font-mono text-lg font-semibold text-text-primary">
                    {{ payment.application.application_number }}
                  </div>
                  <div class="mt-1 text-xs capitalize text-text-muted">Status: {{ payment.application.current_status?.replace(/_/g, ' ') }}</div>
                  <div class="mt-4 flex flex-wrap gap-2">
                    <Link :href="payment.application.show_url" class="zaqa-btn zaqa-btn-primary px-3 py-2 text-xs font-semibold">
                      View application
                    </Link>
                    <Link :href="payment.application.track_url" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs font-semibold">
                      Track
                    </Link>
                    <Link
                      v-if="payment.application.can_edit"
                      :href="payment.application.edit_url"
                      class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs font-semibold"
                    >
                      Edit
                    </Link>
                  </div>
                </div>
                <div
                  v-if="payment.invoice"
                  class="rounded-2xl border border-border/80 bg-surface-muted/60 p-5"
                >
                  <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                    <FileText class="h-3.5 w-3.5" aria-hidden="true" />
                    Invoice
                  </div>
                  <div class="mt-2 font-mono text-lg font-semibold text-text-primary">{{ payment.invoice.invoice_number }}</div>
                  <div class="mt-1 text-xs capitalize text-text-muted">Invoice status: {{ payment.invoice.status }}</div>
                  <div class="mt-4 flex flex-wrap gap-2">
                    <Link :href="payment.invoice.show_url" class="zaqa-btn zaqa-btn-primary px-3 py-2 text-xs font-semibold">
                      View invoice
                    </Link>
                    <a
                      v-if="payment.invoice.download_url"
                      :href="payment.invoice.download_url"
                      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold"
                    >
                      Download invoice
                    </a>
                  </div>
                </div>
              </div>
              <p v-if="!payment.application && !payment.invoice" class="mt-4 text-sm text-text-muted">
                No linked application or invoice found.
              </p>
            </section>

            <!-- Details -->
            <section class="pb-8 pt-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/15 text-violet-900">
                  <CreditCard class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Payment details</h2>
                  <p class="text-xs text-text-muted">Provider, references, and timeline.</p>
                </div>
              </div>
              <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Method</dt>
                  <dd class="mt-1.5 capitalize text-sm font-semibold text-text-primary">{{ humanMethod(payment.method) }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Provider</dt>
                  <dd class="mt-1.5 text-sm font-semibold text-text-primary">{{ payment.provider || '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                    <Hash class="h-3 w-3" aria-hidden="true" />
                    Provider reference
                  </dt>
                  <dd class="mt-1.5 font-mono text-sm font-semibold text-text-primary">{{ payment.provider_reference || '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Transaction ID</dt>
                  <dd class="mt-1.5 font-mono text-sm font-semibold text-text-primary">{{ payment.provider_transaction_id || '—' }}</dd>
                </div>
                <div v-if="payment.mobile_number" class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Mobile number</dt>
                  <dd class="mt-1.5 font-mono text-sm font-semibold text-text-primary">{{ payment.mobile_number }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-text-muted">
                    <CalendarClock class="h-3 w-3" aria-hidden="true" />
                    Created
                  </dt>
                  <dd class="mt-1.5 text-sm font-semibold text-text-primary">{{ formatWhen(payment.created_at) }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Initiated</dt>
                  <dd class="mt-1.5 text-sm font-semibold text-text-primary">{{ formatWhen(payment.initiated_at) }}</dd>
                </div>
                <div class="rounded-2xl border border-border/80 bg-surface-muted/40 p-4">
                  <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Confirmed</dt>
                  <dd class="mt-1.5 text-sm font-semibold text-text-primary">{{ formatWhen(payment.confirmed_at) }}</dd>
                </div>
              </dl>

              <div
                v-if="payment.rejection_reason"
                class="mt-6 rounded-2xl border border-danger/25 bg-danger/10 px-4 py-4 text-sm text-danger"
              >
                <div class="font-semibold">Rejection reason</div>
                <div class="mt-2">{{ payment.rejection_reason }}</div>
              </div>
              <div
                v-if="payment.review_comment"
                class="mt-4 rounded-2xl border border-border bg-surface-muted/60 px-4 py-4 text-sm text-text-primary"
              >
                <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Review note</div>
                <div class="mt-2">{{ payment.review_comment }}</div>
              </div>
            </section>

            <!-- Proof -->
            <section v-if="payment.proof_document" class="pt-8">
              <div class="flex items-center gap-3 border-b border-border/60 pb-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-900">
                  <Route class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                  <h2 class="text-base font-semibold text-text-primary">Payment proof</h2>
                  <p class="text-xs text-text-muted">Document you uploaded for manual / bank verification.</p>
                </div>
              </div>
              <div class="mt-6 rounded-2xl border border-border/80 bg-surface-muted/50 p-5">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">File</div>
                <div class="mt-2 font-medium text-text-primary">{{ payment.proof_document.original_name || 'Uploaded document' }}</div>
                <div class="mt-4 flex flex-wrap gap-3">
                  <a
                    :href="payment.proof_document.preview_url"
                    target="_blank"
                    rel="noopener"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold"
                  >
                    <Eye class="h-4 w-4" aria-hidden="true" />
                    Preview
                  </a>
                  <a
                    :href="payment.proof_document.download_url"
                    target="_blank"
                    rel="noopener"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold"
                  >
                    <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
                    Download
                  </a>
                </div>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
