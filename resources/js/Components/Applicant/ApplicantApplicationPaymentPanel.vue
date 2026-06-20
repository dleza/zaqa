<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import CyberSourceCardPaymentForm from '@/Components/Applicant/CyberSourceCardPaymentForm.vue'
import {
  AlertCircle,
  CheckCircle2,
  CreditCard,
  Landmark,
  Lock,
  Smartphone,
  Upload,
} from 'lucide-vue-next'

const props = defineProps<{
  application: any
  bankTransfer?: Record<string, any>
  cgrate?: Record<string, any>
  /** When set, invoice preparation is blocked until requirements are met. */
  paymentBlocked?: boolean
  /** Label for the step users should return to when payment is blocked. */
  blockedStepLabel?: string
}>()

const emit = defineEmits<{
  goToBlockedStep: []
}>()

const prepareInvoiceForm = useForm({})
const proofForm = useForm<{ file: File | null }>({ file: null })
const mobileMoneyForm = useForm({ mobile_number: '' })

const payment = computed(() => props.application?.payment ?? null)
const invoice = computed(() => props.application?.invoice ?? null)
const invoiceSettled = computed(() => props.application?.payment_satisfied === true)
const applicationLocked = computed(() => invoiceSettled.value)

type PaymentTabKey = 'card' | 'bank_transfer' | 'mobile_money'
const activePaymentTab = ref<PaymentTabKey>('card')

const invoicePreparation = ref({ auto_attempted: false, auto_failed: false })

const invoiceLineItems = computed(() => {
  const items = invoice.value?.line_items
  if (Array.isArray(items) && items.length > 0) return items
  if (!invoice.value) return []
  return [
    {
      description: 'Application verification fee',
      quantity: 1,
      amount_cents: Number(invoice.value.amount_cents ?? 0),
      total_cents: Number(invoice.value.amount_cents ?? 0),
    },
  ]
})

const paymentAwaitingFinanceReview = computed(
  () => (payment.value?.status ?? '').toString() === 'awaiting_finance_review',
)

const bankDepositAccount = computed(() => props.bankTransfer?.deposit_account ?? null)
const bankDepositReference = computed(() => {
  const docNumber = (invoice.value?.document_number ?? invoice.value?.invoice_number ?? '').toString().trim()
  if (docNumber) return docNumber
  return (props.application?.application_number ?? '').toString().trim() || '—'
})

const billingDocumentLabel = computed(() => {
  const title = (invoice.value?.document_title ?? '').toString().trim()
  if (title) return title
  return invoice.value?.document_type === 'quotation' ? 'Quotation' : 'Invoice'
})

const billingDownloadLabel = computed(() => {
  const label = (invoice.value?.download_label ?? '').toString().trim()
  if (label) return label
  return invoice.value?.document_type === 'quotation' ? 'Download quotation' : 'Download invoice'
})

const quotationExpiryDisplay = computed(() => {
  const raw = (invoice.value?.expires_at ?? '').toString().trim()
  if (!raw || invoice.value?.document_type !== 'quotation') return null
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return null
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(date)
})

function formatMoneyCents(cents: number) {
  const c = (invoice.value?.currency ?? 'ZMW').toString()
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format((cents || 0) / 100)
}

function paymentStatusLabel(status: unknown): string {
  const s = (status ?? '').toString().trim()
  if (!s) return 'Pending payment'
  if (s === 'confirmed') return 'Payment confirmed'
  if (s === 'awaiting_finance_review') return 'Awaiting finance review'
  return s.replaceAll('_', ' ')
}

function paymentStatusBadgeClass(status: unknown): string {
  const s = (status ?? '').toString().trim()
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed' || s === 'expired') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
}

function bankDepositField(value: string | null | undefined): string {
  const v = (value ?? '').toString().trim()
  return v || '—'
}

function prepareInvoice(auto = false) {
  if (prepareInvoiceForm.processing || applicationLocked.value || invoice.value || props.paymentBlocked) return
  if (auto && invoicePreparation.value.auto_attempted) return
  if (auto) invoicePreparation.value.auto_attempted = true
  prepareInvoiceForm.post(`/applicant/applications/${props.application.id}/payment/prepare`, {
    preserveScroll: true,
    onSuccess: () => {
      invoicePreparation.value.auto_failed = false
      router.reload({ only: ['application'] })
    },
    onError: () => {
      if (auto) invoicePreparation.value.auto_failed = true
    },
  })
}

watch(
  () => props.paymentBlocked,
  (blocked) => {
    if (!blocked) prepareInvoice(true)
  },
  { immediate: true },
)

function onProofFileChange(e: Event) {
  const target = e.target as HTMLInputElement
  proofForm.file = target.files && target.files.length > 0 ? target.files[0] : null
}

function uploadPaymentProof() {
  proofForm.post(`/applicant/applications/${props.application.id}/payment/upload-proof`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      proofForm.reset('file')
      router.reload({ only: ['application'] })
    },
  })
}

const mobileMoneySubmitting = ref(false)
const mobileMoneyModalOpen = ref(false)
const mobileMoneyLive = ref<any>(null)
const mobileMoneyPollingId = ref<number | null>(null)

const mobileMoneyAttempt = computed(() => payment.value?.latest_attempt ?? null)
const mobileMoneyAttemptId = computed(() => mobileMoneyAttempt.value?.id ?? mobileMoneyLive.value?.attempt_id ?? null)
const mobileMoneyIsPending = computed(() => {
  const status = (mobileMoneyLive.value?.status ?? mobileMoneyAttempt.value?.status ?? '').toString()
  return status === 'pending' || status === 'initiated'
})

async function initiateMobileMoney() {
  if (mobileMoneySubmitting.value) return
  mobileMoneySubmitting.value = true
  try {
    const res = await (window as any).axios.post(
      `/applicant/applications/${props.application.id}/payment/initiate-mobile-money`,
      { mobile_number: mobileMoneyForm.mobile_number },
      { headers: { Accept: 'application/json' } },
    )
    mobileMoneyLive.value = res.data ?? null
    mobileMoneyModalOpen.value = true
    router.reload({ only: ['application'] })
  } finally {
    mobileMoneySubmitting.value = false
  }
}

function stopMobileMoneyPolling() {
  if (mobileMoneyPollingId.value) {
    window.clearInterval(mobileMoneyPollingId.value)
    mobileMoneyPollingId.value = null
  }
}

async function checkMobileMoneyStatus() {
  const attemptId = mobileMoneyAttemptId.value
  if (!attemptId) return
  try {
    const res = await (window as any).axios.get(`/applicant/payments/attempts/${attemptId}/status`)
    mobileMoneyLive.value = res.data ?? null
    if (res.data?.paid || res.data?.status === 'successful') {
      stopMobileMoneyPolling()
      router.reload({ only: ['application'] })
    }
  } catch {
    // ignore poll errors
  }
}

function startMobileMoneyPolling() {
  if (mobileMoneyPollingId.value || !mobileMoneyIsPending.value) return
  mobileMoneyPollingId.value = window.setInterval(() => {
    if (!mobileMoneyIsPending.value) {
      stopMobileMoneyPolling()
      return
    }
    void checkMobileMoneyStatus()
  }, 3000)
}

watch(
  () => [mobileMoneyAttempt.value?.status, mobileMoneyAttemptId.value],
  () => {
    if (mobileMoneyIsPending.value && mobileMoneyAttemptId.value) startMobileMoneyPolling()
    else stopMobileMoneyPolling()
  },
  { immediate: true },
)

onBeforeUnmount(() => stopMobileMoneyPolling())
</script>

<template>
  <div>
    <div class="rounded-xl border border-border bg-surface p-3 shadow-sm ring-1 ring-black/[0.04] sm:p-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs">
          <span class="text-text-muted">{{ billingDocumentLabel }}:</span>
          <span class="ml-1 font-semibold text-text-primary">{{ invoice?.document_number ?? invoice?.invoice_number ?? '—' }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="zaqa-badge text-xs" :class="paymentStatusBadgeClass(payment?.status)">
            <component :is="(payment?.status ?? '') === 'confirmed' ? CheckCircle2 : AlertCircle" class="h-3.5 w-3.5" aria-hidden="true" />
            {{ paymentStatusLabel(payment?.status) }}
          </span>
          <a v-if="invoice?.download_url" :href="invoice.download_url" class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs">
            {{ billingDownloadLabel }}
          </a>
        </div>
      </div>

      <p v-if="quotationExpiryDisplay" class="mt-3 text-xs text-text-muted">
        This quotation expires on <span class="font-semibold text-text-primary">{{ quotationExpiryDisplay }}</span>.
      </p>

      <div v-if="invoice && invoiceLineItems.length > 0" class="mt-3 border-t border-border/50 pt-3">
        <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Fee breakdown</div>
        <ul class="mt-2 space-y-2 text-sm">
          <li v-for="(row, idx) in invoiceLineItems" :key="idx" class="flex justify-between gap-4">
            <span>{{ row.description }}</span>
            <span class="shrink-0 font-medium">{{ formatMoneyCents(Number(row.total_cents ?? 0)) }}</span>
          </li>
        </ul>
        <div class="mt-2 flex justify-between border-t border-border/40 pt-2 text-sm font-semibold">
          <span>Total</span>
          <span>{{ formatMoneyCents(Number(invoice.amount_cents ?? 0)) }}</span>
        </div>
      </div>

      <div v-if="!invoice && !applicationLocked" class="mt-3 border-t border-border/50 pt-3 text-xs text-text-muted">
        <span v-if="paymentBlocked">Complete {{ blockedStepLabel ?? 'previous steps' }} to unlock payment.</span>
        <span v-else-if="prepareInvoiceForm.processing">Preparing your quotation…</span>
        <span v-else-if="invoicePreparation.auto_failed">
          Could not prepare quotation.
          <button type="button" class="zaqa-link ml-1" @click="prepareInvoice(false)">Retry</button>
        </span>
        <span v-else>Preparing your quotation…</span>
        <button
          v-if="paymentBlocked"
          type="button"
          class="zaqa-btn zaqa-btn-secondary mt-2 px-3 py-1.5 text-xs"
          @click="emit('goToBlockedStep')"
        >
          Go to {{ blockedStepLabel ?? 'previous step' }}
        </button>
      </div>
    </div>

    <div v-if="invoiceSettled" class="mt-4 rounded-2xl border border-success/20 bg-success/10 p-4">
      <div class="text-sm font-semibold text-success">Payment confirmed</div>
      <p class="mt-1 text-xs text-text-muted">Your application has been submitted for verification.</p>
    </div>

    <div v-else-if="invoice" class="mt-4">
      <div
        v-if="paymentAwaitingFinanceReview"
        class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
      >
        <div class="font-semibold">Proof submitted — awaiting finance review</div>
        <div v-if="payment?.proof_document" class="mt-3 flex gap-2 text-xs">
          <a :href="payment.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-link">Preview proof</a>
          <a :href="payment.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-link">Download proof</a>
        </div>
      </div>

      <template v-else>
        <div class="mt-3 flex gap-2 overflow-x-auto rounded-2xl bg-surface p-2 ring-1 ring-black/[0.04] sm:grid sm:grid-cols-3">
          <button
            type="button"
            class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold"
            :class="activePaymentTab === 'card' ? 'bg-brand/10 text-brand ring-1 ring-brand/20' : 'text-text-muted'"
            @click="activePaymentTab = 'card'"
          >
            <CreditCard class="h-4 w-4" aria-hidden="true" />
            Card payment
          </button>
          <button
            type="button"
            class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold"
            :class="activePaymentTab === 'bank_transfer' ? 'bg-brand/10 text-brand ring-1 ring-brand/20' : 'text-text-muted'"
            @click="activePaymentTab = 'bank_transfer'"
          >
            <Landmark class="h-4 w-4" aria-hidden="true" />
            Bank transfer
          </button>
          <button
            type="button"
            class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold"
            :class="activePaymentTab === 'mobile_money' ? 'bg-brand/10 text-brand ring-1 ring-brand/20' : 'text-text-muted'"
            @click="activePaymentTab = 'mobile_money'"
          >
            <Smartphone class="h-4 w-4" aria-hidden="true" />
            Mobile money
          </button>
        </div>

        <div class="mt-3 rounded-2xl bg-surface p-4 ring-1 ring-black/[0.04]">
          <div v-if="activePaymentTab === 'card'">
            <CyberSourceCardPaymentForm :application="application" />
          </div>

          <div v-else-if="activePaymentTab === 'bank_transfer'">
            <div class="text-sm font-semibold">Pay by bank transfer or deposit</div>
            <div class="mt-3 rounded-xl border border-brand/15 bg-brand/5 p-4 text-sm">
              <dl class="grid gap-2 sm:grid-cols-2">
                <div>
                  <dt class="text-xs text-text-muted">Bank</dt>
                  <dd class="font-semibold">{{ bankDepositField(bankDepositAccount?.bank_name) }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-text-muted">Account number</dt>
                  <dd class="font-mono font-semibold">{{ bankDepositField(bankDepositAccount?.account_number) }}</dd>
                </div>
                <div class="sm:col-span-2">
                  <dt class="text-xs text-text-muted">Reference</dt>
                  <dd class="font-mono font-semibold">{{ bankDepositReference }}</dd>
                </div>
              </dl>
            </div>
            <div class="mt-4">
              <label class="text-sm font-medium">Upload proof of payment</label>
              <input type="file" accept="application/pdf,image/*" class="zaqa-input mt-2" @change="onProofFileChange" />
              <InputError :message="proofForm.errors.file" />
              <button
                type="button"
                class="zaqa-btn zaqa-btn-primary mt-3 inline-flex items-center gap-2"
                :disabled="proofForm.processing || !proofForm.file"
                @click="uploadPaymentProof"
              >
                <Upload class="h-4 w-4" aria-hidden="true" />
                Upload proof
              </button>
            </div>
          </div>

          <div v-else>
            <div class="text-sm font-semibold">Mobile money</div>
            <div v-if="!cgrate?.enabled" class="mt-2 text-sm text-text-muted">Mobile money is not available right now.</div>
            <template v-else>
              <label class="mt-3 block text-sm font-medium">Mobile number</label>
              <input v-model="mobileMoneyForm.mobile_number" type="text" class="zaqa-input mt-1" placeholder="e.g. 0971234567" />
              <InputError :message="mobileMoneyForm.errors.mobile_number" />
              <button
                type="button"
                class="zaqa-btn zaqa-btn-primary mt-3"
                :disabled="mobileMoneySubmitting"
                @click="initiateMobileMoney"
              >
                Send payment prompt
              </button>
            </template>
          </div>
        </div>

        <div class="mt-3 inline-flex items-start gap-2 rounded-xl bg-surface-muted px-3 py-2 text-xs text-text-muted">
          <Lock class="mt-0.5 h-4 w-4 text-brand" aria-hidden="true" />
          <span>You can edit qualification records until payment is confirmed.</span>
        </div>
      </template>
    </div>
  </div>
</template>
