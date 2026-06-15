<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { formatMoneyFromCents } from '@/utils/money'
import { Head } from '@inertiajs/vue3'
import { BadgeCheck, CheckCircle2, FileX2, Receipt, ShieldCheck } from 'lucide-vue-next'
import { computed } from 'vue'

type ReceiptPayload = {
  receipt_number: string
  receipt_number_display: string
  payment_status: string
  payment_status_label: string
  payment_date: string | null
  amount_cents: number
  currency: string
  application_reference: string | null
  invoice_number: string | null
  holder_name: string | null
  payment_method: string
}

const props = defineProps<{
  verification: {
    found: boolean
    status: string
    status_label: string
    message: string
    verified_at?: string | null
    receipt: ReceiptPayload | null
  }
}>()

const isVerified = computed(() => props.verification.found && props.verification.status === 'verified')

function formatDate(iso: string | null | undefined, includeTime = false) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, {
      dateStyle: 'medium',
      ...(includeTime ? { timeStyle: 'short' as const } : {}),
    })
  } catch {
    return iso
  }
}
</script>

<template>
  <Head title="Official receipt verification" />

  <GuestLayout :card="false" max-width-class="max-w-4xl" content-padding-class="px-4 py-8 sm:px-6" header-compact :center-content="false">
    <div class="overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-lg">
      <section class="border-b border-border/70 bg-gradient-to-r from-brand via-brand/95 to-brand-dark px-6 py-6 text-white">
        <div class="flex items-start gap-4">
          <div class="rounded-xl bg-white/95 p-2.5">
            <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-10 w-auto object-contain" />
          </div>
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-white/75">Zambia Qualifications Authority</div>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">Official Receipt Verification</h1>
          </div>
        </div>
      </section>

      <div class="space-y-6 p-6 sm:p-8">
        <section
          class="rounded-2xl border p-5 sm:p-6"
          :class="isVerified ? 'border-emerald-200 bg-emerald-50/70' : 'border-slate-200 bg-slate-50/80'"
        >
          <div class="flex items-start gap-4">
            <span
              class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-white"
              :class="isVerified ? 'bg-emerald-600' : 'bg-slate-700'"
            >
              <ShieldCheck v-if="isVerified" class="h-6 w-6" aria-hidden="true" />
              <FileX2 v-else class="h-6 w-6" aria-hidden="true" />
            </span>
            <div>
              <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide" :class="isVerified ? 'bg-emerald-600 text-white' : 'bg-slate-700 text-white'">
                <CheckCircle2 class="h-3.5 w-3.5" aria-hidden="true" />
                {{ verification.status_label }}
              </div>
              <p class="mt-3 text-sm leading-6 text-text-secondary">{{ verification.message }}</p>
              <p v-if="verification.verified_at" class="mt-2 text-xs text-text-muted">
                Verified on {{ formatDate(verification.verified_at, true) }}
              </p>
            </div>
          </div>
        </section>

        <section v-if="verification.receipt" class="overflow-hidden rounded-2xl border border-border/80 bg-surface">
          <div class="border-b border-border/70 bg-surface-muted/60 px-5 py-4">
            <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <Receipt class="h-4 w-4 text-brand" aria-hidden="true" />
              Receipt details
            </div>
          </div>
          <dl class="grid gap-4 p-5 sm:grid-cols-2">
            <div class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Receipt number</dt>
              <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ verification.receipt.receipt_number_display }}</dd>
            </div>
            <div class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Payment status</dt>
              <dd class="mt-1 text-sm font-semibold capitalize text-text-primary">{{ verification.receipt.payment_status_label }}</dd>
            </div>
            <div class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Payment date</dt>
              <dd class="mt-1 text-sm font-semibold text-text-primary">{{ formatDate(verification.receipt.payment_date, true) }}</dd>
            </div>
            <div class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Amount</dt>
              <dd class="mt-1 text-sm font-semibold text-text-primary">
                {{ formatMoneyFromCents(verification.receipt.amount_cents, verification.receipt.currency) }}
              </dd>
            </div>
            <div class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Payment method</dt>
              <dd class="mt-1 text-sm font-semibold text-text-primary">{{ verification.receipt.payment_method }}</dd>
            </div>
            <div class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Application reference</dt>
              <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ verification.receipt.application_reference || '—' }}</dd>
            </div>
            <div v-if="verification.receipt.invoice_number" class="rounded-xl border border-border/70 bg-surface-muted/40 p-4">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Invoice number</dt>
              <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ verification.receipt.invoice_number }}</dd>
            </div>
            <div v-if="verification.receipt.holder_name" class="rounded-xl border border-border/70 bg-surface-muted/40 p-4 sm:col-span-2">
              <dt class="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Applicant / holder</dt>
              <dd class="mt-1 text-sm font-semibold text-text-primary">{{ verification.receipt.holder_name }}</dd>
            </div>
          </dl>
        </section>

        <div class="flex items-center gap-2 rounded-xl border border-brand/15 bg-brand/5 px-4 py-3 text-xs text-text-muted">
          <BadgeCheck class="h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
          This page confirms receipt information held by the Zambia Qualifications Authority. It does not expose private payment gateway data.
        </div>
      </div>
    </div>
  </GuestLayout>
</template>
