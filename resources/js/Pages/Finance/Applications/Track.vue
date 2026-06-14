<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import {
  BadgeCheck,
  CalendarClock,
  ChevronDown,
  CircleAlert,
  Clock,
  CreditCard,
  FileEdit,
  FileText,
  GitBranch,
  Landmark,
  Send,
  ShieldCheck,
  Sparkles,
  User,
} from 'lucide-vue-next'

type ApplicationData = {
  id: number
  application_number: string
  current_status?: string | null
  status_label?: string | null
  is_foreign?: boolean
  qualification_type?: {
    level_label?: string | null
    name?: string | null
  } | null
}

type TimelineEvent = {
  id: number | string
  event_code?: string | null
  title?: string | null
  description?: string | null
  comment?: string | null
  occurred_at?: string | null
  actor_name?: string | null
  visibility?: string | null
  status_snapshot?: string | null
}

type StatusHistory = {
  id: number | string
  from_status?: string | null
  to_status?: string | null
  comment?: string | null
  changed_at?: string | null
  actor_name?: string | null
}

type ToneKey = 'success' | 'info' | 'warning' | 'danger' | 'neutral'
type ImportanceKey = 'high' | 'normal' | 'low'

type EventPresentation = {
  icon: any
  tone: ToneKey
  importance: ImportanceKey
}

const props = defineProps<{
  application: ApplicationData
  events: TimelineEvent[]
  statusHistories: StatusHistory[]
}>()

const showAllPrimaryEvents = ref(false)
const showMinorEvents = ref(false)

function normalize(value: string | null | undefined) {
  return (value ?? '').toString().trim().toLowerCase()
}

function formatLabel(value: string | null | undefined) {
  const normalized = (value ?? '').toString().trim()
  if (normalized === '') return '—'

  return normalized
    .replaceAll('.', ' ')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatDateTime(value: string | null | undefined) {
  if (!value) return '—'

  try {
    return new Intl.DateTimeFormat(undefined, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(new Date(value))
  } catch {
    return value
  }
}

function formatRelative(value: string | null | undefined) {
  if (!value) return null

  const timestamp = new Date(value).getTime()
  if (Number.isNaN(timestamp)) return null

  const diffMs = Date.now() - timestamp
  const seconds = Math.round(Math.abs(diffMs) / 1000)
  const direction = diffMs >= 0 ? -1 : 1
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

  if (seconds < 60) return rtf.format(direction * Math.max(1, seconds), 'second')

  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return rtf.format(direction * minutes, 'minute')

  const hours = Math.round(minutes / 60)
  if (hours < 48) return rtf.format(direction * hours, 'hour')

  const days = Math.round(hours / 24)
  if (days < 30) return rtf.format(direction * days, 'day')

  const weeks = Math.round(days / 7)
  if (weeks < 9) return rtf.format(direction * weeks, 'week')

  return null
}

function toneForStatus(status: string | null | undefined): ToneKey {
  const value = normalize(status)

  if (value.includes('approved') || value.includes('confirmed') || value.includes('issued') || value.includes('completed')) {
    return 'success'
  }

  if (value.includes('rejected') || value.includes('failed')) {
    return 'danger'
  }

  if (value.includes('expired') || value.includes('pending') || value.includes('payment') || value.includes('sent back') || value.includes('returned')) {
    return 'warning'
  }

  if (value.includes('submitted') || value.includes('review') || value.includes('verified') || value.includes('assigned') || value.includes('initiated')) {
    return 'info'
  }

  return 'neutral'
}

function badgeClass(tone: ToneKey) {
  if (tone === 'success') return 'border-emerald-500/20 bg-emerald-500/10 text-emerald-800'
  if (tone === 'danger') return 'border-rose-500/20 bg-rose-500/10 text-rose-800'
  if (tone === 'warning') return 'border-amber-500/25 bg-amber-500/10 text-amber-800'
  if (tone === 'info') return 'border-sky-500/20 bg-sky-500/10 text-sky-800'
  return 'border-border/70 bg-surface-muted/45 text-text-primary'
}

function timelineIconClass(tone: ToneKey) {
  if (tone === 'success') return 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700'
  if (tone === 'danger') return 'border-rose-500/20 bg-rose-500/10 text-rose-700'
  if (tone === 'warning') return 'border-amber-500/25 bg-amber-500/10 text-amber-700'
  if (tone === 'info') return 'border-sky-500/20 bg-sky-500/10 text-sky-700'
  return 'border-border/70 bg-surface text-text-muted'
}

function eventPresentation(event: TimelineEvent): EventPresentation {
  const code = normalize(event.event_code)
  const title = normalize(event.title)
  const text = `${code} ${title}`

  if (text.includes('certificate issued')) {
    return { icon: BadgeCheck, tone: 'success', importance: 'high' }
  }

  if (text.includes('approved') || text.includes('verified')) {
    return { icon: BadgeCheck, tone: 'success', importance: 'high' }
  }

  if (text.includes('rejected') || text.includes('failed')) {
    return { icon: CircleAlert, tone: 'danger', importance: 'high' }
  }

  if (text.includes('expired') || text.includes('sent back') || text.includes('returned')) {
    return { icon: CircleAlert, tone: 'warning', importance: 'high' }
  }

  if (code.startsWith('review.') || text.includes('review started') || text.includes('assignment') || text.includes('assigned')) {
    return { icon: ShieldCheck, tone: 'info', importance: 'high' }
  }

  if (code.startsWith('submission.') || text.includes('application submitted') || text.includes('application resubmitted')) {
    return { icon: Send, tone: 'info', importance: 'high' }
  }

  if (text.includes('payment confirmed')) {
    return { icon: CreditCard, tone: 'success', importance: 'high' }
  }

  if (text.includes('payment failed') || text.includes('payment rejected')) {
    return { icon: CreditCard, tone: 'danger', importance: 'high' }
  }

  if (
    text.includes('payment expired') ||
    text.includes('payment initiated') ||
    text.includes('payment method selected') ||
    text.includes('proof of payment uploaded')
  ) {
    return { icon: CreditCard, tone: toneForStatus(text), importance: 'high' }
  }

  if (text.includes('invoice generated') || text.includes('supplementary invoice issued') || text.includes('invoice updated')) {
    return { icon: Landmark, tone: 'info', importance: 'high' }
  }

  if (text.includes('document')) {
    return { icon: FileText, tone: 'neutral', importance: 'normal' }
  }

  if (text.includes('qualification')) {
    return {
      icon: FileEdit,
      tone: 'neutral',
      importance: text.includes('saved') || text.includes('updated') || text.includes('amended') ? 'normal' : 'high',
    }
  }

  if (
    text.includes('declaration') ||
    text.includes('subject results') ||
    text.includes('applicant details') ||
    text.includes('verification subject saved') ||
    text.includes('consent') ||
    text.includes('draft updated') ||
    code.startsWith('wizard.')
  ) {
    return { icon: FileEdit, tone: 'neutral', importance: 'low' }
  }

  if (text.includes('draft created')) {
    return { icon: FileEdit, tone: 'neutral', importance: 'high' }
  }

  return { icon: Clock, tone: 'neutral', importance: 'normal' }
}

function visibilityLabel(value: string | null | undefined) {
  const normalized = normalize(value)
  if (normalized === '' || normalized === 'both') return null
  if (normalized === 'applicant') return 'Applicant'
  if (normalized === 'internal') return 'Internal'
  return formatLabel(value)
}

const timeline = computed(() => Array.isArray(props.events) ? props.events : [])

const decoratedTimeline = computed(() => timeline.value.map((event) => {
  const presentation = eventPresentation(event)
  const statusLabel = event.status_snapshot ? formatLabel(event.status_snapshot) : null
  const titleText = normalize(event.title)
  const showStatusSnapshot = Boolean(statusLabel) && !titleText.includes(normalize(statusLabel))

  return {
    ...event,
    presentation,
    actorLabel: event.actor_name ?? 'System',
    formattedAt: formatDateTime(event.occurred_at),
    relativeAt: formatRelative(event.occurred_at),
    visibilityLabel: visibilityLabel(event.visibility),
    statusLabel: showStatusSnapshot ? statusLabel : null,
  }
}))

const primaryTimelineBase = computed(() => {
  const majorEvents = decoratedTimeline.value.filter((event) => event.presentation.importance !== 'low')
  return majorEvents.length > 0 ? majorEvents : decoratedTimeline.value
})

const minorTimeline = computed(() => {
  if (primaryTimelineBase.value === decoratedTimeline.value) {
    return []
  }

  return decoratedTimeline.value.filter((event) => event.presentation.importance === 'low')
})

const visiblePrimaryTimeline = computed(() => {
  if (showAllPrimaryEvents.value) return primaryTimelineBase.value
  return primaryTimelineBase.value.slice(0, 8)
})

const remainingPrimaryCount = computed(() => Math.max(0, primaryTimelineBase.value.length - visiblePrimaryTimeline.value.length))

const decoratedStatusHistories = computed(() => {
  const rows = Array.isArray(props.statusHistories) ? props.statusHistories : []

  return rows.map((history) => ({
    ...history,
    fromLabel: history.from_status ? formatLabel(history.from_status) : '—',
    toLabel: formatLabel(history.to_status),
    formattedAt: formatDateTime(history.changed_at),
    relativeAt: formatRelative(history.changed_at),
    tone: toneForStatus(history.to_status),
  }))
})

const qualificationSummary = computed(() => {
  const qualification = props.application.qualification_type
  const locality = props.application.is_foreign ? 'Foreign' : 'Local'

  if (!qualification?.level_label && !qualification?.name) {
    return `Qualification details unavailable • ${locality}`
  }

  return `${qualification?.level_label ?? 'Qualification'}${qualification?.name ? ` — ${qualification.name}` : ''} • ${locality}`
})

const applicationStatus = computed(() => ({
  label: props.application.status_label ?? formatLabel(props.application.current_status),
  tone: toneForStatus(`${props.application.current_status ?? ''} ${props.application.status_label ?? ''}`),
}))

const latestPaymentEvent = computed(() => decoratedTimeline.value.find((event) => normalize(event.event_code).startsWith('payment.')) ?? null)

const paymentStatus = computed(() => {
  const event = latestPaymentEvent.value

  if (!event) {
    return {
      label: 'No payment event',
      meta: 'No payment activity recorded yet.',
      tone: 'neutral' as ToneKey,
    }
  }

  return {
    label: event.title ?? 'Payment activity recorded',
    meta: event.formattedAt,
    tone: toneForStatus(`${event.title ?? ''} ${event.status_snapshot ?? ''}`),
  }
})

const latestActivity = computed(() => decoratedTimeline.value[0] ?? null)

const currentStep = computed(() => {
  const status = normalize(props.application.current_status)

  if (status === 'sent_back') {
    return {
      label: 'Sent back',
      meta: 'Awaiting applicant amendments.',
    }
  }

  if (['approved', 'rejected'].includes(status) || decoratedTimeline.value.some((event) => {
    const text = normalize(event.title)
    return text.includes('certificate issued') || text.includes('approved') || text.includes('rejected')
  })) {
    return {
      label: 'Outcome',
      meta: 'Decision or certificate stage reached.',
    }
  }

  if (decoratedTimeline.value.some((event) => {
    const text = `${normalize(event.event_code)} ${normalize(event.title)}`
    return text.includes('review') || text.includes('assigned') || text.includes('verified')
  })) {
    return {
      label: 'Verification',
      meta: 'Application is in the review journey.',
    }
  }

  if (['submitted', 'resubmitted'].includes(status) || decoratedTimeline.value.some((event) => normalize(event.title).includes('application submitted'))) {
    return {
      label: 'Submitted',
      meta: 'Application is with ZAQA for processing.',
    }
  }

  if (latestPaymentEvent.value) {
    return {
      label: 'Payment',
      meta: 'Billing and payment steps are in progress.',
    }
  }

  return {
    label: 'Draft',
    meta: 'Application preparation is still in progress.',
  }
})

const summaryCards = computed(() => [
  {
    key: 'application-status',
    label: 'Current status',
    value: applicationStatus.value.label,
    meta: props.application.current_status ? `Lifecycle state: ${formatLabel(props.application.current_status)}` : 'Application lifecycle state',
    icon: ShieldCheck,
    tone: applicationStatus.value.tone,
  },
  {
    key: 'payment-status',
    label: 'Payment status',
    value: paymentStatus.value.label,
    meta: paymentStatus.value.meta,
    icon: CreditCard,
    tone: paymentStatus.value.tone,
  },
  {
    key: 'current-step',
    label: 'Current step',
    value: currentStep.value.label,
    meta: currentStep.value.meta,
    icon: GitBranch,
    tone: 'info' as ToneKey,
  },
  {
    key: 'last-activity',
    label: 'Last activity',
    value: latestActivity.value?.title ?? 'No recent activity',
    meta: latestActivity.value?.relativeAt ?? latestActivity.value?.formattedAt ?? 'Waiting for the first event.',
    icon: CalendarClock,
    tone: latestActivity.value?.presentation.tone ?? 'neutral',
  },
])
</script>

<template>
  <AdminLayout>
    <div class="w-full min-w-0 max-w-none space-y-6">
      <section class="overflow-hidden rounded-3xl border border-border/70 bg-surface shadow-[0_24px_60px_-36px_rgba(15,23,42,0.32)]">
        <div class="bg-gradient-to-r from-brand/[0.08] via-surface to-emerald-500/[0.05] px-6 py-6 sm:px-7">
          <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0">
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Finance / Applications / Tracking</div>
              <h1 class="mt-3 break-words text-3xl font-semibold tracking-tight text-text-primary">
                {{ application.application_number }}
              </h1>
              <p class="mt-2 text-sm leading-relaxed text-text-muted">
                {{ qualificationSummary }}
              </p>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold"
                  :class="badgeClass(applicationStatus.tone)"
                >
                  Status: {{ applicationStatus.label }}
                </span>
              </div>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2">
              <Link href="/admin/finance/payment-proofs" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
                Payment proofs
              </Link>
            </div>
          </div>
        </div>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article
          v-for="card in summaryCards"
          :key="card.key"
          class="min-w-0 rounded-2xl border border-border/70 bg-surface px-5 py-4 shadow-[0_18px_45px_-32px_rgba(15,23,42,0.32)]"
        >
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">{{ card.label }}</div>
              <div class="mt-3 break-words text-base font-semibold leading-snug text-text-primary">{{ card.value }}</div>
              <div class="mt-1 text-xs leading-relaxed text-text-muted">{{ card.meta }}</div>
            </div>
            <span
              class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border"
              :class="timelineIconClass(card.tone)"
            >
              <component :is="card.icon" class="h-5 w-5" aria-hidden="true" />
            </span>
          </div>
        </article>
      </section>

      <section class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_340px]">
        <div class="min-w-0 rounded-3xl border border-border/70 bg-surface shadow-[0_24px_60px_-36px_rgba(15,23,42,0.32)]">
          <div class="border-b border-border/60 bg-gradient-to-r from-surface-muted/75 via-surface to-surface px-6 py-5 sm:px-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <div class="text-sm font-semibold text-text-primary">Application journey</div>
                <p class="mt-1 text-sm text-text-muted">
                  Key lifecycle milestones are shown first, with the newest updates at the top.
                </p>
              </div>
              <span class="inline-flex items-center rounded-full border border-border/70 bg-surface px-3 py-1 text-xs font-semibold text-text-primary">
                {{ primaryTimelineBase.length }} key events
              </span>
            </div>
          </div>

          <div class="px-6 py-6 sm:px-7">
            <div
              v-if="primaryTimelineBase.length === 0"
              class="rounded-2xl border border-dashed border-border bg-surface-muted/35 px-5 py-10 text-center text-sm text-text-muted"
            >
              No tracking events yet.
            </div>

            <template v-else>
              <ol class="space-y-0" aria-label="Application journey timeline">
                <li
                  v-for="(event, index) in visiblePrimaryTimeline"
                  :key="event.id"
                  class="grid grid-cols-[2.75rem_minmax(0,1fr)] gap-4 pb-6 last:pb-0"
                >
                  <div class="relative flex justify-center">
                    <div
                      v-if="index !== visiblePrimaryTimeline.length - 1"
                      class="absolute left-1/2 top-11 w-px -translate-x-1/2 bg-border/80"
                      style="bottom: -1.5rem;"
                      aria-hidden="true"
                    />
                    <span
                      class="relative z-10 flex h-10 w-10 items-center justify-center rounded-2xl border"
                      :class="timelineIconClass(event.presentation.tone)"
                    >
                      <component :is="event.presentation.icon" class="h-4 w-4" aria-hidden="true" />
                    </span>
                  </div>

                  <article class="min-w-0 border-b border-border/60 pb-6 last:border-b-0 last:pb-0">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                      <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                          <h2
                            class="break-words font-semibold text-text-primary"
                            :class="event.presentation.importance === 'high' ? 'text-base' : 'text-sm'"
                          >
                            {{ event.title || 'Lifecycle event' }}
                          </h2>
                          <span
                            v-if="event.statusLabel"
                            class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                            :class="badgeClass(event.presentation.tone)"
                          >
                            {{ event.statusLabel }}
                          </span>
                          <span
                            v-if="event.visibilityLabel"
                            class="inline-flex items-center rounded-full border border-border/70 bg-surface px-2.5 py-0.5 text-[11px] font-semibold text-text-muted"
                          >
                            {{ event.visibilityLabel }}
                          </span>
                        </div>

                        <p
                          v-if="event.description"
                          class="mt-2 break-words leading-relaxed text-text-muted"
                          :class="event.presentation.importance === 'high' ? 'text-sm' : 'text-xs'"
                        >
                          {{ event.description }}
                        </p>
                      </div>

                      <div class="shrink-0 text-left sm:text-right">
                        <time
                          :datetime="event.occurred_at ?? undefined"
                          class="block text-xs font-semibold text-text-primary"
                        >
                          {{ event.formattedAt }}
                        </time>
                        <div v-if="event.relativeAt" class="mt-1 text-[11px] text-text-muted">{{ event.relativeAt }}</div>
                      </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-text-muted">
                      <span class="inline-flex items-center gap-1.5">
                        <User class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ event.actorLabel }}
                      </span>
                      <span
                        v-if="event.presentation.importance === 'high'"
                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted"
                      >
                        <Sparkles class="h-3.5 w-3.5" aria-hidden="true" />
                        Key milestone
                      </span>
                    </div>

                    <details v-if="event.comment" class="mt-3">
                      <summary class="cursor-pointer text-xs font-semibold text-brand">
                        View note
                      </summary>
                      <div class="mt-2 rounded-2xl border border-border/70 bg-surface-muted/35 px-3 py-3 text-xs leading-relaxed text-text-primary">
                        {{ event.comment }}
                      </div>
                    </details>
                  </article>
                </li>
              </ol>

              <div v-if="remainingPrimaryCount > 0" class="mt-6">
                <button
                  type="button"
                  class="inline-flex items-center gap-2 rounded-full border border-border/70 bg-surface px-4 py-2 text-sm font-semibold text-text-primary transition hover:border-border hover:bg-surface-muted"
                  @click="showAllPrimaryEvents = !showAllPrimaryEvents"
                >
                  <ChevronDown class="h-4 w-4 transition" :class="showAllPrimaryEvents ? 'rotate-180' : ''" aria-hidden="true" />
                  {{ showAllPrimaryEvents ? 'Show fewer events' : `Show ${remainingPrimaryCount} more events` }}
                </button>
              </div>

              <div
                v-if="minorTimeline.length > 0"
                class="mt-6 rounded-2xl border border-dashed border-border/80 bg-surface-muted/30 px-4 py-4 sm:px-5"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Additional updates</div>
                    <p class="mt-1 text-xs text-text-muted">
                      Lower-importance edits and saved steps are grouped here to keep the main journey easy to scan.
                    </p>
                  </div>
                  <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full border border-border/70 bg-surface px-3 py-1.5 text-xs font-semibold text-text-primary transition hover:border-border hover:bg-surface-muted"
                    @click="showMinorEvents = !showMinorEvents"
                  >
                    <ChevronDown class="h-3.5 w-3.5 transition" :class="showMinorEvents ? 'rotate-180' : ''" aria-hidden="true" />
                    {{ showMinorEvents ? 'Hide updates' : `Show ${minorTimeline.length} updates` }}
                  </button>
                </div>

                <ol v-if="showMinorEvents" class="mt-4 divide-y divide-border/60">
                  <li
                    v-for="event in minorTimeline"
                    :key="`minor-${event.id}`"
                    class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                  >
                    <div class="min-w-0">
                      <div class="text-sm font-medium text-text-primary">{{ event.title || 'Update recorded' }}</div>
                      <div v-if="event.description" class="mt-1 text-xs leading-relaxed text-text-muted">{{ event.description }}</div>
                      <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-text-muted">
                        <span class="inline-flex items-center gap-1.5">
                          <User class="h-3.5 w-3.5" aria-hidden="true" />
                          {{ event.actorLabel }}
                        </span>
                        <span v-if="event.visibilityLabel">{{ event.visibilityLabel }}</span>
                      </div>
                    </div>
                    <div class="shrink-0 text-[11px] text-text-muted">
                      {{ event.formattedAt }}
                    </div>
                  </li>
                </ol>
              </div>
            </template>
          </div>
        </div>

        <aside class="space-y-4">
          <section class="rounded-3xl border border-border/70 bg-surface shadow-[0_24px_60px_-36px_rgba(15,23,42,0.32)]">
            <div class="border-b border-border/60 px-5 py-5">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">Status history</div>
                  <p class="mt-1 text-xs text-text-muted">Recorded application status transitions.</p>
                </div>
                <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/45 px-2.5 py-1 text-[11px] font-semibold text-text-primary">
                  {{ decoratedStatusHistories.length }}
                </span>
              </div>
            </div>

            <div class="px-5 py-5">
              <div
                v-if="decoratedStatusHistories.length === 0"
                class="rounded-2xl border border-dashed border-border bg-surface-muted/35 px-4 py-8 text-center text-sm text-text-muted"
              >
                No status transitions recorded.
              </div>

              <ol v-else class="divide-y divide-border/60">
                <li
                  v-for="history in decoratedStatusHistories"
                  :key="history.id"
                  class="py-4 first:pt-0 last:pb-0"
                >
                  <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm font-semibold text-text-primary">
                      <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/35 px-2.5 py-1 text-[11px] font-semibold text-text-primary">
                        {{ history.fromLabel }}
                      </span>
                      <span class="text-text-muted">to</span>
                      <span
                        class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                        :class="badgeClass(history.tone)"
                      >
                        {{ history.toLabel }}
                      </span>
                    </div>

                    <div class="text-xs text-text-muted">
                      <time :datetime="history.changed_at ?? undefined" class="font-medium text-text-primary">
                        {{ history.formattedAt }}
                      </time>
                      <span v-if="history.relativeAt" class="ml-1 text-text-muted">· {{ history.relativeAt }}</span>
                    </div>

                    <div
                      v-if="history.actor_name"
                      class="inline-flex items-center gap-1.5 text-[11px] text-text-muted"
                    >
                      <User class="h-3.5 w-3.5" aria-hidden="true" />
                      {{ history.actor_name }}
                    </div>

                    <p v-if="history.comment" class="text-xs leading-relaxed text-text-muted">
                      {{ history.comment }}
                    </p>
                  </div>
                </li>
              </ol>
            </div>
          </section>
        </aside>
      </section>
    </div>
  </AdminLayout>
</template>
