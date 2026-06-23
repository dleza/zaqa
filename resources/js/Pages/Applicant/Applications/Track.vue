<script setup lang="ts">
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import {
  buildActionRequired,
  buildProgressSteps,
  buildTimeline,
  formatTrackingDate,
  formatTrackingDateTime,
  isMilestoneTimelineEvent,
  isTechnicalTimelineEvent,
  milestoneDisplayTitle,
  paymentStatusLabel,
  resolveTrackingStatus,
  type TimelineEvent,
} from '@/lib/applicantApplicationTracking'
import { formatMoneyFromCents } from '@/utils/money'
import { Link, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { ArrowLeft, Check, ChevronRight } from 'lucide-vue-next'

const props = defineProps<{
  application: any
  events: Array<TimelineEvent>
  statusHistoryFallback: Array<any>
  selectedQualification?: {
    id: number
    title_of_qualification: string | null
    verification_reference_number: string | null
    status_label: string
  } | null
}>()

const page = usePage()
const showFullHistory = ref(false)

const selectedQualificationId = computed(() => {
  const fromProps = Number(props.selectedQualification?.id ?? 0)
  if (fromProps > 0) return fromProps
  const raw = new URL(page.url, 'http://localhost').searchParams.get('qualification')
  const parsed = Number(raw ?? 0)
  return parsed > 0 ? parsed : null
})

const timeline = computed(() =>
  buildTimeline(props.events ?? [], props.statusHistoryFallback ?? [], selectedQualificationId.value),
)

const milestoneTimeline = computed(() =>
  timeline.value.filter((event) => isMilestoneTimelineEvent(event.event_code)),
)

const visibleTimeline = computed(() => (showFullHistory.value ? timeline.value : milestoneTimeline.value))

const tracking = computed(() =>
  resolveTrackingStatus(props.application, props.selectedQualification ?? null, timeline.value),
)

const progressSteps = computed(() => buildProgressSteps(tracking.value.key, props.application))

const actionRequired = computed(() =>
  buildActionRequired(tracking.value.key, props.application.id, props.selectedQualification ?? null),
)

const actionToneClass = computed(() => {
  switch (actionRequired.value.tone) {
    case 'warning':
      return 'border-amber-200 bg-amber-50'
    case 'success':
      return 'border-emerald-200 bg-emerald-50'
    case 'danger':
      return 'border-rose-200 bg-rose-50'
    default:
      return 'border-border bg-surface-muted/50'
  }
})

function stepCircleClass(state: 'complete' | 'current' | 'upcoming') {
  if (state === 'complete') return 'border-emerald-500 bg-emerald-500 text-white'
  if (state === 'current') return 'border-brand bg-brand text-white shadow-md shadow-brand/25'
  return 'border-border bg-surface text-text-muted'
}

function stepLabelClass(state: 'complete' | 'current' | 'upcoming') {
  if (state === 'complete') return 'text-emerald-700'
  if (state === 'current') return 'text-brand'
  return 'text-text-muted'
}

function stepConnectorClass(state: 'complete' | 'current' | 'upcoming') {
  return state === 'upcoming' ? 'bg-border' : 'bg-emerald-400'
}
</script>

<template>
  <ApplicantLayout>
    <div class="zaqa-wizard-shell mx-auto w-full min-w-0 max-w-7xl pb-8 2xl:max-w-[1440px]">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Link
          :href="`/applicant/applications/${application.id}`"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          Back to application
        </Link>
        <Link href="/applicant/applications" class="text-sm font-medium text-text-muted transition hover:text-brand">
          All applications
        </Link>
      </div>

      <!-- Hero status -->
      <section class="mt-4 w-full overflow-hidden rounded-2xl bg-gradient-to-br from-brand via-[#0a4f86] to-[#083a66] px-4 py-5 text-white shadow-lg shadow-brand/20 sm:px-6 sm:py-5">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
          <div class="min-w-0 flex-1">
            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/70">Application tracking</div>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              <div class="min-w-0">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Application reference</div>
                <div class="mt-1 break-all font-mono text-sm font-semibold sm:text-base">{{ application.application_number }}</div>
              </div>
              <div v-if="selectedQualification?.verification_reference_number" class="min-w-0">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Qualification reference</div>
                <div class="mt-1 break-all font-mono text-sm font-semibold sm:text-base">
                  {{ selectedQualification.verification_reference_number }}
                </div>
              </div>
              <div v-if="selectedQualification?.title_of_qualification" class="min-w-0 sm:col-span-2 xl:col-span-1">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Qualification</div>
                <div class="mt-1 text-sm font-medium leading-snug sm:text-base">
                  {{ selectedQualification.title_of_qualification }}
                </div>
              </div>
            </div>
          </div>

          <div class="w-full shrink-0 border-t border-white/15 pt-4 xl:w-auto xl:min-w-[16rem] xl:border-t-0 xl:pt-0 xl:text-right 2xl:min-w-[18rem]">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Current status</div>
            <div class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">{{ tracking.headline }}</div>
          </div>
        </div>

        <div class="mt-5 grid gap-4 border-t border-white/15 pt-4 sm:grid-cols-2 xl:grid-cols-2">
          <div class="min-w-0">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Current stage</div>
            <div class="mt-1 text-sm font-semibold">{{ tracking.stage }}</div>
          </div>
          <div class="min-w-0">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Next step</div>
            <div class="mt-1 text-sm font-semibold">{{ tracking.nextStep }}</div>
          </div>
        </div>
      </section>

      <!-- Progress tracker -->
      <section class="mt-4 w-full rounded-2xl border border-border/70 bg-surface px-3 py-4 sm:px-5">
        <div class="-mx-1 overflow-x-auto px-1 sm:mx-0 sm:overflow-visible">
          <div class="flex min-w-[36rem] items-center sm:min-w-0 sm:w-full">
            <template v-for="(step, index) in progressSteps" :key="step.key">
              <div class="flex min-w-[4.5rem] flex-1 flex-col items-center px-1 text-center sm:min-w-0 sm:px-2">
                <div
                  class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold sm:h-9 sm:w-9"
                  :class="stepCircleClass(step.state)"
                >
                  <Check v-if="step.state === 'complete'" class="h-4 w-4" aria-hidden="true" />
                  <span v-else>{{ index + 1 }}</span>
                </div>
                <div
                  class="mt-2 max-w-[5.5rem] text-[10px] font-semibold leading-tight sm:max-w-none sm:text-xs"
                  :class="stepLabelClass(step.state)"
                >
                  {{ step.label }}
                </div>
              </div>
              <div
                v-if="index < progressSteps.length - 1"
                class="mb-6 h-0.5 min-w-[1.25rem] flex-1 shrink-0"
                :class="stepConnectorClass(step.state)"
                aria-hidden="true"
              />
            </template>
          </div>
        </div>
      </section>

      <!-- Summary chips -->
      <section class="mt-4 grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 sm:px-4">
          <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Submitted</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">{{ formatTrackingDate(application.submitted_at) }}</div>
        </div>
        <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 sm:px-4">
          <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Target completion</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">
            {{ formatTrackingDate(application.service_deadline_at) }}
          </div>
        </div>
        <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 sm:px-4">
          <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Payment</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">
            {{ paymentStatusLabel(application.payment?.status) }}
          </div>
        </div>
        <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 sm:px-4">
          <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Amount</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">
            {{
              application.invoice
                ? formatMoneyFromCents(application.invoice.amount_cents, application.invoice.currency)
                : '—'
            }}
          </div>
        </div>
      </section>

      <!-- Action + what next -->
      <section class="mt-4 grid w-full grid-cols-1 gap-3 md:grid-cols-2">
        <div class="rounded-2xl border p-4 sm:p-5" :class="actionToneClass">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Action required</div>
          <div class="mt-2 text-lg font-semibold text-text-primary">{{ actionRequired.title }}</div>
          <p class="mt-2 text-sm leading-relaxed text-text-muted">{{ actionRequired.body }}</p>
          <Link
            v-if="actionRequired.ctaHref"
            :href="actionRequired.ctaHref"
            class="zaqa-btn zaqa-btn-primary mt-4 inline-flex w-full items-center justify-center gap-2 px-4 py-2.5 text-sm sm:w-auto"
          >
            {{ actionRequired.ctaLabel }}
            <ChevronRight class="h-4 w-4" aria-hidden="true" />
          </Link>
        </div>

        <div class="rounded-2xl border border-border/70 bg-surface p-4 sm:p-5">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">What happens next?</div>
          <p class="mt-3 text-sm leading-relaxed text-text-primary">{{ tracking.whatNext }}</p>
        </div>
      </section>

      <!-- Timeline -->
      <section class="mt-4 w-full rounded-2xl border border-border/70 bg-surface">
        <div class="flex flex-col gap-3 border-b border-border/70 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div class="min-w-0">
            <h2 class="text-base font-semibold text-text-primary">Activity</h2>
            <p class="mt-1 text-sm text-text-muted">
              {{ showFullHistory ? 'Full activity history' : 'Key milestones only' }}
            </p>
          </div>
          <button
            type="button"
            class="zaqa-btn zaqa-btn-secondary w-full shrink-0 px-4 py-2.5 text-sm sm:w-auto"
            @click="showFullHistory = !showFullHistory"
          >
            {{ showFullHistory ? 'Show key milestones' : 'Show full activity history' }}
          </button>
        </div>

        <div class="px-4 py-4 sm:px-6">
          <div
            v-if="visibleTimeline.length === 0"
            class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
          >
            No activity recorded yet. Updates will appear here as your application progresses.
          </div>

          <ol v-else class="space-y-0">
            <li
              v-for="(event, index) in visibleTimeline"
              :key="event.id"
              class="relative flex gap-3 pb-5 last:pb-0"
            >
              <div class="flex flex-col items-center">
                <div
                  class="mt-1 h-2.5 w-2.5 rounded-full"
                  :class="index === 0 ? 'bg-brand ring-4 ring-brand/15' : 'bg-border'"
                />
                <div
                  v-if="index < visibleTimeline.length - 1"
                  class="mt-1 w-px flex-1 bg-border"
                  aria-hidden="true"
                />
              </div>
              <div class="min-w-0 flex-1 pb-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-sm font-semibold text-text-primary">
                    {{ milestoneDisplayTitle(event) }}
                  </span>
                  <span
                    v-if="isTechnicalTimelineEvent(event.event_code) && showFullHistory"
                    class="rounded-full bg-surface-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-text-muted"
                  >
                    System
                  </span>
                </div>
                <time :datetime="event.occurred_at ?? undefined" class="mt-1 block text-xs text-text-muted">
                  {{ formatTrackingDateTime(event.occurred_at) }}
                </time>
                <p v-if="event.description" class="mt-2 text-sm leading-relaxed text-text-muted">
                  {{ event.description }}
                </p>
                <p
                  v-if="event.comment"
                  class="mt-2 rounded-lg border border-border/70 bg-surface-muted/60 px-3 py-2 text-sm text-text-primary"
                >
                  {{ event.comment }}
                </p>
              </div>
            </li>
          </ol>
        </div>
      </section>
    </div>
  </ApplicantLayout>
</template>
