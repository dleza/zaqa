<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
  ArrowDown,
  BadgeCheck,
  CalendarClock,
  CheckCircle2,
  CircleAlert,
  Clock,
  CreditCard,
  FileEdit,
  RefreshCcw,
  Send,
  ShieldCheck,
  Sparkles,
} from 'lucide-vue-next'

const props = defineProps<{
  application: any
  events: Array<any>
  statusHistoryFallback: Array<any>
}>()

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}

const stageSteps = computed(() => {
  const s = (props.application.current_status ?? '').toString()
  const steps = [
    { key: 'draft', label: 'Draft', done: s !== 'draft', icon: FileEdit },
    {
      key: 'payment',
      label: 'Payment',
      done: ['submitted', 'resubmitted', 'sent_back', 'approved', 'rejected'].includes(s),
      icon: CreditCard,
    },
    {
      key: 'submitted',
      label: 'Submitted',
      done: ['submitted', 'resubmitted', 'sent_back', 'approved', 'rejected'].includes(s),
      icon: Send,
    },
    { key: 'review', label: 'Under review', done: ['approved', 'rejected'].includes(s), icon: ShieldCheck },
    { key: 'decision', label: 'Decision', done: ['approved', 'rejected'].includes(s), icon: BadgeCheck },
  ]
  return steps
})

function statusBadgeClass(status: string) {
  const s = (status ?? '').toString()
  if (s === 'draft') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'sent_back') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'submitted' || s === 'resubmitted') return 'zaqa-badge zaqa-badge-info'
  if (s === 'approved') return 'zaqa-badge zaqa-badge-success'
  if (s === 'rejected') return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}

const displayStatusLabel = computed(
  () => props.application?.display_status_label ?? props.application?.status_label ?? '—',
)

const correctionRequired = computed(() => props.application?.correction_required === true)

function eventDisplayTitle(ev: any): string {
  const code = (ev?.event_code ?? '').toString()
  const title = (ev?.title ?? '').toString()
  const qualTitle = (ev?.qualification_title ?? '').toString().trim()
  if (code.includes('qualification_sent_back') && qualTitle) {
    return `Correction requested for ${qualTitle}`
  }
  return title || 'Update'
}

function eventIcon(code: string | undefined) {
  const c = (code ?? '').toString()
  if (c.startsWith('draft.')) return FileEdit
  if (c.startsWith('wizard.')) return RefreshCcw
  if (c.startsWith('payment.')) return CreditCard
  if (c.startsWith('submission.') || c.includes('submit')) return Send
  if (c.startsWith('status.')) return Clock
  return Clock
}

const timeline = computed(() => {
  if ((props.events ?? []).length > 0) return props.events
  return (props.statusHistoryFallback ?? []).map((h) => ({
    id: `status-${h.id}`,
    title: h.from_status ? `${h.from_status} → ${h.to_status}` : `Status: ${h.to_status}`,
    description: h.comment ?? null,
    occurred_at: h.changed_at,
    event_code: 'status.fallback',
    comment: null as string | null,
  }))
})

/** Newest-first from API — first item is always the latest activity. */
const latestActivity = computed(() => timeline.value[0] ?? null)

/** Earlier entries (excludes the latest so we don’t duplicate). */
const earlierActivities = computed(() => timeline.value.slice(1))

function formatDateTime(iso: string | undefined) {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d)
  } catch {
    return iso
  }
}

function relativeLabel(iso: string | undefined): string | null {
  if (!iso) return null
  const d = new Date(iso).getTime()
  if (Number.isNaN(d)) return null
  const diffMs = Date.now() - d
  const sec = Math.abs(Math.round(diffMs / 1000))
  const past = diffMs >= 0
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

  const minute = 60
  const hour = minute * 60
  const day = hour * 24
  const week = day * 7

  if (sec < minute) return past ? 'Just now' : 'Soon'
  if (sec < hour) return rtf.format(past ? -Math.round(sec / minute) : Math.round(sec / minute), 'minute')
  if (sec < day) return rtf.format(past ? -Math.round(sec / hour) : Math.round(sec / hour), 'hour')
  if (sec < week * 4) return rtf.format(past ? -Math.round(sec / day) : Math.round(sec / day), 'day')
  if (sec < day * 365) return rtf.format(past ? -Math.round(sec / week) : Math.round(sec / week), 'week')
  return rtf.format(past ? -Math.round(sec / (day * 30)) : Math.round(sec / (day * 30)), 'month')
}
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell relative">
      <!-- subtle ambient -->
      <div
        class="pointer-events-none absolute inset-x-0 -top-8 h-48 bg-gradient-to-b from-brand/[0.07] via-transparent to-transparent"
        aria-hidden="true"
      />

      <div class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Track application</div>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-semibold tracking-tight text-text-primary">{{ application.application_number }}</h1>
            <span :class="correctionRequired ? 'zaqa-badge zaqa-badge-warning' : statusBadgeClass(application.current_status)">
              {{ displayStatusLabel }}
            </span>
          </div>
          <div class="mt-1 text-sm text-text-muted">
            {{ application.is_foreign ? 'Foreign' : 'Local' }} • Submitted: {{ application.submitted_at ?? '—' }}
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Link :href="`/applicant/applications/${application.id}`" class="zaqa-btn zaqa-btn-secondary">View details</Link>
          <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-ghost">All applications</Link>
        </div>
      </div>

      <!-- Progress tracker -->
      <div class="relative mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]">
        <div class="border-b border-border bg-gradient-to-r from-surface-muted to-surface px-5 py-4">
          <div class="text-sm font-semibold text-text-primary">Where you are in the process</div>
          <div class="mt-1 text-xs text-text-muted">Steps advance as your application moves forward.</div>
        </div>
        <div class="px-5 py-5">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-5">
            <div v-for="step in stageSteps" :key="step.key" class="rounded-2xl border border-border bg-surface-muted/80 px-4 py-4 ring-1 ring-black/[0.03]">
              <div class="flex items-center justify-between">
                <component :is="step.icon" class="h-4 w-4" :class="step.done ? 'text-success' : 'text-text-muted'" aria-hidden="true" />
                <component
                  :is="step.done ? CheckCircle2 : CircleAlert"
                  class="h-4 w-4"
                  :class="step.done ? 'text-success' : 'text-warning'"
                  aria-hidden="true"
                />
              </div>
              <div class="mt-3 text-sm font-semibold text-text-primary">{{ step.label }}</div>
              <div class="mt-1 text-xs" :class="step.done ? 'text-emerald-700' : 'text-text-muted'">
                {{ step.done ? 'Completed' : 'Pending' }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="relative mt-8 grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8">
        <!-- Activity timeline -->
        <div class="lg:col-span-2">
          <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-[0_24px_48px_-24px_rgba(11,58,102,0.18)] ring-1 ring-black/[0.05]">
            <div class="border-b border-border bg-gradient-to-r from-brand/[0.06] via-surface-muted to-surface-muted px-6 py-5">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div class="text-base font-semibold tracking-tight text-text-primary">Application activity</div>
                  <p class="mt-1 max-w-xl text-sm leading-relaxed text-text-muted">
                    Updates appear <span class="font-semibold text-text-primary">newest first</span>. The highlighted card is your
                    <span class="font-semibold text-brand">most recent</span> activity; scroll down for older milestones — the last entry is the
                    earliest recorded step.
                  </p>
                </div>
                <div
                  class="hidden shrink-0 items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-text-muted sm:flex"
                >
                  <CalendarClock class="h-3.5 w-3.5" aria-hidden="true" />
                  Newest → oldest
                </div>
              </div>
            </div>

            <div class="px-5 py-6 sm:px-8 sm:py-8">
              <div v-if="timeline.length === 0" class="rounded-2xl border border-dashed border-border bg-surface-muted/50 px-6 py-10 text-center">
                <Clock class="mx-auto h-10 w-10 text-text-muted opacity-60" aria-hidden="true" />
                <p class="mt-4 text-sm font-semibold text-text-primary">No activity recorded yet</p>
                <p class="mt-2 text-sm text-text-muted">Your timeline will appear here as your application moves through verification.</p>
              </div>

              <template v-else>
                <!-- Latest (newest) — always first in API order -->
                <div v-if="latestActivity" class="relative">
                  <div class="flex justify-end sm:pr-2">
                    <span
                      class="inline-flex items-center gap-1.5 rounded-full border border-brand/25 bg-brand/[0.08] px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-brand"
                    >
                      <Sparkles class="h-3.5 w-3.5" aria-hidden="true" />
                      Latest activity
                    </span>
                  </div>

                  <div
                    class="mt-3 rounded-2xl border border-brand/25 bg-gradient-to-br from-brand/[0.09] via-surface to-surface p-[1px] shadow-lg shadow-brand/[0.08]"
                  >
                    <div class="rounded-[15px] bg-surface px-5 py-5 sm:px-6 sm:py-6">
                      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 flex-1 gap-4">
                          <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand/20 bg-brand/[0.07] text-brand shadow-inner"
                          >
                            <component :is="eventIcon(latestActivity.event_code)" class="h-5 w-5" aria-hidden="true" />
                          </div>
                          <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold leading-snug text-text-primary">{{ eventDisplayTitle(latestActivity) }}</h3>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
                              <time :datetime="latestActivity.occurred_at ?? undefined" class="font-medium text-text-primary">
                                {{ formatDateTime(latestActivity.occurred_at) }}
                              </time>
                              <span v-if="relativeLabel(latestActivity.occurred_at)" class="text-text-muted">
                                · {{ relativeLabel(latestActivity.occurred_at) }}
                              </span>
                            </div>
                            <p v-if="latestActivity.description" class="mt-3 text-sm leading-relaxed text-text-muted">
                              {{ latestActivity.description }}
                            </p>
                            <div
                              v-if="latestActivity.comment"
                              class="mt-4 rounded-xl border border-brand/15 bg-brand/[0.04] px-4 py-3 text-sm leading-relaxed text-text-primary"
                            >
                              {{ latestActivity.comment }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div v-if="earlierActivities.length > 0" class="mt-6 flex items-center justify-center gap-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
                    <ArrowDown class="h-4 w-4 text-brand/60" aria-hidden="true" />
                    Earlier activity below
                    <ArrowDown class="h-4 w-4 text-brand/60" aria-hidden="true" />
                  </div>
                </div>

                <!-- Earlier milestones — vertical rail -->
                <div v-if="earlierActivities.length > 0" class="relative mt-8">
                  <div class="absolute bottom-6 left-[18px] top-0 w-px bg-gradient-to-b from-brand/35 via-border to-border/80" aria-hidden="true" />

                  <ol class="relative space-y-0">
                    <li v-for="(ev, idx) in earlierActivities" :key="ev.id" class="relative pb-10 pl-12 last:pb-0">
                      <!-- node -->
                      <div
                        class="absolute left-3 top-2 flex h-4 w-4 items-center justify-center rounded-full border-2 bg-surface shadow-sm"
                        :class="idx === earlierActivities.length - 1 ? 'border-emerald-500/80 ring-2 ring-emerald-500/15' : 'border-brand/40'"
                      />

                      <div
                        class="rounded-2xl border border-border bg-surface-muted/60 px-4 py-4 shadow-sm ring-1 ring-black/[0.03] transition hover:border-border hover:bg-surface-muted"
                      >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                          <div class="flex min-w-0 flex-1 gap-3">
                            <div class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border bg-surface">
                              <component :is="eventIcon(ev.event_code)" class="h-4 w-4 text-brand" aria-hidden="true" />
                            </div>
                            <div class="min-w-0">
                              <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-text-primary">{{ eventDisplayTitle(ev) }}</span>
                                <span
                                  v-if="idx === earlierActivities.length - 1"
                                  class="inline-flex rounded-full border border-emerald-500/25 bg-emerald-500/[0.08] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800"
                                >
                                  Earliest
                                </span>
                              </div>
                              <div class="mt-1 flex flex-wrap items-center gap-x-2 text-[11px] text-text-muted">
                                <time :datetime="ev.occurred_at ?? undefined">{{ formatDateTime(ev.occurred_at) }}</time>
                                <span v-if="relativeLabel(ev.occurred_at)">· {{ relativeLabel(ev.occurred_at) }}</span>
                              </div>
                              <p v-if="ev.description" class="mt-2 text-xs leading-relaxed text-text-muted">{{ ev.description }}</p>
                              <div
                                v-if="ev.comment"
                                class="mt-3 rounded-xl border border-border bg-surface px-3 py-2 text-xs text-text-primary"
                              >
                                {{ ev.comment }}
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </li>
                  </ol>

                  <p class="mt-4 text-center text-[11px] text-text-muted">
                    End of timeline — older items are closer to when your application began.
                  </p>
                </div>

                <!-- Single event only -->
                <p v-if="timeline.length === 1" class="mt-6 text-center text-sm text-text-muted">
                  Only one activity has been recorded so far; more updates will appear here as your application progresses.
                </p>
              </template>
            </div>
          </div>
        </div>

        <!-- Summary -->
        <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/[0.04]">
            <div class="text-sm font-semibold text-text-primary">Key dates</div>
            <div class="mt-3 grid grid-cols-1 gap-3 text-sm">
              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Created</div>
                <div class="mt-1 font-semibold text-text-primary">{{ application.created_at ?? '—' }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Submitted</div>
                <div class="mt-1 font-semibold text-text-primary">{{ application.submitted_at ?? '—' }}</div>
              </div>
              <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">SLA deadline</div>
                <div class="mt-1 font-semibold text-text-primary">{{ application.service_deadline_at ?? '—' }}</div>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/[0.04]">
            <div class="text-sm font-semibold text-text-primary">Payment</div>
            <div class="mt-3 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm">
              <div class="text-xs text-text-muted">Invoice</div>
              <div class="mt-1 font-semibold text-text-primary">{{ application.invoice?.invoice_number ?? '—' }}</div>
              <div class="mt-2 text-xs text-text-muted">Amount</div>
              <div class="mt-1 font-semibold text-text-primary">
                {{ application.invoice ? money(application.invoice.amount_cents, application.invoice.currency) : '—' }}
              </div>
              <div class="mt-2 text-xs text-text-muted">Payment status</div>
              <div class="mt-1 font-semibold text-text-primary">{{ application.payment?.status ?? '—' }}</div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </ApplicantLayout>
</template>
