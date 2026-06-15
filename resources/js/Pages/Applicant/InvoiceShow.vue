<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { ArrowLeft, CreditCard, FileDown } from 'lucide-vue-next'

type LineItem = {
  description: string
  quantity: number
  amount_cents: number
  total_cents: number
}

const props = defineProps<{
  document: {
    organization: {
      name?: string
      address?: string
      phone?: string
      email?: string
    }
    bill_to: {
      name?: string
      address?: string
      phone?: string
      email?: string
    }
    invoice_number: string
    invoice_date: string | null
    status_label: string
    application_reference: string | null
    application_id: number | null
    currency: string
    line_items: LineItem[]
    subtotal_cents: number
    vat_cents: number
    discount_cents: number
    total_cents: number
    vat_rate_label: string
    discount_rate_label: string
  }
  invoice: {
    id: number
    invoice_number: string
    currency: string
    amount_cents: number
    status: string
    issued_at: string | null
    due_at: string | null
    paid_at: string | null
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
      receipt_download_url: string | null
    }>
    download_url: string
  }
}>()

function money(cents: number, currency?: string) {
  const c = currency || props.document.currency || 'ZMW'
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format((cents ?? 0) / 100)
}

function formatWhen(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return iso
  }
}

function paymentBadgeClass(s: string) {
  const x = (s ?? '').toLowerCase()
  if (x === 'confirmed') return 'zaqa-badge-success'
  if (x === 'rejected' || x === 'failed') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
}

function humanMethod(m: string) {
  return (m ?? '').replace(/_/g, ' ')
}

const applicationRef = props.document.application_reference || (props.document.application_id ? String(props.document.application_id) : 'N/A')
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
          href="/applicant/invoices"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          All invoices
        </Link>

        <div class="mt-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Billing</p>
            <h1 class="mt-2 font-mono text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">
              {{ invoice.invoice_number }}
            </h1>
            <p v-if="invoice.application" class="mt-2 max-w-2xl text-sm text-text-muted">
              Application
              <span class="font-mono font-semibold text-text-primary">{{ invoice.application.application_number }}</span>
              <span class="capitalize"> · {{ invoice.application.current_status?.replace(/_/g, ' ') }}</span>
            </p>
            <p v-else class="mt-2 max-w-2xl text-sm text-text-muted">
              Fee invoice for your verification application.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2 self-start lg:max-w-[50%] lg:justify-end">
            <a
              :href="invoice.download_url"
              class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm"
            >
              <FileDown class="h-4 w-4" aria-hidden="true" />
              Download PDF
            </a>

            <template v-if="invoice.application">
              <Link :href="invoice.application.show_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                View application
              </Link>
              <Link :href="invoice.application.track_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                Track
              </Link>
              <Link
                v-if="invoice.application.can_edit"
                :href="invoice.application.edit_url"
                class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold"
              >
                Edit
              </Link>
            </template>

            <template v-for="p in invoice.payments" :key="p.id">
              <Link :href="p.show_url" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold">
                Payment #{{ p.id }}
              </Link>
              <a
                v-if="p.receipt_download_url"
                :href="p.receipt_download_url"
                class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm font-semibold"
              >
                Receipt #{{ p.id }}
              </a>
            </template>
          </div>
        </div>

        <div class="mt-8 space-y-8">
      <!-- PDF-style invoice document -->
      <article
        class="mx-auto w-full max-w-4xl overflow-hidden rounded-lg border border-gray-300 bg-white shadow-[0_8px_30px_rgba(15,23,42,0.08)]"
        aria-label="Invoice document"
      >
        <header class="bg-[#8f1d2f] px-6 py-4 text-center text-[1.75rem] font-bold tracking-[0.04em] text-white sm:text-[1.85rem]">
          Invoice
        </header>

        <div class="px-6 py-7 sm:px-10 sm:py-8">
          <!-- Brand block -->
          <div class="text-center">
            <img :src="zaqaLogoUrl" alt="ZAQA logo" class="mx-auto mb-3 h-16 w-auto object-contain sm:h-[4.5rem]" />
            <div class="text-[13px] font-bold text-gray-800">
              {{ document.organization.name || 'Zambia Qualifications Authority.' }}
            </div>
            <div v-if="document.organization.address" class="mt-1 text-[11px] leading-relaxed text-gray-600">
              {{ document.organization.address }}
            </div>
            <div v-if="document.organization.phone" class="text-[11px] leading-relaxed text-gray-600">
              {{ document.organization.phone }}
            </div>
            <div v-if="document.organization.email" class="text-[11px] leading-relaxed text-gray-600">
              {{ document.organization.email }}
            </div>
          </div>

          <!-- Bill to -->
          <div class="mt-5 text-[11px] leading-relaxed text-gray-800 sm:mt-6">
            <div class="mb-1.5 font-bold">BILL TO:</div>
            <div v-if="document.bill_to.address">{{ document.bill_to.address }}</div>
            <div v-else-if="document.bill_to.name">{{ document.bill_to.name }}</div>
            <div v-if="document.bill_to.phone">{{ document.bill_to.phone }}</div>
            <div v-if="document.bill_to.email">{{ document.bill_to.email }}</div>
          </div>

          <!-- Meta -->
          <table class="mt-5 w-full border-collapse text-[11px] text-gray-800 sm:mt-6">
            <tbody>
              <tr>
                <td class="w-[34%] py-1 align-top font-bold">Invoice Number :</td>
                <td class="w-[66%] py-1 align-top">#{{ document.invoice_number }}</td>
              </tr>
              <tr>
                <td class="py-1 align-top font-bold">Invoice Date :</td>
                <td class="py-1 align-top">{{ document.invoice_date || 'N/A' }}</td>
              </tr>
              <tr>
                <td class="py-1 align-top font-bold">Status :</td>
                <td class="py-1 align-top">{{ document.status_label }}</td>
              </tr>
              <tr>
                <td class="py-1 align-top font-bold">Application Id :</td>
                <td class="py-1 align-top">#{{ applicationRef }}</td>
              </tr>
            </tbody>
          </table>

          <!-- Line items -->
          <div class="mt-5 overflow-x-auto sm:mt-6">
            <table class="min-w-full border-collapse text-[11px] text-gray-800">
              <thead>
                <tr class="bg-gray-100">
                  <th class="border border-gray-300 px-2.5 py-2 text-left font-semibold sm:px-2.5">Description</th>
                  <th class="w-[12%] border border-gray-300 px-2.5 py-2 text-left font-semibold">Quantity</th>
                  <th class="w-[18%] border border-gray-300 px-2.5 py-2 text-right font-semibold">Amount</th>
                  <th class="w-[18%] border border-gray-300 px-2.5 py-2 text-right font-semibold">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, idx) in document.line_items" :key="idx">
                  <td class="border border-gray-300 px-2.5 py-2 align-top">{{ item.description }}</td>
                  <td class="border border-gray-300 px-2.5 py-2 align-top">{{ item.quantity }}</td>
                  <td class="border border-gray-300 px-2.5 py-2 text-right align-top whitespace-nowrap">
                    {{ document.currency }} {{ (item.amount_cents / 100).toFixed(2) }}
                  </td>
                  <td class="border border-gray-300 px-2.5 py-2 text-right align-top whitespace-nowrap">
                    {{ document.currency }} {{ (item.total_cents / 100).toFixed(2) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Totals -->
          <div class="mt-5 flex justify-end sm:mt-[18px]">
            <table class="w-full max-w-xs border-collapse text-[11px] text-gray-800 sm:w-[42%]">
              <tbody>
                <tr>
                  <td class="py-1 pr-3 text-right font-bold">Sub Total :</td>
                  <td class="py-1 text-right whitespace-nowrap">
                    {{ document.currency }} {{ (document.subtotal_cents / 100).toFixed(2) }}
                  </td>
                </tr>
                <tr>
                  <td class="py-1 pr-3 text-right font-bold">VAT ({{ document.vat_rate_label }}) :</td>
                  <td class="py-1 text-right whitespace-nowrap">
                    {{ document.currency }} {{ (document.vat_cents / 100).toFixed(2) }}
                  </td>
                </tr>
                <tr>
                  <td class="py-1 pr-3 text-right font-bold">Discount ({{ document.discount_rate_label }}) :</td>
                  <td class="py-1 text-right whitespace-nowrap">
                    {{ document.currency }} {{ (document.discount_cents / 100).toFixed(2) }}
                  </td>
                </tr>
                <tr>
                  <td class="pt-2 pr-3 text-right text-xs font-bold">Total :</td>
                  <td class="pt-2 text-right text-xs font-bold whitespace-nowrap">
                    {{ document.currency }} {{ (document.total_cents / 100).toFixed(2) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </article>

      <!-- Web-only: linked application summary -->
      <section
        v-if="invoice.application"
        class="mt-6 rounded-2xl border border-border bg-surface px-5 py-4 shadow-sm sm:px-6"
      >
        <h2 class="text-sm font-semibold text-text-primary">Linked application</h2>
        <p class="mt-1 font-mono text-base font-semibold text-text-primary">{{ invoice.application.application_number }}</p>
        <p class="mt-0.5 text-xs capitalize text-text-muted">Status: {{ invoice.application.current_status?.replace(/_/g, ' ') }}</p>
      </section>

      <!-- Web-only: payments -->
      <section class="mt-6 rounded-2xl border border-border bg-surface shadow-sm">
        <div class="flex items-center gap-3 border-b border-border px-5 py-4 sm:px-6">
          <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
            <CreditCard class="h-4 w-4" aria-hidden="true" />
          </span>
          <div>
            <h2 class="text-sm font-semibold text-text-primary">Payments on this invoice</h2>
            <p class="text-xs text-text-muted">Due {{ formatWhen(invoice.due_at) }} · Paid {{ formatWhen(invoice.paid_at) }}</p>
          </div>
        </div>

        <div v-if="invoice.payments.length === 0" class="px-5 py-8 text-center text-sm text-text-muted sm:px-6">
          No payment attempts recorded yet.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-sm">
            <thead class="bg-surface-muted text-left text-[10px] font-semibold uppercase tracking-wider text-text-muted">
              <tr>
                <th class="px-5 py-3 sm:px-6">Payment</th>
                <th class="px-5 py-3 sm:px-6">Method</th>
                <th class="px-5 py-3 sm:px-6">Amount</th>
                <th class="px-5 py-3 sm:px-6">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="p in invoice.payments" :key="p.id" class="hover:bg-surface-muted/40">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-text-primary sm:px-6">#{{ p.id }}</td>
                <td class="px-5 py-3 capitalize text-text-primary sm:px-6">{{ humanMethod(p.method) }}</td>
                <td class="px-5 py-3 font-semibold text-text-primary sm:px-6">{{ money(p.amount_cents, p.currency) }}</td>
                <td class="px-5 py-3 sm:px-6">
                  <span class="zaqa-badge text-[10px] capitalize" :class="paymentBadgeClass(p.status)">{{ p.status }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
