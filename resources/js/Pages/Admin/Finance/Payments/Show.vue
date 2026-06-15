<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { Link, router } from '@inertiajs/vue3'
import {
  BadgeCheck,
  Banknote,
  Building2,
  CalendarClock,
  ChevronRight,
  CircleAlert,
  CircleX,
  Clock,
  CreditCard,
  ExternalLink,
  FileText,
  Hash,
  Mail,
  Phone,
  Receipt,
  ScrollText,
  ShieldCheck,
  UserRound,
  Wallet,
} from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'
import { computed, ref } from 'vue'

const props = defineProps<{
  payment: any
  webhooks: Array<any>
  can: {
    correct: boolean
    view_applicant: boolean
    view_qualifications: boolean
  }
  correction: {
    enabled: boolean
    disabled_reason: string | null
    status_options: Array<{ value: string; label: string }>
  }
  navigation: {
    applicant: { name: string | null; href: string | null } | null
    qualifications: Array<{
      id: number
      title: string | null
      holder_name: string | null
      is_foreign: boolean
      href: string | null
    }>
  }
  history: Array<any>
}>()

const correctionOpen = ref(false)
const correctionStatus = ref(props.correction.status_options[0]?.value ?? props.payment.status ?? '')
const correctionNote = ref('')
const correctionProviderTransactionId = ref(props.payment.provider_transaction_id ?? '')

const createdAt = computed(() => props.payment.created_at ?? props.payment.initiated_at ?? null)
const confirmingPayment = computed(() => correctionStatus.value === 'confirmed')
const applicationHref = computed(() => (
  props.payment.application?.id
    ? `/finance/applications/${props.payment.application.id}/track`
    : null
))
const proofDetailHref = computed(() => (
  props.payment.method === 'bank_deposit' || props.payment.method === 'bank_transfer'
    ? `/admin/finance/payment-proofs/${props.payment.id}`
    : null
))
const latestUpdateAt = computed(() => latestKnownAt([
  props.history[0]?.created_at,
  props.payment.reviewed_at,
  props.payment.rejected_at,
  props.payment.failed_at,
  props.payment.confirmed_at,
  props.payment.initiated_at,
  props.payment.created_at,
]))
const applicationTypeLabel = computed(() => (
  props.payment.application?.is_foreign
    ? 'Foreign qualification verification'
    : 'Local qualification verification'
))
const referenceRows = computed(() => ([
  { label: 'Provider reference', value: props.payment.provider_reference },
  { label: 'Transaction reference', value: props.payment.provider_transaction_id },
]).filter((row) => row.value))
const paymentStatusRows = computed(() => [
  { label: 'Status', value: humanize(props.payment.status) },
  { label: 'Method', value: humanize(props.payment.method) },
  { label: 'Provider', value: props.payment.provider ?? '—' },
  { label: 'Created', value: formatDateTime(createdAt.value) },
  { label: 'Latest update', value: formatDateTime(latestUpdateAt.value) },
])
const summaryCards = computed(() => [
  {
    key: 'amount',
    label: 'Amount',
    value: formatMoneyFromCents(props.payment.amount_cents, props.payment.currency),
    meta: props.payment.currency ?? '—',
    icon: Wallet,
  },
  {
    key: 'method',
    label: 'Payment method',
    value: humanize(props.payment.method),
    meta: props.payment.provider ?? '—',
    icon: CreditCard,
  },
  {
    key: 'invoice',
    label: 'Invoice',
    value: props.payment.invoice?.invoice_number ?? '—',
    meta: humanize(props.payment.invoice?.status),
    icon: Receipt,
  },
  {
    key: 'created',
    label: 'Created',
    value: formatDateShort(createdAt.value),
    meta: formatTimeShort(createdAt.value),
    icon: CalendarClock,
  },
])

const timelineStages = computed(() => {
  const currentKey = currentTimelineKey(props.payment.status)
  const stages = [
    { key: 'initiated', label: 'Initiated', at: props.payment.initiated_at ?? createdAt.value, icon: Clock },
    { key: 'confirmed', label: 'Confirmed', at: props.payment.confirmed_at, icon: BadgeCheck },
    { key: 'failed', label: props.payment.status === 'expired' ? 'Expired' : 'Failed', at: props.payment.status === 'expired' ? props.payment.expires_at : props.payment.failed_at, icon: CircleX },
    { key: 'rejected', label: 'Rejected', at: props.payment.rejected_at, icon: CircleAlert },
  ]
  const currentIndex = stages.findIndex((stage) => stage.key === currentKey)

  return stages.map((stage, index) => ({
    ...stage,
    state: currentIndex === index
      ? 'current'
      : (currentIndex > index && stage.at ? 'completed' : 'inactive'),
  }))
})

function humanize(value: string | null | undefined) {
  const normalized = (value ?? '').trim().replaceAll('_', ' ')
  if (!normalized) return '—'

  return normalized.replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatDateShort(value: string | null | undefined) {
  if (!value) return '—'

  return new Intl.DateTimeFormat(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value))
}

function formatTimeShort(value: string | null | undefined) {
  if (!value) return '—'

  return new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

function formatDateTime(value: string | null | undefined) {
  if (!value) return '—'

  return new Intl.DateTimeFormat(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

function latestKnownAt(values: Array<string | null | undefined>) {
  return values
    .filter((value): value is string => Boolean(value))
    .sort((left, right) => new Date(right).getTime() - new Date(left).getTime())[0] ?? null
}

function currentTimelineKey(status: string | null | undefined) {
  if (status === 'confirmed') return 'confirmed'
  if (status === 'failed' || status === 'expired') return 'failed'
  if (status === 'rejected') return 'rejected'
  return 'initiated'
}

function statusBadgeClass(status: string | null | undefined) {
  if (status === 'confirmed') return 'border-emerald-200/80 bg-emerald-50 text-emerald-700'
  if (status === 'failed' || status === 'rejected' || status === 'expired') return 'border-rose-200/80 bg-rose-50 text-rose-700'
  if (status === 'awaiting_finance_review' || status === 'pending_confirmation' || status === 'initiated' || status === 'pending') return 'border-amber-200/80 bg-amber-50 text-amber-700'
  return 'border-border/80 bg-surface-muted text-text-primary'
}

function timelineCardClass(state: 'completed' | 'current' | 'inactive') {
  if (state === 'completed') return 'border-emerald-200/70 bg-emerald-50/80'
  if (state === 'current') return 'border-sky-200/80 bg-sky-50/80 shadow-[0_12px_30px_-18px_rgba(2,132,199,0.45)]'
  return 'border-border/70 bg-surface-muted/35'
}

function timelineIconClass(state: 'completed' | 'current' | 'inactive') {
  if (state === 'completed') return 'bg-emerald-500 text-white'
  if (state === 'current') return 'bg-sky-500 text-white'
  return 'bg-surface text-text-muted ring-1 ring-border/70'
}

function correctionSummary(entry: any) {
  const parts: string[] = []

  if ((entry.before_status ?? null) !== (entry.after_status ?? null) && entry.after_status) {
    parts.push(`${entry.before_status ?? '—'} -> ${entry.after_status}`)
  }

  if ((entry.before_provider_transaction_id ?? null) !== (entry.after_provider_transaction_id ?? null)) {
    parts.push(`TX: ${entry.before_provider_transaction_id ?? '—'} -> ${entry.after_provider_transaction_id ?? '—'}`)
  }

  return parts.length > 0 ? parts.join(' · ') : 'Recorded finance action'
}
</script>

<template>
  <AdminLayout>
    <div class="space-y-6">
      <section class="rounded-3xl border border-border/60 bg-gradient-to-br from-surface via-surface to-surface-muted/45 p-6 shadow-[0_24px_60px_-36px_rgba(15,23,42,0.45)] sm:p-7">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.22em] text-text-muted">
              <span>Finance</span>
              <ChevronRight class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Payments</span>
              <ChevronRight class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Payment #{{ payment.id }}</span>
            </div>

            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
              <h1 class="text-3xl font-semibold tracking-tight text-text-primary">Payment #{{ payment.id }}</h1>
              <span class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(payment.status)">
                {{ humanize(payment.status) }}
              </span>
            </div>

            <p class="mt-3 max-w-3xl text-sm text-text-muted">
              View payment details, invoice linkage and transaction history.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2 xl:justify-end">
            <a
              v-if="payment.invoice?.download_url"
              :href="payment.invoice.download_url"
              class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
            >
              Download invoice
            </a>
            <Link href="/admin/finance/payments" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
              Back to payments
            </Link>
            <button
              v-if="can.correct && correction.enabled"
              type="button"
              class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
              @click="correctionOpen = true"
            >
              Update payment
            </button>
          </div>
        </div>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article
          v-for="card in summaryCards"
          :key="card.key"
          class="rounded-2xl border border-border/60 bg-surface p-5 shadow-[0_18px_45px_-30px_rgba(15,23,42,0.35)]"
        >
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">{{ card.label }}</div>
              <div class="mt-3 text-lg font-semibold text-text-primary">{{ card.value }}</div>
              <div class="mt-1 text-sm text-text-muted">{{ card.meta }}</div>
            </div>
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-surface-muted/70 text-text-primary ring-1 ring-border/60">
              <component :is="card.icon" class="h-5 w-5" aria-hidden="true" />
            </span>
          </div>
        </article>
      </section>

      <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(280px,0.9fr)]">
        <div class="space-y-6">
          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="text-lg font-semibold text-text-primary">Payment Information</h2>
                <p class="mt-1 text-sm text-text-muted">Application, applicant and transaction references linked to this payment.</p>
              </div>
            </div>

            <div class="mt-6 divide-y divide-border/60">
              <section class="pb-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                      <Building2 class="h-4 w-4 text-text-muted" aria-hidden="true" />
                      Application
                    </div>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                      <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">Application reference</div>
                        <div class="mt-1 text-sm font-medium text-text-primary">{{ payment.application?.application_number ?? '—' }}</div>
                      </div>
                      <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">Application type</div>
                        <div class="mt-1 text-sm font-medium text-text-primary">{{ applicationTypeLabel }}</div>
                      </div>
                    </div>
                  </div>

                  <Link
                    v-if="applicationHref"
                    :href="applicationHref"
                    class="zaqa-btn zaqa-btn-secondary h-10 px-3 py-2 text-xs"
                  >
                    <ExternalLink class="h-4 w-4" aria-hidden="true" />
                    Open application
                  </Link>
                </div>
              </section>

              <section class="py-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                      <UserRound class="h-4 w-4 text-text-muted" aria-hidden="true" />
                      Applicant
                    </div>
                    <div class="mt-3 space-y-3">
                      <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">Applicant name</div>
                        <div class="mt-1 text-sm font-medium text-text-primary">{{ payment.applicant?.name ?? '—' }}</div>
                      </div>
                      <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl bg-surface-muted/45 px-4 py-3">
                          <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
                            <Phone class="h-3.5 w-3.5" aria-hidden="true" />
                            Phone
                          </div>
                          <div class="mt-1 text-sm text-text-primary">{{ payment.applicant?.phone ?? '—' }}</div>
                        </div>
                        <div class="rounded-2xl bg-surface-muted/45 px-4 py-3">
                          <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
                            <Mail class="h-3.5 w-3.5" aria-hidden="true" />
                            Email
                          </div>
                          <div class="mt-1 break-all text-sm text-text-primary">{{ payment.applicant?.email ?? '—' }}</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <Link
                    v-if="navigation.applicant?.href"
                    :href="navigation.applicant.href"
                    class="zaqa-btn zaqa-btn-secondary h-10 px-3 py-2 text-xs"
                  >
                    <ExternalLink class="h-4 w-4" aria-hidden="true" />
                    Open applicant
                  </Link>
                </div>
              </section>

              <section class="py-5">
                <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                  <Hash class="h-4 w-4 text-text-muted" aria-hidden="true" />
                  References
                </div>

                <div v-if="referenceRows.length > 0" class="mt-3 grid gap-3 sm:grid-cols-2">
                  <div
                    v-for="row in referenceRows"
                    :key="row.label"
                    class="rounded-2xl bg-surface-muted/45 px-4 py-3"
                  >
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">{{ row.label }}</div>
                    <div class="mt-1 break-all text-sm font-medium text-text-primary">{{ row.value }}</div>
                  </div>
                </div>
                <div v-else class="mt-3 rounded-2xl bg-surface-muted/40 px-4 py-3 text-sm text-text-muted">
                  No transaction references recorded for this payment.
                </div>
              </section>

              <section class="pt-5">
                <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                  <FileText class="h-4 w-4 text-text-muted" aria-hidden="true" />
                  Qualifications
                </div>

                <div v-if="navigation.qualifications.length === 0" class="mt-3 rounded-2xl bg-surface-muted/40 px-4 py-3 text-sm text-text-muted">
                  No qualifications linked to this application.
                </div>
                <div v-else class="mt-4 space-y-3">
                  <div
                    v-for="qualification in navigation.qualifications"
                    :key="qualification.id"
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-surface-muted/25 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div class="min-w-0">
                      <div class="truncate text-sm font-semibold text-text-primary">
                        {{ qualification.title ?? `Qualification #${qualification.id}` }}
                      </div>
                      <div class="mt-1 text-xs text-text-muted">
                        {{ qualification.is_foreign ? 'Foreign' : 'Local' }}
                        <span v-if="qualification.holder_name"> · {{ qualification.holder_name }}</span>
                      </div>
                    </div>

                    <Link
                      v-if="qualification.href"
                      :href="qualification.href"
                      class="zaqa-btn zaqa-btn-secondary h-10 px-3 py-2 text-xs"
                    >
                      <ExternalLink class="h-4 w-4" aria-hidden="true" />
                      Open qualification
                    </Link>
                  </div>
                </div>
              </section>
            </div>
          </section>

          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
            <div class="flex items-center gap-2">
              <ShieldCheck class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-lg font-semibold text-text-primary">Payment Timeline</h2>
                <p class="mt-1 text-sm text-text-muted">Key payment milestones and their recorded timestamps.</p>
              </div>
            </div>

            <div class="relative mt-6">
              <div class="absolute bottom-0 left-5 top-0 w-px bg-border/70 md:left-0 md:right-0 md:top-5 md:h-px md:w-auto" aria-hidden="true" />
              <div class="relative grid gap-4 md:grid-cols-4">
                <article
                  v-for="stage in timelineStages"
                  :key="stage.key"
                  class="relative flex items-start gap-4 rounded-2xl border p-4 md:flex-col md:items-center md:text-center"
                  :class="timelineCardClass(stage.state)"
                >
                  <span class="relative z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl shadow-sm" :class="timelineIconClass(stage.state)">
                    <component :is="stage.icon" class="h-5 w-5" aria-hidden="true" />
                  </span>
                  <div class="min-w-0">
                    <div class="text-sm font-semibold text-text-primary">{{ stage.label }}</div>
                    <div class="mt-1 text-xs text-text-muted">{{ formatDateTime(stage.at) }}</div>
                  </div>
                </article>
              </div>
            </div>
          </section>

          <section v-if="(payment.attempts ?? []).length > 0" class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
            <div class="flex items-center gap-2">
              <CreditCard class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-lg font-semibold text-text-primary">Gateway Attempts</h2>
                <p class="mt-1 text-sm text-text-muted">Recorded provider prompts, references and query results.</p>
              </div>
            </div>

            <div class="mt-5 space-y-3">
              <article
                v-for="attempt in payment.attempts"
                :key="attempt.id"
                class="rounded-2xl border border-border/60 bg-surface-muted/25 p-4"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Attempt #{{ attempt.id }} · {{ attempt.gateway ?? '—' }}</div>
                    <div class="mt-1 text-xs text-text-muted">{{ humanize(attempt.method) }}</div>
                  </div>
                  <span class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(attempt.status)">
                    {{ humanize(attempt.status) }}
                  </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                  <div class="rounded-xl bg-surface px-3 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Reference</div>
                    <div class="mt-1 break-all text-sm text-text-primary">{{ attempt.payment_reference ?? '—' }}</div>
                  </div>
                  <div class="rounded-xl bg-surface px-3 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Mobile</div>
                    <div class="mt-1 text-sm text-text-primary">{{ attempt.mobile_number ?? '—' }}</div>
                  </div>
                  <div class="rounded-xl bg-surface px-3 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Response code</div>
                    <div class="mt-1 text-sm text-text-primary">{{ attempt.response_code ?? '—' }}</div>
                  </div>
                  <div class="rounded-xl bg-surface px-3 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Last queried</div>
                    <div class="mt-1 text-sm text-text-primary">{{ formatDateTime(attempt.last_queried_at) }}</div>
                  </div>
                </div>

                <div class="mt-3 rounded-xl bg-surface px-3 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Message</div>
                  <div class="mt-1 text-sm text-text-primary">{{ attempt.response_message ?? '—' }}</div>
                </div>
              </article>
            </div>
          </section>

          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
            <div class="flex items-center gap-2">
              <Banknote class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-lg font-semibold text-text-primary">Webhook / Return Events</h2>
                <p class="mt-1 text-sm text-text-muted">Provider callbacks and return handling records for this payment.</p>
              </div>
            </div>

            <div v-if="webhooks.length === 0" class="mt-5 rounded-2xl bg-surface-muted/40 px-4 py-4 text-sm text-text-muted">
              No webhook/return logs recorded for this payment.
            </div>
            <div v-else class="mt-5 space-y-3">
              <article
                v-for="webhook in webhooks"
                :key="webhook.id"
                class="rounded-2xl border border-border/60 bg-surface-muted/25 p-4"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">{{ webhook.provider }}</div>
                    <div class="mt-1 text-xs text-text-muted">{{ webhook.event_type }}</div>
                  </div>
                  <span class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(webhook.process_status)">
                    {{ humanize(webhook.process_status) }}
                  </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                  <div class="rounded-xl bg-surface px-3 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Received</div>
                    <div class="mt-1 text-sm text-text-primary">{{ formatDateTime(webhook.received_at) }}</div>
                  </div>
                  <div class="rounded-xl bg-surface px-3 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Processed</div>
                    <div class="mt-1 text-sm text-text-primary">{{ formatDateTime(webhook.processed_at) }}</div>
                  </div>
                </div>

                <div v-if="webhook.error_message" class="mt-3 rounded-xl bg-rose-50/70 px-3 py-3 text-sm text-rose-700">
                  {{ webhook.error_message }}
                </div>
              </article>
            </div>
          </section>

          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
            <div class="flex items-center gap-2">
              <ScrollText class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-lg font-semibold text-text-primary">Finance Action History</h2>
                <p class="mt-1 text-sm text-text-muted">Corrections and review activity recorded against this payment.</p>
              </div>
            </div>

            <div v-if="history.length === 0" class="mt-5 rounded-2xl bg-surface-muted/40 px-4 py-4 text-sm text-text-muted">
              No finance status corrections or review actions recorded yet.
            </div>
            <div v-else class="mt-5 space-y-3">
              <article
                v-for="entry in history"
                :key="entry.id"
                class="rounded-2xl border border-border/60 bg-surface-muted/25 p-4"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0">
                    <div class="text-sm font-semibold text-text-primary">{{ entry.message }}</div>
                    <div class="mt-1 text-xs text-text-muted">{{ correctionSummary(entry) }}</div>
                  </div>
                  <div class="text-xs text-text-muted sm:text-right">
                    <div>{{ entry.actor_name ?? 'System' }}</div>
                    <div class="mt-1">{{ formatDateTime(entry.created_at) }}</div>
                  </div>
                </div>

                <div v-if="entry.note" class="mt-3 rounded-xl bg-surface px-3 py-3 text-sm text-text-primary">
                  {{ entry.note }}
                </div>
              </article>
            </div>
          </section>
        </div>

        <aside class="space-y-6">
          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)]">
            <div class="flex items-center gap-2">
              <FileText class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-base font-semibold text-text-primary">Proof Document</h2>
                <p class="mt-1 text-sm text-text-muted">Uploaded proof file and related actions.</p>
              </div>
            </div>

            <div v-if="payment.proof_document" class="mt-5 rounded-2xl bg-surface-muted/35 p-4">
              <div class="text-sm font-semibold text-text-primary">{{ payment.proof_document.original_name }}</div>
              <div class="mt-3 flex flex-wrap gap-2">
                <a :href="payment.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-10 px-3 py-2 text-xs">
                  Preview
                </a>
                <a :href="payment.proof_document.download_url" class="zaqa-btn zaqa-btn-secondary h-10 px-3 py-2 text-xs">
                  Download
                </a>
                <Link v-if="proofDetailHref" :href="proofDetailHref" class="zaqa-btn zaqa-btn-secondary h-10 px-3 py-2 text-xs">
                  Open proof review
                </Link>
              </div>
            </div>
            <div v-else class="mt-5 rounded-2xl bg-surface-muted/40 px-4 py-4 text-sm text-text-muted">
              No proof document uploaded.
            </div>
          </section>

          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)]">
            <div class="flex items-center gap-2">
              <ShieldCheck class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-base font-semibold text-text-primary">Payment Status</h2>
                <p class="mt-1 text-sm text-text-muted">Current payment state and recorded metadata.</p>
              </div>
            </div>

            <div class="mt-5">
              <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusBadgeClass(payment.status)">
                {{ humanize(payment.status) }}
              </span>
            </div>

            <dl class="mt-5 space-y-3">
              <div
                v-for="row in paymentStatusRows"
                :key="row.label"
                class="flex items-start justify-between gap-3 rounded-2xl bg-surface-muted/35 px-4 py-3"
              >
                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">{{ row.label }}</dt>
                <dd class="text-right text-sm font-medium text-text-primary">{{ row.value }}</dd>
              </div>
            </dl>
          </section>

          <section
            v-if="payment.reviewed_at || payment.review_comment || payment.rejection_reason"
            class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)]"
          >
            <div class="flex items-center gap-2">
              <BadgeCheck class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-base font-semibold text-text-primary">Manual Review</h2>
                <p class="mt-1 text-sm text-text-muted">Finance review notes captured for this payment.</p>
              </div>
            </div>

            <div class="mt-5 space-y-3">
              <div class="rounded-2xl bg-surface-muted/35 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">Reviewed at</div>
                <div class="mt-1 text-sm text-text-primary">{{ formatDateTime(payment.reviewed_at) }}</div>
              </div>
              <div class="rounded-2xl bg-surface-muted/35 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">Reviewer</div>
                <div class="mt-1 text-sm text-text-primary">{{ payment.reviewed_by ?? '—' }}</div>
              </div>
              <div v-if="payment.review_comment" class="rounded-2xl bg-surface-muted/35 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">Comment</div>
                <div class="mt-1 whitespace-pre-wrap text-sm text-text-primary">{{ payment.review_comment }}</div>
              </div>
              <div v-if="payment.rejection_reason" class="rounded-2xl bg-rose-50/70 px-4 py-3">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-700">Reason</div>
                <div class="mt-1 whitespace-pre-wrap text-sm text-rose-700">{{ payment.rejection_reason }}</div>
              </div>
            </div>
          </section>

          <section
            v-if="can.correct && correction.disabled_reason"
            class="rounded-3xl border border-amber-200/70 bg-amber-50/80 p-6 shadow-[0_20px_50px_-34px_rgba(245,158,11,0.28)]"
          >
            <div class="flex items-start gap-3">
              <CircleAlert class="mt-0.5 h-5 w-5 text-amber-600" aria-hidden="true" />
              <div>
                <h2 class="text-base font-semibold text-amber-700">Correction unavailable</h2>
                <p class="mt-2 text-sm text-amber-700/90">{{ correction.disabled_reason }}</p>
              </div>
            </div>
          </section>

          <section class="rounded-3xl border border-border/60 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)]">
            <div class="flex items-center gap-2">
              <ScrollText class="h-5 w-5 text-text-primary" aria-hidden="true" />
              <div>
                <h2 class="text-base font-semibold text-text-primary">Raw Payload</h2>
                <p class="mt-1 text-sm text-text-muted">Stored provider payload for this payment.</p>
              </div>
            </div>

            <div v-if="payment.raw_payload" class="mt-5 overflow-hidden rounded-2xl bg-surface-muted/35">
              <pre class="max-h-[26rem] overflow-auto whitespace-pre-wrap break-all p-4 text-xs text-text-primary">{{ JSON.stringify(payment.raw_payload, null, 2) }}</pre>
            </div>
            <div v-else class="mt-5 rounded-2xl bg-surface-muted/40 px-4 py-4 text-sm text-text-muted">
              No raw payload stored.
            </div>
          </section>
        </aside>
      </div>
    </div>

    <AdminActionModal
      v-model="correctionOpen"
      title="Update payment"
      description="Manually update the recorded payment status or transaction ID. Confirming a payment requires the provider transaction ID and will move the application into the submission flow."
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Target status</label>
          <select v-model="correctionStatus" class="zaqa-input mt-2 h-11">
            <option v-for="option in correction.status_options" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div>
          <label class="text-sm font-semibold text-text-primary">
            Provider transaction ID
            <span v-if="confirmingPayment" class="text-danger">*</span>
          </label>
          <input
            v-model="correctionProviderTransactionId"
            type="text"
            class="zaqa-input mt-2 h-11"
            :placeholder="confirmingPayment ? 'Required when confirming this payment' : 'Optional transaction ID override'"
          />
          <div v-if="confirmingPayment" class="mt-2 text-xs text-text-muted">
            This is required before the payment can be marked as confirmed.
          </div>
        </div>

        <div>
          <label class="text-sm font-semibold text-text-primary">Correction note</label>
          <textarea
            v-model="correctionNote"
            class="zaqa-input mt-2 h-auto min-h-[8rem] py-3"
            placeholder="Explain why this payment record is being corrected."
          />
        </div>
      </div>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="correctionOpen = false">
          Cancel
        </button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="!correctionStatus || !correctionNote.trim() || (confirmingPayment && !correctionProviderTransactionId.trim())"
          @click="router.post(`/admin/finance/payments/${payment.id}/correct`, {
            status: correctionStatus,
            note: correctionNote,
            provider_transaction_id: correctionProviderTransactionId || null,
          }, {
            preserveScroll: true,
            onSuccess: () => {
              correctionOpen = false
            },
          })"
        >
          Save correction
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
