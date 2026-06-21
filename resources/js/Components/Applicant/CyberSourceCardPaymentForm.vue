<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, withDefaults } from 'vue'
import { router } from '@inertiajs/vue3'
import { AlertCircle, CheckCircle2, CreditCard, LoaderCircle, RefreshCw, ShieldCheck } from 'lucide-vue-next'

type CaptureContextResponse = {
  payment_id?: number | string | null
  provider_reference?: string | null
  capture_context?: string | null
  card_networks?: string[]
}

type ConfirmCardResponse = {
  payment_status?: string | null
  redirect_url?: string | null
  message?: string | null
}

type FlexField = {
  load: (container: HTMLElement | string) => void
  unload?: () => void
  dispose?: () => void
  on?: (type: string, listener: (data?: any) => void) => void
}

type Microform = {
  createField: (type: string, options?: Record<string, unknown>) => FlexField
  createToken: (
    options: Record<string, string>,
    callback: (error: { message?: string } | null, token?: unknown) => void,
  ) => void
}

type FlexConstructor = new (captureContext: string) => {
  microform: (...args: any[]) => Microform
}

type CaptureContextMetadata = {
  clientLibrary: string
  clientLibraryIntegrity?: string
}

const scriptPromises = new Map<string, Promise<void>>()

const props = withDefaults(
  defineProps<{
    application: any
    autoInit?: boolean
    compact?: boolean
  }>(),
  {
    autoInit: false,
    compact: false,
  },
)

const emit = defineEmits<{
  paymentConfirmed: [result: ConfirmCardResponse]
}>()

const captureLoading = ref(false)
const microformReady = ref(false)
const submitting = ref(false)
const message = ref('')
const messageTone = ref<'info' | 'success' | 'error'>('info')
const paymentId = ref<number | null>(Number(props.application?.payment?.id ?? 0) || null)
const cardNetworks = ref<string[]>([])
const expMonth = ref('')
const expYear = ref('')
const numberFieldValid = ref(false)
const securityCodeValid = ref(false)

const numberContainer = ref<HTMLDivElement | null>(null)
const securityCodeContainer = ref<HTMLDivElement | null>(null)
const numberField = ref<FlexField | null>(null)
const securityCodeField = ref<FlexField | null>(null)
const microform = ref<Microform | null>(null)

const payment = computed(() => props.application?.payment ?? null)
const invoice = computed(() => props.application?.invoice ?? null)
const paymentSettled = computed(() => {
  return props.application?.payment_satisfied === true || (payment.value?.status ?? '').toString() === 'confirmed'
})
const paymentStatus = computed(() => (payment.value?.status ?? '').toString())
const paymentStatusLabel = computed(() => {
  if (!paymentStatus.value) return 'Pending payment'
  if (paymentStatus.value === 'confirmed') return 'Payment confirmed'
  if (paymentStatus.value === 'awaiting_finance_review') return 'Awaiting finance review'

  return paymentStatus.value.replaceAll('_', ' ')
})

const amountLabel = computed(() => {
  const cents = Number(invoice.value?.amount_cents ?? payment.value?.amount_cents ?? 0)
  const currency = (invoice.value?.currency ?? payment.value?.currency ?? 'ZMW').toString()

  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100)
  } catch {
    return `${(cents / 100).toFixed(2)} ${currency}`
  }
})

const years = computed(() => {
  const start = new Date().getFullYear()
  return Array.from({ length: 16 }, (_, index) => String(start + index))
})

const months = Array.from({ length: 12 }, (_, index) => String(index + 1).padStart(2, '0'))

const canSubmit = computed(() => {
  return microformReady.value
    && !captureLoading.value
    && !submitting.value
    && !paymentSettled.value
    && !!expMonth.value
    && !!expYear.value
})

const statusClass = computed(() => {
  if (messageTone.value === 'success') return 'border-success/20 bg-success/10 text-success'
  if (messageTone.value === 'error') return 'border-danger/20 bg-danger/10 text-danger'

  return 'border-border bg-surface-muted text-text-muted'
})

onMounted(() => {
  if (props.autoInit) {
    void prepareMicroform()
  }
})

defineExpose({ prepareMicroform })

onBeforeUnmount(() => {
  disposeFields()
})

watch(
  () => props.application?.payment?.id,
  (value) => {
    paymentId.value = Number(value ?? paymentId.value ?? 0) || paymentId.value
  },
)

async function prepareMicroform() {
  if (captureLoading.value || paymentSettled.value) return

  captureLoading.value = true
  message.value = 'Preparing card payment.'
  messageTone.value = 'info'
  microformReady.value = false

  try {
    disposeFields()

    const capture = await requestCaptureContext()
    const captureContext = (capture.capture_context ?? '').toString()
    if (!captureContext) throw new Error('Card payment is unavailable. Please try again later.')

    paymentId.value = Number(capture.payment_id ?? 0) || paymentId.value
    cardNetworks.value = Array.isArray(capture.card_networks) ? capture.card_networks : []

    const metadata = decodeCaptureContext(captureContext)
    await loadMicroformScript(metadata.clientLibrary, metadata.clientLibraryIntegrity)
    await nextTick()
    mountMicroform(captureContext)

    message.value = ''
    messageTone.value = 'info'
  } catch (error) {
    message.value = errorMessage(error)
    messageTone.value = 'error'
  } finally {
    captureLoading.value = false
  }
}

async function requestCaptureContext(): Promise<CaptureContextResponse> {
  const response = await (window as any).axios.post(
    `/applicant/applications/${props.application.id}/payment/card/capture-context`,
    {},
    { headers: { Accept: 'application/json' } },
  )

  return response.data ?? {}
}

function mountMicroform(captureContext: string) {
  const Flex = (window as any).Flex as FlexConstructor | undefined
  if (!Flex) throw new Error('Card payment could not load. Please refresh and try again.')
  if (!numberContainer.value || !securityCodeContainer.value) {
    throw new Error('Card payment form is not ready. Please refresh and try again.')
  }

  const flex = new Flex(captureContext)
  const styles = {
    input: {
      color: '#111827',
      'font-family': 'Inter, ui-sans-serif, system-ui, sans-serif',
      'font-size': '16px',
      'font-weight': '500',
    },
    ':focus': { color: '#111827' },
    valid: { color: '#166534' },
    invalid: { color: '#b91c1c' },
  }

  try {
    microform.value = flex.microform('card', { styles })
  } catch {
    microform.value = flex.microform({ styles })
  }

  numberField.value = microform.value.createField('number', { placeholder: 'Card number' })
  securityCodeField.value = microform.value.createField('securityCode', { placeholder: 'CVV' })

  numberField.value.on?.('change', (data?: any) => {
    numberFieldValid.value = data?.valid === true
  })
  securityCodeField.value.on?.('change', (data?: any) => {
    securityCodeValid.value = data?.valid === true
  })
  securityCodeField.value.on?.('inputSubmitRequest', () => {
    void submitPayment()
  })

  numberField.value.load(numberContainer.value)
  securityCodeField.value.load(securityCodeContainer.value)

  microformReady.value = true
}

async function submitPayment() {
  if (!canSubmit.value || !microform.value || !paymentId.value) return

  submitting.value = true
  message.value = ''
  messageTone.value = 'info'

  try {
    const transientTokenJwt = await createTransientToken()
    const response = await (window as any).axios.post(
      `/applicant/payments/${paymentId.value}/card/confirm`,
      { transient_token_jwt: transientTokenJwt },
      { headers: { Accept: 'application/json' } },
    )

    const result = (response.data ?? {}) as ConfirmCardResponse
    message.value = result.message || paymentMessage(result.payment_status)
    messageTone.value = result.payment_status === 'confirmed' ? 'success' : result.payment_status === 'pending_confirmation' ? 'info' : 'error'

    if (result.payment_status === 'confirmed') {
      emit('paymentConfirmed', result)
    }

    if (result.redirect_url) {
      window.location.assign(result.redirect_url)
      return
    }

    router.reload({ only: ['application'] })
  } catch (error) {
    message.value = errorMessage(error)
    messageTone.value = 'error'
  } finally {
    submitting.value = false
  }
}

function createTransientToken(): Promise<string> {
  return new Promise((resolve, reject) => {
    const instance = microform.value
    if (!instance) {
      reject(new Error('Card payment form is not ready. Please refresh and try again.'))
      return
    }

    instance.createToken(
      {
        expirationMonth: expMonth.value,
        expirationYear: expYear.value,
      },
      (error, token) => {
        if (error) {
          reject(new Error(error.message || 'Card details could not be tokenized.'))
          return
        }

        const jwt = transientTokenFromResponse(token)
        if (!jwt) {
          reject(new Error('Card details could not be tokenized.'))
          return
        }

        resolve(jwt)
      },
    )
  })
}

function transientTokenFromResponse(token: unknown): string {
  if (typeof token === 'string') return token
  if (!token || typeof token !== 'object') return ''

  const payload = token as Record<string, unknown>
  for (const key of ['token', 'transientTokenJwt', 'transient_token_jwt']) {
    const value = payload[key]
    if (typeof value === 'string' && value.trim() !== '') return value
  }

  return ''
}

function decodeCaptureContext(captureContext: string): CaptureContextMetadata {
  const payload = decodeJwtPayload(captureContext)
  const data = captureContextData(payload)
  const clientLibrary = typeof data.clientLibrary === 'string' ? data.clientLibrary : ''
  const clientLibraryIntegrity = typeof data.clientLibraryIntegrity === 'string' ? data.clientLibraryIntegrity : ''

  if (!clientLibrary) {
    throw new Error('Card payment is unavailable. Please try again later.')
  }

  return {
    clientLibrary,
    clientLibraryIntegrity: clientLibraryIntegrity || undefined,
  }
}

function captureContextData(payload: Record<string, unknown>): Record<string, unknown> {
  if (payload.data && typeof payload.data === 'object') {
    return payload.data as Record<string, unknown>
  }

  const contexts = Array.isArray(payload.ctx) ? payload.ctx : []
  for (const context of contexts) {
    if (!context || typeof context !== 'object') continue

    const data = (context as Record<string, unknown>).data
    if (data && typeof data === 'object') {
      return data as Record<string, unknown>
    }
  }

  return {}
}

function decodeJwtPayload(jwt: string): Record<string, unknown> {
  const [, payload] = jwt.split('.')
  if (!payload) throw new Error('Card payment is unavailable. Please try again later.')

  const normalized = payload.replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=')
  const binary = window.atob(padded)
  const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))
  const decoded = new TextDecoder().decode(bytes)

  return JSON.parse(decoded) as Record<string, unknown>
}

function loadMicroformScript(src: string, integrity?: string): Promise<void> {
  const existingFlex = (window as any).Flex
  if (existingFlex) return Promise.resolve()

  const existing = Array.from(document.querySelectorAll<HTMLScriptElement>('script[data-cybersource-microform]'))
    .find((script) => script.dataset.cybersourceMicroform === src)
  if (existing?.dataset.loaded === 'true') return Promise.resolve()

  if (scriptPromises.has(src)) {
    return scriptPromises.get(src) as Promise<void>
  }

  const promise = new Promise<void>((resolve, reject) => {
    const script = existing ?? document.createElement('script')
    script.src = src
    script.type = 'text/javascript'
    script.async = true
    script.crossOrigin = 'anonymous'
    script.dataset.cybersourceMicroform = src
    if (integrity) script.integrity = integrity

    script.onload = () => {
      script.dataset.loaded = 'true'
      resolve()
    }
    script.onerror = () => {
      script.remove()
      scriptPromises.delete(src)
      reject(new Error('Card payment could not load. Please refresh and try again.'))
    }

    if (!existing) document.head.appendChild(script)
  })

  scriptPromises.set(src, promise)

  return promise
}

function disposeFields() {
  for (const field of [numberField.value, securityCodeField.value]) {
    try {
      field?.unload?.()
      field?.dispose?.()
    } catch {
      // ignore cleanup failures
    }
  }

  numberField.value = null
  securityCodeField.value = null
  microform.value = null
  numberFieldValid.value = false
  securityCodeValid.value = false
  microformReady.value = false
}

function errorMessage(error: unknown): string {
  const err = error as any
  const status = Number(err?.response?.status ?? 0)
  const data = err?.response?.data ?? null
  const validationMessage = data?.errors?.payment?.[0] ?? data?.errors?.transient_token_jwt?.[0]

  if (validationMessage) return validationMessage.toString()
  if (typeof data?.message === 'string' && data.message.trim() !== '') return data.message
  if (status === 401 || status === 419) return 'Your session expired. Please sign in again.'
  if (status === 422) return 'Payment could not be completed. Please check the card details and try again.'
  if (status === 503) return 'Card payment is temporarily unavailable. Please try another payment option.'
  if (err instanceof Error && err.message) return err.message

  return 'Payment could not be completed. Please try again.'
}

function paymentMessage(status: unknown): string {
  const value = (status ?? '').toString()
  if (value === 'confirmed') return 'Payment confirmed.'
  if (value === 'pending_confirmation') return 'Payment is pending confirmation.'
  if (value === 'rejected') return 'Payment was rejected.'
  if (value === 'expired') return 'Payment expired.'

  return 'Payment failed. Please try again.'
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="!compact" class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
          <CreditCard class="h-4 w-4 text-brand" aria-hidden="true" />
          <span>Pay by card</span>
        </div>
        <div v-if="invoice" class="mt-1 text-xs text-text-muted">
          Amount: <span class="font-semibold text-text-primary">{{ amountLabel }}</span>
        </div>
      </div>
      <div v-if="cardNetworks.length" class="flex flex-wrap gap-1.5">
        <span
          v-for="network in cardNetworks"
          :key="network"
          class="rounded-md bg-surface-muted px-2 py-1 text-[11px] font-semibold text-text-muted ring-1 ring-black/[0.04]"
        >
          {{ network }}
        </span>
      </div>
    </div>
    <div class="text-xs text-text-muted">
      Status: <span class="font-semibold text-text-primary">{{ paymentStatusLabel }}</span>
    </div>

    <div v-if="paymentSettled" class="rounded-lg border border-success/20 bg-success/10 px-3 py-2 text-sm text-success">
      <CheckCircle2 class="mr-1 inline h-4 w-4" aria-hidden="true" />
      Payment confirmed.
    </div>

    <template v-else>
      <div v-if="captureLoading" class="flex items-center gap-2 rounded-lg border border-border bg-surface-muted px-3 py-2 text-sm text-text-muted">
        <LoaderCircle class="h-4 w-4 animate-spin" aria-hidden="true" />
        <span>Preparing card payment.</span>
      </div>

      <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
        <div>
          <label class="text-sm font-medium text-text-primary">Card number</label>
          <div
            ref="numberContainer"
            class="cybersource-field mt-1"
            :class="numberFieldValid ? 'cybersource-field-valid' : ''"
          ></div>
        </div>
        <div>
          <label class="text-sm font-medium text-text-primary">CVV</label>
          <div
            ref="securityCodeContainer"
            class="cybersource-field mt-1"
            :class="securityCodeValid ? 'cybersource-field-valid' : ''"
          ></div>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="cybersource-exp-month" class="text-sm font-medium text-text-primary">Expiry month</label>
          <select id="cybersource-exp-month" v-model="expMonth" class="zaqa-input mt-1 h-12">
            <option value="">Month</option>
            <option v-for="month in months" :key="month" :value="month">{{ month }}</option>
          </select>
        </div>
        <div>
          <label for="cybersource-exp-year" class="text-sm font-medium text-text-primary">Expiry year</label>
          <select id="cybersource-exp-year" v-model="expYear" class="zaqa-input mt-1 h-12">
            <option value="">Year</option>
            <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
          </select>
        </div>
      </div>

      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary w-full sm:w-auto"
          :disabled="!canSubmit"
          @click="submitPayment"
        >
          <LoaderCircle v-if="submitting" class="h-4 w-4 animate-spin" aria-hidden="true" />
          <ShieldCheck v-else class="h-4 w-4" aria-hidden="true" />
          Pay by card
        </button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto"
          :disabled="captureLoading || submitting"
          @click="prepareMicroform"
        >
          <RefreshCw class="h-4 w-4" aria-hidden="true" />
          Reload card form
        </button>
      </div>

      <div v-if="message" class="rounded-lg border px-3 py-2 text-sm" :class="statusClass" role="alert">
        <component :is="messageTone === 'success' ? CheckCircle2 : AlertCircle" class="mr-1 inline h-4 w-4" aria-hidden="true" />
        {{ message }}
      </div>
    </template>
  </div>
</template>

<style scoped>
.cybersource-field {
  min-height: 3rem;
  border: 1px solid var(--zaqa-border, #d6e2ea);
  border-radius: 0.5rem;
  background: var(--zaqa-surface, #ffffff);
  padding: 0.75rem;
}

.cybersource-field:focus-within {
  outline: 2px solid var(--zaqa-accent, #f18230);
  outline-offset: 2px;
}

.cybersource-field-valid {
  border-color: var(--zaqa-success, #15803d);
}
</style>
