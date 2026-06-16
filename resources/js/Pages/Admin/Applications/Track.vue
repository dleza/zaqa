<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import {
  Activity,
  ArrowRight,
  BookOpen,
  CalendarClock,
  CheckCircle2,
  CircleDot,
  Clock,
  FileSearch,
  FileText,
  GitBranch,
  Search,
  Shield,
  User,
} from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  selected?: any | null
  statuses?: Array<any>
  activity_feed?: Array<{
    kind: string
    id: string
    at: string
    title: string
    body: string | null
    meta: string
  }>
  qualifications?: Array<{
    id: number
    verification_reference_number: string | null
    title_of_qualification: string | null
    names_as_on_qualification_document?: string | null
    verification_state: string | null
    verification_state_label: string
    assigned_verifier_name: string | null
    qualification_type_label: string | null
    awarding_label: string | null
    updated_at: string | null
    verification_url: string | null
  }>
  filters?: { application_id?: string | null }
  can?: { view_verification: boolean }
}>()

const query = ref('')
const loading = ref(false)
const suggestions = ref<Array<any>>([])
const open = ref(false)

let debounce: number | null = null
watch(
  () => query.value,
  () => {
    if (debounce) window.clearTimeout(debounce)
    const q = query.value.trim()
    if (q.length < 3) {
      suggestions.value = []
      open.value = false
      return
    }
    debounce = window.setTimeout(async () => {
      loading.value = true
      try {
        const res = await fetch(`/admin/applications/track/suggest?q=${encodeURIComponent(q)}`, {
          headers: { Accept: 'application/json' },
        })
        const json = await res.json()
        suggestions.value = Array.isArray(json?.data) ? json.data : []
        open.value = true
      } finally {
        loading.value = false
      }
    }, 250)
  },
)

function selectSuggestion(s: any) {
  open.value = false
  suggestions.value = []
  query.value = ''
  router.get('/admin/applications/track', { application_id: s.id }, { preserveScroll: true })
}

const viewHref = computed(() => {
  if (!props.selected) return null
  if (props.can?.view_verification) return `/admin/verification/applications/${props.selected.id}`
  return null
})

const statusBadgeClass = computed(() => {
  return (status: string | null | undefined) => {
    const s = (status ?? '').toString()
    if (['approved', 'certificate_ready', 'completed'].includes(s)) return 'zaqa-badge-success'
    if (['rejected', 'failed'].includes(s)) return 'zaqa-badge-danger'
    if (['submitted', 'resubmitted'].includes(s)) return 'zaqa-badge-warning'
    if (['in_progress', 'under_review'].includes(s)) return 'zaqa-badge-info'
    if (['sent_back', 'returned_to_applicant', 'pending_payment'].includes(s)) return 'zaqa-badge-warning'
    if (['draft'].includes(s)) return 'zaqa-badge-secondary'
    return 'zaqa-badge-secondary'
  }
})

function verificationQualBadgeClass(state: string | null | undefined) {
  const s = (state ?? '').toString()
  if (['approved_for_certificate', 'certificate_issued', 'closed'].includes(s)) return 'zaqa-badge-success'
  if (['rejected'].includes(s)) return 'zaqa-badge-danger'
  if (['returned_to_applicant'].includes(s)) return 'zaqa-badge-warning'
  if (['awaiting_assignment'].includes(s)) return 'zaqa-badge-secondary'
  return 'zaqa-badge-info'
}

function formatDateTime(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function formatDateOnly(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' })
  } catch {
    return iso
  }
}

function formatRelative(iso: string | null | undefined) {
  if (!iso) return '—'
  const t = new Date(iso).getTime()
  if (Number.isNaN(t)) return '—'
  const diff = Date.now() - t
  const sec = Math.round(Math.abs(diff) / 1000)
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  if (sec < 60) return rtf.format(-Math.round(diff / 1000), 'second')
  const min = Math.round(sec / 60)
  if (min < 60) return rtf.format(-Math.round(diff / 60000), 'minute')
  const hr = Math.round(min / 60)
  if (hr < 48) return rtf.format(-Math.round(diff / 3600000), 'hour')
  const day = Math.round(hr / 24)
  if (day < 30) return rtf.format(-Math.round(diff / 86400000), 'day')
  return formatDateTime(iso)
}

const feed = computed(() => (Array.isArray(props.activity_feed) ? props.activity_feed : []))
const quals = computed(() => (Array.isArray(props.qualifications) ? props.qualifications : []))

const latestPulse = computed(() => feed.value[0] ?? null)

const journeySteps = computed(() => {
  const sel = props.selected
  if (!sel) return []

  const status = (sel.current_status ?? '').toString()
  const v = (sel.verification_state ?? '').toString()

  const terminal =
    status === 'rejected' ||
    v === 'rejected' ||
    ['approved', 'certificate_ready', 'completed'].includes(status) ||
    ['certificate_issued', 'closed'].includes(v)

  const inVerification =
    status === 'in_progress' ||
    [
      'awaiting_assignment',
      'assigned_to_level1',
      'under_level1_review',
      'under_level2_review',
      'returned_to_applicant',
      'approved_for_certificate',
    ].includes(v)

  const labels = [
    { id: 'intake', label: 'Intake & payment' },
    { id: 'lodged', label: 'Lodged with ZAQA' },
    { id: 'verify', label: 'Verification' },
    { id: 'outcome', label: 'Outcome' },
  ] as const

  if (terminal) {
    return labels.map((step, idx) => ({
      id: step.id,
      label: step.label,
      done: true,
      current: idx === 3,
    }))
  }

  let focus = 0
  if (inVerification) {
    focus = 2
  } else if (['submitted', 'resubmitted', 'sent_back'].includes(status)) {
    focus = 1
  } else {
    focus = 0
  }

  return labels.map((step, idx) => ({
    id: step.id,
    label: step.label,
    done: idx < focus,
    current: idx === focus,
  }))
})

function activityIcon(kind: string) {
  return kind === 'status' ? Activity : GitBranch
}
</script>

<template>
  <AdminLayout>
    <div class="w-full min-w-0 max-w-none">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
            <FileText class="h-4 w-4" aria-hidden="true" />
            Applications
          </div>
          <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Application tracker</h1>
          <p class="mt-1 text-sm leading-relaxed text-text-muted">
            Search any application, then see qualifications, lifecycle milestones, and status changes in one place—with the latest updates surfaced first.
          </p>
        </div>
      </div>

      <div
        class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]"
      >
        <div class="border-b border-border bg-gradient-to-r from-brand/[0.06] via-transparent to-accent/[0.05] px-5 py-5 sm:px-6">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-10">
            <div class="shrink-0 lg:max-w-md">
              <div class="text-sm font-semibold text-text-primary">Find an application</div>
              <div class="mt-1 text-xs text-text-muted">
                Search by application number, NRC, or passport. Type at least three characters.
              </div>
            </div>

            <div class="relative min-w-0 w-full flex-1">
              <Search
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted"
                aria-hidden="true"
              />
              <input
                v-model="query"
                class="zaqa-input h-11 border-border/80 pl-9 shadow-inner shadow-black/[0.02]"
                placeholder="e.g. ZAQA-VER-1234, 111111/11/1, P1234567"
                autocomplete="off"
                @focus="open = suggestions.length > 0"
                @keydown.escape="open = false"
              />

              <div
                v-if="open"
                class="absolute z-20 mt-2 max-h-72 w-full overflow-auto rounded-xl border border-border bg-surface shadow-lg"
              >
                <div v-if="loading" class="px-4 py-3 text-sm text-text-muted">Searching…</div>
                <div v-else-if="suggestions.length === 0" class="px-4 py-3 text-sm text-text-muted">
                  No matches.
                </div>
                <button
                  v-for="s in suggestions"
                  :key="s.id"
                  type="button"
                  class="flex w-full items-start justify-between gap-3 border-t border-border/60 px-4 py-3 text-left text-sm transition first:border-t-0 hover:bg-surface-muted"
                  @click="selectSuggestion(s)"
                >
                  <div class="min-w-0">
                    <div class="font-semibold text-text-primary">{{ s.application_number }}</div>
                    <div class="mt-0.5 truncate text-xs text-text-muted">
                      {{ s.name ?? '—' }} • {{ s.nrc_passport ?? '—' }} • {{ s.qualification_title ?? '—' }}
                    </div>
                  </div>
                  <div class="shrink-0">
                    <span class="zaqa-badge" :class="statusBadgeClass(s.status)">{{ s.status }}</span>
                  </div>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="selected" class="mt-8 space-y-8">
        <!-- Hero summary -->
        <section
          class="relative overflow-hidden rounded-2xl border border-border bg-surface shadow-md ring-1 ring-brand/[0.12]"
        >
          <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(900px_420px_at_10%_-10%,color-mix(in_oklab,var(--color-brand)_22%,transparent),transparent_55%),radial-gradient(700px_360px_at_100%_0%,color-mix(in_oklab,var(--color-accent)_18%,transparent),transparent_50%)]"
            aria-hidden="true"
          />
          <div class="relative grid gap-6 p-6 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-start">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full border border-brand/20 bg-brand/10 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wider text-brand"
                >
                  <CircleDot class="h-3.5 w-3.5" aria-hidden="true" />
                  Application
                </span>
                <span v-if="selected.qualification_count" class="text-xs text-text-muted">
                  {{ selected.qualification_count }}
                  {{ selected.qualification_count === 1 ? 'qualification' : 'qualifications' }}
                </span>
              </div>
              <h2 class="mt-3 break-words text-2xl font-bold tracking-tight text-text-primary sm:text-3xl">
                {{ selected.application_number }}
              </h2>
              <p class="mt-1 text-sm text-text-muted">
                {{ selected.applicant_name ?? '—' }}
                <span v-if="selected.nrc_passport_number" class="text-text-muted"> · {{ selected.nrc_passport_number }} </span>
              </p>
              <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="zaqa-badge" :class="statusBadgeClass(selected.current_status)">
                  {{ selected.current_status_label ?? selected.current_status }}
                </span>
                <span
                  v-if="selected.verification_state"
                  class="zaqa-badge zaqa-badge-info"
                  :title="selected.verification_state_label"
                >
                  {{ selected.verification_state_label ?? selected.verification_state }}
                </span>
                <span
                  v-if="selected.submitted_at"
                  class="inline-flex items-center gap-1 text-xs text-text-muted"
                >
                  <CalendarClock class="h-3.5 w-3.5" aria-hidden="true" />
                  Submitted {{ formatDateOnly(selected.submitted_at) }}
                </span>
              </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row lg:flex-col">
              <Link
                v-if="viewHref"
                :href="viewHref"
                class="zaqa-btn zaqa-btn-primary inline-flex h-11 items-center justify-center gap-2 px-5 text-sm shadow-sm"
              >
                <Shield class="h-4 w-4 shrink-0" aria-hidden="true" />
                Verification workspace
              </Link>
              <div v-else class="rounded-xl border border-border bg-surface-muted px-4 py-3 text-xs text-text-muted">
                You do not have permission to open the verification workspace for this application.
              </div>
            </div>
          </div>
        </section>

        <!-- Pulse + stats -->
        <section class="grid gap-4 lg:grid-cols-4">
          <div
            class="rounded-2xl border border-border bg-surface p-5 shadow-sm lg:col-span-2 lg:row-span-1 ring-1 ring-black/[0.03]"
          >
            <div class="flex items-start gap-3">
              <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
                <Clock class="h-5 w-5" aria-hidden="true" />
              </div>
              <div class="min-w-0 flex-1">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Latest activity</div>
                <div v-if="latestPulse" class="mt-1">
                  <div class="font-semibold text-text-primary">{{ latestPulse.title }}</div>
                  <div class="mt-1 text-xs text-text-muted">{{ latestPulse.meta }}</div>
                  <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
                    <span class="font-medium text-brand">{{ formatRelative(latestPulse.at) }}</span>
                    <span>{{ formatDateTime(latestPulse.at) }}</span>
                  </div>
                </div>
                <div v-else class="mt-1 text-sm text-text-muted">No recorded activity yet.</div>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/[0.03]">
            <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Qualifications</div>
            <div class="mt-2 flex items-baseline gap-2">
              <span class="text-3xl font-bold tabular-nums text-text-primary">{{ quals.length }}</span>
              <span class="text-xs text-text-muted">linked items</span>
            </div>
            <div class="mt-3 text-xs text-text-muted">Each row below opens detailed verification for that item.</div>
          </div>

          <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/[0.03]">
            <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Record freshness</div>
            <div class="mt-2 text-sm font-semibold text-text-primary">
              {{ selected.last_activity_at ? formatRelative(selected.last_activity_at) : '—' }}
            </div>
            <div class="mt-1 text-xs text-text-muted">
              Last update {{ selected.last_activity_at ? formatDateTime(selected.last_activity_at) : '—' }}
            </div>
          </div>
        </section>

        <!-- Journey strip -->
        <section class="rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div class="text-sm font-semibold text-text-primary">At-a-glance journey</div>
              <div class="mt-1 text-xs leading-relaxed text-text-muted">
                A simplified lane—not every edge case maps linearly. Use the unified timeline below for authoritative detail.
              </div>
            </div>
          </div>
          <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:gap-3">
            <div v-for="(step, idx) in journeySteps" :key="step.id" class="flex min-w-0 flex-1 basis-0 items-start gap-3">
              <div class="flex flex-col items-center pt-0.5">
                <div
                  class="flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold"
                  :class="
                    step.current
                      ? 'border-brand bg-brand text-white shadow-md shadow-brand/25'
                      : step.done
                        ? 'border-success/40 bg-success/10 text-success'
                        : 'border-border bg-surface-muted text-text-muted'
                  "
                >
                  <CheckCircle2 v-if="step.done && !step.current" class="h-4 w-4" aria-hidden="true" />
                  <span v-else>{{ idx + 1 }}</span>
                </div>
                <div v-if="idx < journeySteps.length - 1" class="hidden h-px w-full flex-1 sm:block sm:h-8 sm:w-px sm:bg-border" />
              </div>
              <div class="min-w-0 pb-2">
                <div class="text-sm font-semibold text-text-primary">{{ step.label }}</div>
                <div class="mt-0.5 text-[11px] text-text-muted">
                  {{ step.current ? 'Current focus' : step.done ? 'Completed segment' : 'Pending' }}
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-12 lg:items-start">
          <!-- Activity -->
          <div class="space-y-6 lg:col-span-7 xl:col-span-8">
            <section class="rounded-2xl border border-border bg-surface shadow-sm">
              <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                  <div class="text-sm font-semibold text-text-primary">Unified activity</div>
                  <div class="mt-0.5 text-xs text-text-muted">
                    Milestones and application status changes, newest first ({{ feed.length }} shown).
                  </div>
                </div>
              </div>
              <div class="px-4 py-4 sm:px-6">
                <div v-if="feed.length === 0" class="rounded-xl border border-dashed border-border bg-surface-muted/80 px-4 py-8 text-center text-sm text-text-muted">
                  No lifecycle or status events recorded for this application yet.
                </div>
                <ol v-else class="relative space-y-0">
                  <li
                    v-for="(item, index) in feed"
                    :key="item.id"
                    class="relative flex gap-4 pb-8 pl-1 last:pb-0"
                  >
                    <div
                      class="absolute bottom-0 left-[18px] top-8 w-px bg-gradient-to-b from-border to-transparent"
                      aria-hidden="true"
                      :class="index === feed.length - 1 ? 'hidden' : ''"
                    />
                    <div
                      class="relative z-[1] flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-border bg-surface shadow-sm"
                    >
                      <component
                        :is="activityIcon(item.kind)"
                        class="h-4 w-4 text-brand"
                        aria-hidden="true"
                      />
                    </div>
                    <div class="min-w-0 flex-1 pt-0.5">
                      <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="text-sm font-semibold text-text-primary">{{ item.title }}</div>
                        <div class="text-right text-[11px] text-text-muted">
                          <div class="font-medium text-text-primary/90">{{ formatRelative(item.at) }}</div>
                          <div>{{ formatDateTime(item.at) }}</div>
                        </div>
                      </div>
                      <div class="mt-1 text-xs text-text-muted">{{ item.meta }}</div>
                      <p v-if="item.body" class="mt-2 text-sm leading-relaxed text-text-primary/95">{{ item.body }}</p>
                    </div>
                  </li>
                </ol>
              </div>
            </section>
          </div>

          <!-- Sidebar: meta + quals -->
          <div class="space-y-6 lg:col-span-5 xl:col-span-4">
            <section class="rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
              <div class="text-sm font-semibold text-text-primary">Application facts</div>
              <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                  <dt class="text-text-muted">Applicant</dt>
                  <dd class="text-right font-medium text-text-primary">{{ selected.applicant_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                  <dt class="text-text-muted">NRC / passport</dt>
                  <dd class="text-right font-medium text-text-primary">{{ selected.nrc_passport_number ?? '—' }}</dd>
                </div>
                <div v-if="selected.paid_at" class="flex justify-between gap-4">
                  <dt class="text-text-muted">Paid</dt>
                  <dd class="text-right font-medium text-text-primary">{{ formatDateTime(selected.paid_at) }}</dd>
                </div>
                <div v-if="selected.service_deadline_at" class="flex justify-between gap-4">
                  <dt class="text-text-muted">Service deadline</dt>
                  <dd class="text-right font-medium text-text-primary">{{ formatDateOnly(selected.service_deadline_at) }}</dd>
                </div>
                <div v-if="selected.completed_at" class="flex justify-between gap-4">
                  <dt class="text-text-muted">Completed</dt>
                  <dd class="text-right font-medium text-text-primary">{{ formatDateTime(selected.completed_at) }}</dd>
                </div>
              </dl>
            </section>

            <section class="rounded-2xl border border-border bg-surface shadow-sm">
              <div class="border-b border-border px-5 py-4 sm:px-6">
                <div class="text-sm font-semibold text-text-primary">Qualifications on this application</div>
                <div class="mt-1 text-xs text-text-muted">
                  Verification tasks—each can move independently through assignment and review.
                </div>
              </div>
              <div class="space-y-3 p-4 sm:p-5">
                <div
                  v-if="quals.length === 0"
                  class="rounded-xl border border-dashed border-border bg-surface-muted/80 px-4 py-6 text-center text-sm text-text-muted"
                >
                  No qualification rows are linked to this application.
                </div>
                <article
                  v-for="q in quals"
                  :key="q.id"
                  class="group relative overflow-hidden rounded-xl border border-border bg-gradient-to-br from-surface to-surface-muted/60 p-4 transition hover:border-brand/35 hover:shadow-md"
                >
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-brand">{{
                          q.verification_reference_number ?? '—'
                        }}</span>
                        <span class="zaqa-badge text-[10px]" :class="verificationQualBadgeClass(q.verification_state)">
                          {{ q.verification_state_label }}
                        </span>
                      </div>
                      <h3 class="mt-2 text-sm font-semibold leading-snug text-text-primary">
                        {{ q.title_of_qualification ?? 'Untitled qualification' }}
                      </h3>
                      <p class="mt-1 text-xs font-semibold text-text-primary">
                        <span class="font-medium text-text-muted">Names on qualification document:</span>
                        {{ q.names_as_on_qualification_document?.trim() || 'Not captured' }}
                      </p>
                      <div class="mt-2 space-y-1 text-xs text-text-muted">
                        <div v-if="q.qualification_type_label" class="flex gap-1">
                          <BookOpen class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                          <span>{{ q.qualification_type_label }}</span>
                        </div>
                        <div v-if="q.awarding_label" class="flex gap-1">
                          <User class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                          <span>{{ q.awarding_label }}</span>
                        </div>
                        <div v-if="q.assigned_verifier_name" class="flex gap-1">
                          <Shield class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                          <span>Assigned: {{ q.assigned_verifier_name }}</span>
                        </div>
                        <div class="text-[11px]">Updated {{ formatRelative(q.updated_at) }}</div>
                      </div>
                    </div>
                  </div>
                  <Link
                    v-if="q.verification_url"
                    :href="q.verification_url"
                    class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline"
                  >
                    Open qualification
                    <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                  </Link>
                </article>
              </div>
            </section>

            <section class="rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
              <div class="text-sm font-semibold text-text-primary">Status history</div>
              <div class="mt-4 space-y-3">
                <div v-if="(statuses?.length ?? 0) === 0" class="text-sm text-text-muted">No status history.</div>
                <div v-for="s in statuses" :key="s.id" class="rounded-xl border border-border bg-surface-muted p-4">
                  <div class="flex items-center justify-between gap-4">
                    <div class="text-sm font-semibold text-text-primary">{{ s.to_status }}</div>
                    <div class="text-xs text-text-muted">{{ s.changed_at ? formatRelative(s.changed_at) : '—' }}</div>
                  </div>
                  <div class="mt-1 text-xs text-text-muted">From {{ s.from_status ?? '—' }} · {{ s.changed_by ?? '—' }}</div>
                  <div v-if="s.comment" class="mt-2 text-sm text-text-primary">{{ s.comment }}</div>
                </div>
              </div>
            </section>
          </div>
        </div>
      </div>

      <div v-else class="mt-10 rounded-2xl border border-dashed border-border bg-surface-muted/40 px-6 py-14 text-center">
        <FileSearch class="mx-auto h-10 w-10 text-text-muted/80" aria-hidden="true" />
        <p class="mt-4 text-sm font-semibold text-text-primary">Select an application to see the full tracker</p>
        <p class="mt-2 text-sm text-text-muted">
          Use the search above, or open this page with <span class="font-mono text-xs">?application_id=</span> in the URL.
        </p>
      </div>
    </div>
  </AdminLayout>
</template>
