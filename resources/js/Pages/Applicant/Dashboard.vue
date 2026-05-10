<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRight,
  BadgeCheck,
  CircleAlert,
  ClipboardList,
  CreditCard,
  Eye,
  FileText,
  History,
  LayoutDashboard,
  RefreshCcw,
  Send,
} from 'lucide-vue-next'

const page = usePage()
const authUserName = computed(() => ((page.props as any).auth?.user?.name ?? '').toString().trim())
const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
})

const props = defineProps<{
  counts: Record<string, number>
  continueDraft: { label: string; href: string; kind: string } | null
  applications: Array<any>
  activity: Array<any>
  alerts: Array<any>
  returnedQualifications: Array<any>
  returnedQualificationsCount: number
}>()

function money(cents: number, currency: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZMW' }).format((cents ?? 0) / 100)
}

const hasApplications = computed(() => (props.applications?.length ?? 0) > 0)
const firstReturnedQualification = computed(() => (props.returnedQualifications?.length ?? 0) > 0 ? props.returnedQualifications[0] : null)
const returnedQualificationsLabel = computed(() => {
  const c = Number(props.returnedQualificationsCount ?? 0)
  if (c <= 0) return ''
  return c === 1 ? 'Update returned qualification' : `Update returned qualifications (${c})`
})

const activityRows = computed(() => {
  const byId = Object.fromEntries((props.applications ?? []).map((a: any) => [a.id, a]))
  return (props.activity ?? []).map((row: any) => ({
    ...row,
    application_number: byId[row.application_id]?.application_number ?? `Application #${row.application_id}`,
  }))
})

function formatActivityWhen(iso: string | undefined) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d)
}

function humanizeStatus(raw: string | null | undefined) {
  const s = (raw ?? '').toString().trim()
  if (!s) return '—'
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

const trackModalOpen = ref(false)
const trackSelectedId = ref<number | ''>('')
const trackSelected = computed(() => props.applications.find((a: any) => Number(a.id) === Number(trackSelectedId.value || 0)) ?? null)

function openTrackModal() {
  trackModalOpen.value = true
  trackSelectedId.value = ''
}

function closeTrackModal() {
  trackModalOpen.value = false
  trackSelectedId.value = ''
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && trackModalOpen.value) closeTrackModal()
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

function badgeClass(status: string) {
  const s = (status ?? '').toString()
  if (s === 'draft') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'sent_back') return 'zaqa-badge zaqa-badge-warning'
  if (s === 'submitted') return 'zaqa-badge zaqa-badge-info'
  if (s === 'approved') return 'zaqa-badge zaqa-badge-success'
  if (s === 'rejected') return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}

function qualBadgeClass(state: string) {
  const s = (state ?? '').toString()
  if (['awaiting_assignment', 'unassigned', 'submitted'].includes(s)) return 'zaqa-badge zaqa-badge-info'
  if (['assigned', 'in_review', 'under_review'].includes(s)) return 'zaqa-badge zaqa-badge-warning'
  if (['sent_back', 'returned'].includes(s)) return 'zaqa-badge zaqa-badge-warning'
  if (['approved', 'verified', 'completed'].includes(s)) return 'zaqa-badge zaqa-badge-success'
  if (['rejected', 'failed'].includes(s)) return 'zaqa-badge zaqa-badge-danger'
  return 'zaqa-badge'
}
</script>

<template>
  <ApplicantLayout>
    <div
      class="w-full max-w-none mx-auto -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-8 2xl:-mx-10 2xl:px-10"
    >
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="max-w-4xl">
          <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
            <LayoutDashboard class="h-4 w-4" aria-hidden="true" />
            Applicant portal
          </div>
          <h2 class="mt-3 text-3xl font-semibold tracking-tight text-text-primary sm:text-4xl">Dashboard</h2>
          <p class="mt-2 text-base leading-relaxed text-text-muted">
            {{ greeting }}{{ authUserName ? ` ${authUserName}` : '' }}. Track verification applications, invoices, and what to do next—all in one place.
          </p>
        </div>
      </div>

      <!-- Summary cards -->
      <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Link
          href="/applicant/applications"
          class="flex min-h-[7.5rem] flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/5 transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md sm:p-6"
        >
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Total</div>
            <FileText class="h-5 w-5 text-brand" aria-hidden="true" />
          </div>
          <div class="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-text-primary">{{ counts.total ?? 0 }}</div>
        </Link>
        <Link
          href="/applicant/applications"
          class="flex min-h-[7.5rem] flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/5 transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md sm:p-6"
        >
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Draft</div>
            <RefreshCcw class="h-5 w-5 text-accent" aria-hidden="true" />
          </div>
          <div class="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-text-primary">{{ counts.draft ?? 0 }}</div>
        </Link>
        <Link
          href="/applicant/applications"
          class="flex min-h-[7.5rem] flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/5 transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md sm:p-6"
        >
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Submitted</div>
            <Send class="h-5 w-5 text-brand" aria-hidden="true" />
          </div>
          <div class="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-text-primary">{{ counts.submitted ?? 0 }}</div>
        </Link>
        <Link
          href="/applicant/applications"
          class="flex min-h-[7.5rem] flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/5 transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md sm:p-6"
        >
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Sent back</div>
            <CircleAlert class="h-5 w-5 text-warning" aria-hidden="true" />
          </div>
          <div class="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-text-primary">{{ counts.sent_back ?? 0 }}</div>
        </Link>
        <Link
          href="/applicant/applications"
          class="flex min-h-[7.5rem] flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-sm ring-1 ring-black/5 transition hover:border-brand/25 hover:bg-surface-muted hover:shadow-md sm:p-6"
        >
          <div class="flex items-center justify-between">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Approved</div>
            <BadgeCheck class="h-5 w-5 text-success" aria-hidden="true" />
          </div>
          <div class="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-text-primary">{{ counts.approved ?? 0 }}</div>
        </Link>
      </div>

      <!-- Alerts + Quick actions -->
      <div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-12">
        <div class="space-y-6 xl:col-span-8">
          <div v-if="alerts.length > 0" class="rounded-2xl border border-border bg-surface p-6 shadow-sm ring-1 ring-black/5 sm:p-7">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-base font-semibold text-text-primary">Important</div>
                <div class="mt-1 text-sm text-text-muted">Items that may require your attention.</div>
              </div>
            </div>
            <div class="mt-5 space-y-3">
              <Link
                v-for="a in alerts"
                :key="a.title + a.message"
                :href="a.href || '/applicant/applications'"
                class="block rounded-xl border border-border bg-surface-muted px-4 py-3 transition hover:bg-surface"
              >
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="text-sm font-semibold text-text-primary">{{ a.title }}</div>
                    <div class="mt-1 text-xs text-text-muted">{{ a.message }}</div>
                  </div>
                  <ArrowRight class="h-4 w-4 text-text-muted" aria-hidden="true" />
                </div>
              </Link>
            </div>
          </div>

          <!-- Applications overview -->
          <div class="rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/5">
            <div class="border-b border-border bg-surface-muted px-6 py-5 sm:px-7">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="text-base font-semibold text-text-primary">Applications overview</div>
                  <div class="mt-1 text-sm text-text-muted">Status, qualifications, and quick actions for each application.</div>
                </div>
                <Link href="/applicant/applications" class="zaqa-link shrink-0 text-sm font-medium">View all</Link>
              </div>
            </div>

            <div v-if="!hasApplications" class="px-6 py-10 sm:px-8">
              <div class="rounded-2xl border border-border bg-surface-muted p-8 text-center sm:p-10">
                <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10">
                  <ClipboardList class="h-6 w-6 text-brand" aria-hidden="true" />
                </div>
                <div class="mt-4 text-lg font-semibold text-text-primary">Start your first application</div>
                <div class="mt-2 max-w-md mx-auto text-sm leading-relaxed text-text-muted">
                  Create a verification request and track progress from submission through payment and decision.
                </div>
                <div class="mt-6">
                  <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary h-11 px-8">New application</Link>
                </div>
              </div>
            </div>

            <div v-else class="space-y-4 px-6 py-6 sm:px-7 sm:py-7">
              <div
                v-for="app in applications.slice(0, 6)"
                :key="app.id"
                class="rounded-2xl border border-border bg-surface-muted/50 p-5 transition hover:border-brand/20 hover:bg-surface-muted sm:p-6"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <div class="text-sm font-semibold text-text-primary">{{ app.application_number }}</div>
                      <span :class="badgeClass(app.current_status)">{{ app.status_label }}</span>
                      <span v-if="(app.qualifications?.length ?? 0) > 0" class="zaqa-badge">
                        {{ app.qualifications.length }} qualification{{ app.qualifications.length === 1 ? '' : 's' }}
                      </span>
                    </div>
                    <div class="mt-1 text-xs text-text-muted">Updated {{ app.updated_at ?? app.created_at ?? '—' }}</div>
                  </div>

                  <div class="flex flex-wrap gap-2 sm:justify-end">
                    <button type="button" class="zaqa-btn zaqa-btn-secondary h-10 px-4 text-sm" @click="openTrackModal(); trackSelectedId = app.id">
                      <Eye class="mr-2 h-4 w-4" aria-hidden="true" />
                      Track
                    </button>
                    <Link
                      v-if="app.amend_action"
                      :href="app.amend_action.href"
                      class="zaqa-btn zaqa-btn-warning h-10 px-4 text-sm"
                    >
                      <CircleAlert class="h-4 w-4" aria-hidden="true" />
                      {{ app.amend_action.label }}
                    </Link>
                    <Link :href="app.primary_action.href" class="zaqa-btn zaqa-btn-secondary h-10 px-4 text-sm">
                      {{ app.primary_action.label }}
                    </Link>
                  </div>
                </div>

                <div v-if="(app.qualifications?.length ?? 0) > 0" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  <div v-for="q in app.qualifications.slice(0, 6)" :key="q.id" class="rounded-xl border border-border bg-surface px-4 py-3">
                    <div class="flex items-start justify-between gap-2">
                      <div class="min-w-0">
                        <div class="truncate text-xs font-semibold text-text-primary">
                          {{ q.qualification_type ? `${q.qualification_type.level_label} — ${q.qualification_type.name}` : 'Qualification' }}
                        </div>
                        <div class="mt-0.5 truncate text-xs text-text-muted">{{ q.title_of_qualification || '—' }}</div>
                      </div>
                      <span :class="qualBadgeClass(q.verification_state)">{{ q.verification_state || '—' }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick actions -->
        <aside class="space-y-6 xl:col-span-4">
          <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm ring-1 ring-black/5 sm:p-7">
            <div class="text-base font-semibold text-text-primary">Quick actions</div>
            <div class="mt-1 text-sm text-text-muted">Shortcuts to verification workflow and billing.</div>

            <div class="mt-6 grid grid-cols-1 gap-3">
              <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary h-11 w-full justify-center">
                <ClipboardList class="mr-2 h-4 w-4" aria-hidden="true" />
                New application
              </Link>

              <Link
                v-if="firstReturnedQualification"
                :href="firstReturnedQualification.href"
                class="zaqa-btn zaqa-btn-warning h-11 w-full justify-center"
              >
                <CircleAlert class="mr-2 h-4 w-4" aria-hidden="true" />
                {{ returnedQualificationsLabel }}
              </Link>

              <button
                type="button"
                class="zaqa-btn zaqa-btn-secondary h-11 w-full justify-center border border-brand/20 bg-brand/5 text-brand hover:bg-brand/10"
                :disabled="applications.length === 0"
                @click="openTrackModal"
              >
                <Eye class="mr-2 h-4 w-4" aria-hidden="true" />
                Track application
              </button>

              <Link v-if="continueDraft" :href="continueDraft.href" class="zaqa-btn zaqa-btn-secondary w-full">
                <RefreshCcw class="mr-2 h-4 w-4" aria-hidden="true" />
                {{ continueDraft.label }}
              </Link>

              <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary h-11 w-full justify-center">
                <FileText class="mr-2 h-4 w-4" aria-hidden="true" />
                My applications
              </Link>

              <Link href="/applicant/invoices" class="zaqa-btn zaqa-btn-secondary h-11 w-full justify-center">
                <CreditCard class="mr-2 h-4 w-4" aria-hidden="true" />
                View invoices
              </Link>
            </div>
          </div>
        </aside>
      </div>

      <!-- Recent activity -->
      <div v-if="hasApplications" class="mt-8 rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/5">
        <div class="border-b border-border bg-surface-muted px-6 py-5 sm:px-7">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-4">
              <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-border bg-surface shadow-sm">
                <History class="h-6 w-6 text-brand" aria-hidden="true" />
              </div>
              <div>
                <div class="text-base font-semibold text-text-primary">Recent activity</div>
                <div class="mt-1 text-sm text-text-muted">Latest status changes recorded on your applications.</div>
              </div>
            </div>
            <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary h-11 shrink-0 px-5">All applications</Link>
          </div>
        </div>

        <div v-if="activityRows.length === 0" class="px-6 py-12 text-center sm:px-8">
          <p class="mx-auto max-w-lg text-sm leading-relaxed text-text-muted">
            No status history yet. When ZAQA updates an application status, it will appear here with a timestamp.
          </p>
        </div>

        <ul v-else class="divide-y divide-border">
          <li v-for="row in activityRows" :key="row.id">
            <Link
              :href="`/applicant/applications/${row.application_id}`"
              class="flex flex-col gap-3 px-6 py-5 transition hover:bg-surface-muted/80 sm:flex-row sm:items-center sm:justify-between sm:px-7 sm:py-5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold text-text-primary">{{ row.application_number }}</span>
                  <span class="text-sm text-text-muted">·</span>
                  <span class="text-sm text-text-muted">{{ formatActivityWhen(row.changed_at) }}</span>
                </div>
                <div class="mt-2 text-sm">
                  <span class="font-medium text-text-primary">{{ humanizeStatus(row.from_status) }}</span>
                  <span class="mx-2 text-text-muted">→</span>
                  <span class="font-medium text-text-primary">{{ humanizeStatus(row.to_status) }}</span>
                </div>
                <p v-if="row.comment" class="mt-2 text-sm leading-relaxed text-text-muted line-clamp-2">
                  {{ row.comment }}
                </p>
              </div>
              <ArrowRight class="h-5 w-5 shrink-0 text-text-muted sm:ml-4" aria-hidden="true" />
            </Link>
          </li>
        </ul>
      </div>
    </div>

    <!-- Track modal -->
    <div v-if="trackModalOpen" class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-black/50" @click="closeTrackModal" />
      <div class="absolute inset-0 flex items-end justify-center p-4 sm:items-center">
        <div class="w-full max-w-4xl overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-text-primary">Track an application</div>
                <div class="mt-1 text-xs text-text-muted">Select an application to view its latest activity.</div>
              </div>
              <button type="button" class="zaqa-btn zaqa-btn-ghost px-3 py-2 text-sm" @click="closeTrackModal">Close</button>
            </div>
          </div>

          <div class="px-5 py-5 space-y-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Application</label>
                <select v-model="trackSelectedId" class="zaqa-input mt-1">
                  <option value="">Select application…</option>
                  <option v-for="a in applications" :key="a.id" :value="a.id">
                    {{ a.application_number }} — {{ a.status_label }}
                  </option>
                </select>
              </div>
              <div class="flex items-end">
                <Link v-if="trackSelectedId" :href="`/applicant/applications/${trackSelectedId}/track`" class="zaqa-btn zaqa-btn-secondary w-full">
                  Open tracking
                </Link>
                <button v-else type="button" class="zaqa-btn zaqa-btn-secondary w-full" disabled>Open tracking</button>
              </div>
            </div>

            <div v-if="trackSelected" class="overflow-hidden rounded-2xl border border-border">
              <div class="border-b border-border bg-surface-muted px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="text-sm font-semibold text-text-primary">
                    {{ trackSelected.application_number }}
                  </div>
                  <span :class="badgeClass(trackSelected.current_status)">{{ trackSelected.status_label }}</span>
                </div>
              </div>
              <div class="p-4 bg-surface space-y-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualifications</div>
                <div v-if="(trackSelected.qualifications?.length ?? 0) === 0" class="text-sm text-text-muted">No qualifications found.</div>
                <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <div v-for="q in trackSelected.qualifications" :key="q.id" class="rounded-xl border border-border bg-surface-muted/40 px-3 py-2">
                    <div class="flex items-start justify-between gap-2">
                      <div class="min-w-0">
                        <div class="truncate text-xs font-semibold text-text-primary">
                          {{ q.qualification_type ? `${q.qualification_type.level_label} — ${q.qualification_type.name}` : 'Qualification' }}
                        </div>
                        <div class="mt-0.5 truncate text-xs text-text-muted">{{ q.title_of_qualification || '—' }}</div>
                      </div>
                      <span :class="qualBadgeClass(q.verification_state)">{{ q.verification_state || '—' }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
              Select an application to see its qualifications here.
            </div>
          </div>

          <div class="border-t border-border bg-surface-muted px-5 py-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div class="text-xs text-text-muted">Tip: Press Esc to close.</div>
              <div class="flex gap-2">
                <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary">My applications</Link>
                <Link href="/applicant/applications/new" class="zaqa-btn zaqa-btn-primary">New application</Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
