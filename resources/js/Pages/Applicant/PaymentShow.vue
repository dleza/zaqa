<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { AlertCircle, ArrowDownToLine, ArrowLeft, CheckCircle2, Eye } from 'lucide-vue-next'

const props = defineProps<{
  document: {
    is_official_receipt: boolean
    organization: {
      legal_name?: string
      address?: string
      address_line_1?: string
      address_line_2?: string
      address_line_3?: string
      tel?: string
      fax?: string
      email?: string
      website?: string
    }
    receipt_number_display: string
    receipt_date: string | null
    receipt_time: string | null
    account_label: string
    account_reference: string
    description: string
    amount_in_words: string
    reference: string
    payment_method_label: string
    breakdown: {
      cheque_no: string
      cheque_amount: string
      cash_amount: string
      electronic_amount: string
      total: string
    }
    signature_data_uri: string | null
    qr_data_uri: string | null
    verification_url: string | null
  }
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
      download_url: string
    } | null
    proof_document: {
      id: number
      original_name: string | null
      preview_url: string
      download_url: string
    } | null
    receipt_download_url: string | null
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
        <div class="absolute -left-16 top-0 h-64 w-64 rounded-full bg-brand/10 blur-3xl" />
        <div class="absolute right-0 top-20 h-72 w-72 rounded-full bg-accent/10 blur-3xl" />
      </div>

      <div class="zaqa-wizard-shell">
        <Link
          href="/applicant/payments"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          All payments
        </Link>

        <div class="mt-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Billing</p>
            <h1 class="mt-2 font-mono text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">
              ZQ {{ payment.id }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
              {{ humanMethod(payment.method) }} · {{ money(payment.amount_cents, payment.currency) }}
              <span class="capitalize"> · {{ payment.status.replace(/_/g, ' ') }}</span>
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2 self-start lg:max-w-[50%] lg:justify-end">
            <a
              v-if="payment.receipt_download_url"
              :href="payment.receipt_download_url"
              class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm"
            >
              <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
              Download receipt
            </a>

            <template v-if="payment.application">
              <Link :href="payment.application.show_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                View application
              </Link>
              <Link :href="payment.application.track_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                Track
              </Link>
              <Link
                v-if="payment.application.can_edit"
                :href="payment.application.edit_url"
                class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold"
              >
                Edit
              </Link>
            </template>

            <template v-if="payment.invoice">
              <Link :href="payment.invoice.show_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                View invoice
              </Link>
              <a :href="payment.invoice.download_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                Download invoice
              </a>
            </template>

            <template v-if="payment.proof_document">
              <a
                :href="payment.proof_document.preview_url"
                target="_blank"
                rel="noopener"
                class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
              >
                <Eye class="h-4 w-4" aria-hidden="true" />
                Preview proof
              </a>
              <a
                :href="payment.proof_document.download_url"
                target="_blank"
                rel="noopener"
                class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
              >
                <ArrowDownToLine class="h-4 w-4" aria-hidden="true" />
                Download proof
              </a>
            </template>
          </div>
        </div>

        <div class="mt-8 space-y-8">
          <!-- Web-only: payment status & timeline -->
          <section class="rounded-2xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-5 py-4 sm:px-6">
              <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-sm font-semibold text-text-primary">Payment record</h2>
                <span class="zaqa-badge inline-flex items-center gap-1.5 text-xs capitalize" :class="statusBadgeClass(payment.status)">
                  <CheckCircle2 v-if="payment.status === 'confirmed'" class="h-3.5 w-3.5" aria-hidden="true" />
                  <AlertCircle v-else class="h-3.5 w-3.5" aria-hidden="true" />
                  {{ payment.status.replace(/_/g, ' ') }}
                </span>
              </div>
            </div>

            <dl class="grid grid-cols-1 gap-px bg-border sm:grid-cols-2 lg:grid-cols-3">
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Method</dt>
                <dd class="mt-1 capitalize text-sm font-semibold text-text-primary">{{ humanMethod(payment.method) }}</dd>
              </div>
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Provider</dt>
                <dd class="mt-1 text-sm font-semibold text-text-primary">{{ payment.provider || '—' }}</dd>
              </div>
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Provider reference</dt>
                <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ payment.provider_reference || '—' }}</dd>
              </div>
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Transaction ID</dt>
                <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ payment.provider_transaction_id || '—' }}</dd>
              </div>
              <div v-if="payment.mobile_number" class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Mobile number</dt>
                <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ payment.mobile_number }}</dd>
              </div>
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Created</dt>
                <dd class="mt-1 text-sm font-semibold text-text-primary">{{ formatWhen(payment.created_at) }}</dd>
              </div>
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Initiated</dt>
                <dd class="mt-1 text-sm font-semibold text-text-primary">{{ formatWhen(payment.initiated_at) }}</dd>
              </div>
              <div class="bg-surface px-5 py-3 sm:px-6">
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Confirmed</dt>
                <dd class="mt-1 text-sm font-semibold text-text-primary">{{ formatWhen(payment.confirmed_at) }}</dd>
              </div>
            </dl>

            <div
              v-if="payment.rejection_reason"
              class="border-t border-border px-5 py-4 text-sm text-danger sm:px-6"
            >
              <div class="font-semibold">Rejection reason</div>
              <div class="mt-1">{{ payment.rejection_reason }}</div>
            </div>
            <div
              v-if="payment.review_comment"
              class="border-t border-border px-5 py-4 text-sm text-text-primary sm:px-6"
            >
              <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Review note</div>
              <div class="mt-1">{{ payment.review_comment }}</div>
            </div>
          </section>

          <!-- Official receipt document (confirmed payments only) -->
          <article
            v-if="document.is_official_receipt"
            class="mx-auto w-full max-w-4xl border-[3px] border-black bg-white p-3 text-[10px] leading-snug text-black shadow-[0_8px_30px_rgba(15,23,42,0.08)] sm:p-4"
            aria-label="Receipt document"
          >
            <!-- Header -->
            <div class="grid grid-cols-1 gap-4 border-0 sm:grid-cols-[20%_44%_36%] sm:gap-2">
              <div class="flex items-start justify-center sm:justify-start">
                <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-14 w-auto object-contain" />
              </div>
              <div class="text-center">
                <div class="text-[11px] font-bold uppercase leading-snug">
                  {{ document.organization.legal_name || 'ZAMBIA QUALIFICATIONS AUTHORITY' }}
                </div>
                <div v-if="document.organization.address_line_1" class="mt-0.5 text-[9px] font-bold leading-snug">
                  {{ document.organization.address_line_1 }}
                </div>
                <div v-if="document.organization.address_line_2" class="text-[9px] font-bold leading-snug">
                  {{ document.organization.address_line_2 }}
                </div>
                <div v-if="document.organization.address_line_3" class="text-[9px] font-bold leading-snug">
                  {{ document.organization.address_line_3 }}
                </div>
                <div v-else-if="document.organization.address" class="text-[9px] font-bold leading-snug">
                  {{ document.organization.address }}
                </div>
              </div>
              <div class="text-center text-[9px] font-bold leading-snug sm:text-left">
                <div v-if="document.organization.tel">Tel: {{ document.organization.tel }}</div>
                <div v-if="document.organization.fax">Fax: {{ document.organization.fax }}</div>
                <div v-if="document.organization.email">Email: {{ document.organization.email }}</div>
                <div v-if="document.organization.website">Website: {{ document.organization.website }}</div>
              </div>
            </div>

            <!-- Title -->
            <div class="my-3 text-center sm:my-4">
              <div class="inline-block border-2 border-black px-7 py-1.5 text-[17px] font-bold">Receipt</div>
            </div>

            <!-- Meta -->
            <div class="mb-2 grid grid-cols-2 gap-y-0.5 font-bold">
              <div>No. {{ document.receipt_number_display }}</div>
              <div class="text-right">Date : {{ document.receipt_date || '—' }}</div>
              <div></div>
              <div class="text-right">Time : {{ document.receipt_time || '—' }}</div>
            </div>

            <!-- Details table -->
            <div class="overflow-x-auto">
              <table class="w-full min-w-[520px] border-collapse border-2 border-black text-[9.5px] leading-snug">
                <tbody>
                  <tr>
                    <td class="border-2 border-black p-1.5 align-top sm:p-2" colspan="2">
                      <strong>Account</strong> {{ document.account_label }} {{ document.account_reference }}
                    </td>
                    <td class="w-[22%] border-2 border-black p-1.5 font-bold whitespace-nowrap sm:p-2">Cheque No</td>
                    <td class="w-[16%] border-2 border-black p-1.5 text-right font-bold whitespace-nowrap sm:p-2">
                      {{ document.breakdown.cheque_no }}
                    </td>
                  </tr>
                  <tr>
                    <td class="border-2 border-black p-1.5 align-top sm:p-2" colspan="2">
                      <strong>Description:</strong> {{ document.description }}
                    </td>
                    <td class="border-2 border-black p-1.5 font-bold whitespace-nowrap sm:p-2">Cheque Amount</td>
                    <td class="border-2 border-black p-1.5 text-right font-bold whitespace-nowrap sm:p-2">
                      {{ document.breakdown.cheque_amount }}
                    </td>
                  </tr>
                  <tr>
                    <td class="border-2 border-black p-1.5 align-top sm:p-2" colspan="2" rowspan="2">
                      <strong>Amount In Words:</strong> {{ document.amount_in_words }}
                    </td>
                    <td class="border-2 border-black p-1.5 font-bold whitespace-nowrap sm:p-2">Cash Amount</td>
                    <td class="border-2 border-black p-1.5 text-right font-bold whitespace-nowrap sm:p-2">
                      {{ document.breakdown.cash_amount }}
                    </td>
                  </tr>
                  <tr>
                    <td class="border-2 border-black p-1.5 font-bold whitespace-nowrap sm:p-2">Electronic Cash Transfer</td>
                    <td class="border-2 border-black p-1.5 text-right font-bold whitespace-nowrap sm:p-2">
                      {{ document.breakdown.electronic_amount }}
                    </td>
                  </tr>
                  <tr>
                    <td class="border-2 border-black p-1.5 align-top sm:p-2" colspan="2">
                      <strong>Reference:</strong> {{ document.reference }}
                    </td>
                    <td class="border-2 border-black p-1.5 font-bold whitespace-nowrap sm:p-2"><strong>Total</strong></td>
                    <td class="border-2 border-black p-1.5 text-right font-bold whitespace-nowrap sm:p-2">
                      <strong>{{ document.breakdown.total }}</strong>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Signature + QR -->
            <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-[58%_42%] sm:items-end">
              <div class="border-2 border-black p-2 min-h-[58px]">
                <img
                  v-if="document.signature_data_uri"
                  :src="document.signature_data_uri"
                  alt="Signature"
                  class="max-h-10 max-w-[180px] object-contain"
                />
                <div class="mt-2 text-[10px] font-bold">Signature..........................................</div>
              </div>
              <div class="flex justify-center sm:justify-end">
                <img
                  v-if="document.qr_data_uri"
                  :src="document.qr_data_uri"
                  alt="Receipt verification QR code"
                  class="h-[78px] w-[78px]"
                />
              </div>
            </div>

            <!-- Footer -->
            <div class="mt-2 grid grid-cols-1 gap-1 text-[8px] font-bold sm:grid-cols-2">
              <div>This Is An Official Electronic Receipt From Zambia Qualifications Authority.</div>
              <div class="sm:text-right">You Learn, We Standardize</div>
            </div>
          </article>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
