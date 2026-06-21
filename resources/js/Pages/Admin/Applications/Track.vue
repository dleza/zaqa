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
  X,
} from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

type SearchResult = {
  id: number
  application_number: string
  status: string
  status_label: string
  applicant_name: string | null
  submitted_at: string | null
  qualification_count: number
  matched_qualification_id: number | null
  matched_qualification_reference: string | null
  matched_qualification_title: string | null
  view_url: string | null
  view_label: string
}

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
  search?: {
    performed: boolean
    results: SearchResult[]
    error: string | null
  }
  filters?: {
    application_id?: string | null
    application_reference?: string | null
    qualification_reference?: string | null
  }
  can?: { view_verification: boolean }
}>()

const applicationReference = ref('')
const qualificationReference = ref('')
const searching = ref(false)

watch(
  () => props.filters,
  (filters) => {
    applicationReference.value = filters?.application_reference ?? ''
    qualificationReference.value = filters?.qualification_reference ?? ''
  },
  { immediate: true, deep: true },
)

function hasUsableReference(value: string): boolean {
  return value.trim().length >= 3
}

function submitSearch() {
  const appRef = applicationReference.value.trim()
  const qualRef = qualificationReference.value.trim()

  if (!hasUsableReference(appRef) && !hasUsableReference(qualRef)) {
    return
  }

  searching.value = true
  router.get(
    '/admin/applications/track',
    {
      application_reference: appRef || undefined,
      qualification_reference: qualRef || undefined,
    },
    {
      preserveScroll: true,
      onFinish: () => {
        searching.value = false
      },
    },
  )
}

function clearSearch() {
  applicationReference.value = ''
  qualificationReference.value = ''
  searching.value = true
  router.get(
    '/admin/applications/track',
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        searching.value = false
      },
    },
  )
}

const searchResults = computed(() => props.search?.results ?? [])
const searchPerformed = computed(() => props.search?.performed ?? false)
const searchError = computed(() => props.search?.error ?? null)

const highlightQualificationRef = computed(() => {
  const ref = (props.filters?.qualification_reference ?? '').trim().toUpperCase()
  return ref.length >= 3 ? ref : null
})

function isHighlightedQualification(verificationReference: string | null | undefined): boolean {
  const ref = highlightQualificationRef.value
  if (!ref) return false
  const qref = (verificationReference ?? '').trim().toUpperCase()
  return qref !== '' && qref.startsWith(ref)
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
          <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Search For Application</h1>
          <p class="mt-1 text-sm leading-relaxed text-text-muted">
            Look up an application or qualification by its official reference number. Each field is independent—search runs only when you click Search, not while you type.
          </p>
        </div>
      </div>

      <section
        class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.03]"
        aria-labelledby="application-search-heading"
      >
        <div class="border-b border-border bg-gradient-to-r from-brand/[0.06] via-transparent to-accent/[0.05] px-5 py-5 sm:px-6">
          <h2 id="application-search-heading" class="text-sm font-semibold text-text-primary">Reference search</h2>
          <p class="mt-1 max-w-3xl text-xs leading-relaxed text-text-muted">
            Use the application reference for the whole submission (e.g. <span class="font-mono">2026-000245</span>) or the qualification reference for a single verification task (e.g. <span class="font-mono">2026-000245-01</span>). Names and NRC numbers are not searched here.
          </p>
        </div>

        <form class="px-5 py-5 sm:px-6" @submit.prevent="submitSearch">
          <div class="grid gap-5 lg:grid-cols-2">
            <div>
              <label for="application-reference" class="block text-sm font-semibold text-text-primary">
                Application reference
              </label>
              <p class="mt-1 text-xs text-text-muted">ZAQA application number assigned at submission.</p>
              <input
                id="application-reference"
                v-model="applicationReference"
                type="text"
                autocomplete="off"
                spellcheck="false"
                class="zaqa-input mt-3 h-11 w-full font-mono text-sm"
                placeholder="2026-000245"
              />
            </div>
            <div>
              <label for="qualification-reference" class="block text-sm font-semibold text-text-primary">
                Qualification reference
              </label>
              <p class="mt-1 text-xs text-text-muted">Verification task reference for one qualification on an application.</p>
              <input
                id="qualification-reference"
                v-model="qualificationReference"
                type="text"
                autocomplete="off"
                spellcheck="false"
                class="zaqa-input mt-3 h-11 w-full font-mono text-sm"
                placeholder="2026-000245-01"
              />
            </div>
          </div>

          <div class="mt-5 flex flex-wrap items-center gap-3">
            <button
              type="submit"
              class="zaqa-btn zaqa-btn-primary inline-flex h-10 items-center gap-2 px-4 text-sm"
              :disabled="searching"
            >
              <Search class="h-4 w-4" aria-hidden="true" />
              {{ searching ? 'Searching…' : 'Search' }}
            </button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-secondary inline-flex h-10 items-center gap-2 px-4 text-sm"
              :disabled="searching"
              @click="clearSearch"
            >
              <X class="h-4 w-4" aria-hidden="true" />
              Clear
            </button>
            <p class="text-xs text-text-muted">Minimum three characters in at least one field. Up to 25 results.</p>
          </div>
        </form>

        <div v-if="searchError" class="border-t border-border bg-danger/5 px-5 py-4 text-sm text-danger sm:px-6">
          {{ searchError }}
        </div>

        <div v-else-if="searchPerformed" class="border-t border-border">
          <div class="flex items-center justify-between gap-3 px-5 py-4 sm:px-6">
            <div class="text-sm font-semibold text-text-primary">Search results</div>
            <div class="text-xs text-text-muted">{{ searchResults.length }} match{{ searchResults.length === 1 ? '' : 'es' }}</div>
          </div>

          <div v-if="searchResults.length === 0" class="border-t border-border px-5 py-10 text-center sm:px-6">
            <FileSearch class="mx-auto h-8 w-8 text-text-muted/70" aria-hidden="true" />
            <p class="mt-3 text-sm font-medium text-text-primary">No applications matched those references</p>
            <p class="mt-1 text-sm text-text-muted">Check the reference format and try again, or search using the other field.</p>
          </div>

          <div v-else class="overflow-x-auto border-t border-border">
            <table class="min-w-full divide-y divide-border text-sm">
              <thead class="bg-surface-muted/60">
                <tr>
                  <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted sm:px-6">
                    Application
                  </th>
                  <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Qualification
                  </th>
                  <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Applicant
                  </th>
                  <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Status
                  </th>
                  <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Submitted
                  </th>
                  <th scope="col" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-text-muted sm:px-6">
                    Action
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border bg-surface">
                <tr v-for="result in searchResults" :key="result.id" class="transition hover:bg-surface-muted/50">
                  <td class="whitespace-nowrap px-5 py-4 font-mono font-semibold text-brand sm:px-6">
                    {{ result.application_number }}
                    <div v-if="result.qualification_count > 1" class="mt-0.5 font-sans text-[11px] font-normal text-text-muted">
                      {{ result.qualification_count }} qualifications
                    </div>
                  </td>
                  <td class="px-5 py-4">
                    <div v-if="result.matched_qualification_reference" class="font-mono text-xs font-semibold text-text-primary">
                      {{ result.matched_qualification_reference }}
                    </div>
                    <div v-else class="text-xs text-text-muted">—</div>
                    <div v-if="result.matched_qualification_title" class="mt-1 max-w-xs truncate text-xs text-text-muted">
                      {{ result.matched_qualification_title }}
                    </div>
                  </td>
                  <td class="px-5 py-4 text-text-primary">{{ result.applicant_name ?? '—' }}</td>
                  <td class="px-5 py-4">
                    <span class="zaqa-badge" :class="statusBadgeClass(result.status)">{{ result.status_label }}</span>
                  </td>
                  <td class="whitespace-nowrap px-5 py-4 text-xs text-text-muted">
                    {{ formatDateOnly(result.submitted_at) }}
                  </td>
                  <td class="whitespace-nowrap px-5 py-4 text-right sm:px-6">
                    <Link
                      v-if="result.view_url"
                      :href="result.view_url"
                      class="zaqa-btn zaqa-btn-secondary inline-flex h-9 items-center gap-1.5 px-3 text-xs"
                    >
                      {{ result.view_label }}
                      <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                    <span v-else class="text-xs text-text-muted">No access</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

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
                  class="group relative overflow-hidden rounded-xl border p-4 transition hover:shadow-md"
                  :class="
                    isHighlightedQualification(q.verification_reference_number)
                      ? 'border-brand bg-gradient-to-br from-brand/10 to-surface-muted/60 ring-2 ring-brand/30'
                      : 'border-border bg-gradient-to-br from-surface to-surface-muted/60 hover:border-brand/35'
                  "
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

      <div v-else-if="!searchPerformed" class="mt-10 rounded-2xl border border-dashed border-border bg-surface-muted/40 px-6 py-14 text-center">
        <FileSearch class="mx-auto h-10 w-10 text-text-muted/80" aria-hidden="true" />
        <p class="mt-4 text-sm font-semibold text-text-primary">Search by reference to open the tracker</p>
        <p class="mt-2 text-sm text-text-muted">
          Enter an application or qualification reference above and click Search, then choose a result to view qualifications, milestones, and status history.
        </p>
      </div>
    </div>
  </AdminLayout>
</template>
