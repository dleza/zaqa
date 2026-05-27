<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useForm, Link } from '@inertiajs/vue3'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { CheckCircle2, Clock, FileText, ShieldCheck, Timer, XCircle } from 'lucide-vue-next'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  application: any
  viewerUserId: number | null
  can: { assign: boolean; send_back: boolean; level1_process: boolean; level2_review: boolean; approve: boolean; reject: boolean; issue: boolean }
}>()

const sendBackOpen = ref(false)
const level1CompleteOpen = ref(false)
const returnOpen = ref(false)
const approveOpen = ref(false)
const rejectOpen = ref(false)
const issueOpen = ref(false)
const commentOpen = ref(false)

const sendBackForm = useForm({ comment: '' })
const level1CompleteForm = useForm({ findings: '' })
const returnForm = useForm({ comment: '' })
const approveForm = useForm({ comment: '' })
const rejectForm = useForm({ reason: '' })
const issueForm = useForm({ comment: '' })
const commentForm = useForm<{ body: string; visibility: 'internal' | 'applicant_visible'; type: string }>({
  body: '',
  visibility: 'internal',
  type: 'general',
})

const state = computed<string>(() => (props.application.verification_state ?? '').toString())
const isViewerAssignedLevel1 = computed<boolean>(() => {
  if (!props.viewerUserId) return false
  return (props.application.assigned_level1_user_id ?? null) === props.viewerUserId
})

const applicantGenderLabel = computed<string | null>(() => {
  const v = (props.application?.applicant?.gender ?? '').toString().trim().toLowerCase()
  if (v === 'male') return 'Male'
  if (v === 'female') return 'Female'
  return null
})

const canShowReturnToLevel1 = computed(() => props.can.level2_review && state.value === 'under_level2_review')
const canShowApprove = computed(() => props.can.approve && state.value === 'under_level2_review')
const canShowReject = computed(() => props.can.reject && state.value === 'under_level2_review')
const canShowIssue = computed(() => props.can.issue && state.value === 'approved_for_certificate')

const canShowLevel1Complete = computed(() => {
  if (!props.can.level1_process) return false
  if (!isViewerAssignedLevel1.value) return false
  return ['assigned_to_level1', 'under_level1_review'].includes(state.value)
})

/** Same rule as backend qualification visibility: Level 1 officers without assign/L2 send back per qualification task only (not whole application). */
const isQualificationScopedVerifier = computed(
  () => props.can.level1_process && !props.can.assign && !props.can.level2_review,
)

const canShowSendBack = computed(() => {
  if (isQualificationScopedVerifier.value) return false
  if (!props.can.send_back) return false
  return !['approved_for_certificate', 'rejected', 'certificate_issued', 'closed'].includes(state.value)
})

/** Per-item verification tasks on this application (filtered server-side for Level 1). */
const qualificationsList = computed<any[]>(() => {
  const list = props.application?.qualifications
  return Array.isArray(list) ? list : []
})

const showQualificationsList = computed(() => qualificationsList.value.length > 1)

const statusBadgeClass = computed(() => {
  return (value: string | null | undefined) => {
    const s = (value ?? '').toString()
    if (['approved', 'certificate_ready', 'completed'].includes(s)) return 'zaqa-badge-success'
    if (['rejected', 'failed'].includes(s)) return 'zaqa-badge-danger'
    if (['submitted', 'resubmitted', 'sent_back'].includes(s)) return 'zaqa-badge-warning'
    if (['in_progress', 'under_review'].includes(s)) return 'zaqa-badge-info'
    return 'zaqa-badge-secondary'
  }
})

/** Final positive decision path (not only raw `approved` status — e.g. after certificate issuance). */
const decisionBannerKind = computed<'approved' | 'rejected' | null>(() => {
  const cs = (props.application.current_status ?? '').toString()
  const vs = (props.application.verification_state ?? '').toString()
  if (cs === 'rejected' || vs === 'rejected') return 'rejected'
  if (cs === 'approved' || cs === 'certificate_ready' || cs === 'completed') return 'approved'
  if (['approved_for_certificate', 'certificate_issued', 'closed'].includes(vs)) return 'approved'
  return null
})

const decisionBannerTitle = computed(() => {
  if (decisionBannerKind.value === 'rejected') return 'Application rejected'
  if (decisionBannerKind.value === 'approved') return 'Application approved'
  return ''
})

const decisionBannerSubtitle = computed(() => {
  const cs = (props.application.current_status ?? '').toString()
  const vs = (props.application.verification_state ?? '').toString()
  if (decisionBannerKind.value === 'rejected') {
    return 'This file has a final rejection on record. Further verification actions are not applicable unless the workflow is reopened elsewhere.'
  }
  if (decisionBannerKind.value === 'approved') {
    const parts: string[] = []
    if (cs) parts.push(`Status: ${cs.replace(/_/g, ' ')}`)
    if (vs) parts.push(`Verification: ${vs.replace(/_/g, ' ')}`)
    return parts.length ? parts.join(' · ') : 'A favourable decision is on record for this application.'
  }
  return ''
})

function parseIso(value: any): Date | null {
  if (!value) return null
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? null : d
}

function formatDuration(ms: number | null): string {
  if (ms === null || ms === undefined) return '—'
  const s = Math.max(0, Math.floor(ms / 1000))
  const m = Math.floor(s / 60)
  const h = Math.floor(m / 60)
  const d = Math.floor(h / 24)
  if (d > 0) return `${d}d ${h % 24}h`
  if (h > 0) return `${h}h ${m % 60}m`
  if (m > 0) return `${m}m`
  return `${s}s`
}

const nowMs = ref<number>(Date.now())
let tick: number | null = null
onMounted(() => {
  tick = window.setInterval(() => (nowMs.value = Date.now()), 30_000)
})
onBeforeUnmount(() => {
  if (tick) window.clearInterval(tick)
})

const submittedAt = computed(() => parseIso(props.application.submitted_at) ?? parseIso(props.application.created_at))
const deadlineAt = computed(() => parseIso(props.application.service_deadline_at))
const latestAssignmentAt = computed(() => parseIso(props.application.assignments?.[0]?.assigned_at))

/** Matches backend {@link App\Domain\Verification\SlaService}: no live countdown after terminal outcomes. */
function isTerminalForServiceSla(app: typeof props.application): boolean {
  if (app.completed_at) return true
  const vs = (app.verification_state ?? '').toString()
  if (['certificate_issued', 'closed', 'rejected'].includes(vs)) return true
  const cs = (app.current_status ?? '').toString()
  if (['rejected', 'certificate_ready', 'completed'].includes(cs)) return true
  return false
}

const slaClockActive = computed(() => !isTerminalForServiceSla(props.application))

const ageMs = computed(() => (submittedAt.value ? nowMs.value - submittedAt.value.getTime() : null))
const sinceAssignedMs = computed(() => (latestAssignmentAt.value ? nowMs.value - latestAssignmentAt.value.getTime() : null))

const dueInMs = computed(() => (deadlineAt.value ? deadlineAt.value.getTime() - nowMs.value : null))
const displayDueInMs = computed(() => (slaClockActive.value ? dueInMs.value : null))
const displayIsOverdue = computed(() => (displayDueInMs.value !== null ? displayDueInMs.value < 0 : false))
const displayOverdueByMs = computed(() => (displayIsOverdue.value ? Math.abs(displayDueInMs.value ?? 0) : 0))

const slaProgressPct = computed(() => {
  if (!slaClockActive.value) return null
  if (!submittedAt.value || !deadlineAt.value) return null
  const total = deadlineAt.value.getTime() - submittedAt.value.getTime()
  if (total <= 0) return 100
  const elapsed = nowMs.value - submittedAt.value.getTime()
  return Math.max(0, Math.min(100, Math.round((elapsed / total) * 100)))
})
</script>

<template>
  <AdminLayout>
    <!-- High-visibility final decision strip (green = approved path, red = rejected) -->
    <div
      v-if="decisionBannerKind"
      class="-mx-4 mb-6 flex items-start gap-4 border-y px-4 py-5 shadow-lg sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
      :class="
        decisionBannerKind === 'rejected'
          ? 'border-red-800/30 bg-red-600 text-white'
          : 'border-emerald-800/30 bg-emerald-600 text-white'
      "
      role="status"
      :aria-label="decisionBannerTitle"
    >
      <div
        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border-2 border-white/25 bg-white/15"
        aria-hidden="true"
      >
        <XCircle v-if="decisionBannerKind === 'rejected'" class="h-7 w-7" />
        <CheckCircle2 v-else class="h-7 w-7" />
      </div>
      <div class="min-w-0 flex-1 pt-0.5">
        <div class="text-lg font-bold tracking-tight sm:text-xl">{{ decisionBannerTitle }}</div>
        <div class="mt-1.5 text-sm font-medium leading-snug text-white/95 sm:text-[0.9375rem]">
          {{ application.application_number }} — {{ decisionBannerSubtitle }}
        </div>
      </div>
      <span
        class="shrink-0 self-center rounded-lg border border-white/30 bg-white/15 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-white"
      >
        {{ decisionBannerKind === 'rejected' ? 'Rejected' : 'Approved' }}
      </span>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Application {{ application.application_number }}</h1>
        <p class="mt-1 text-sm text-text-muted">Review, comment, assign, and decide.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to pool</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Applicant & qualification</div>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Applicant</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.applicant?.name ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ application.applicant?.email ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">{{ application.applicant?.phone ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">Gender: {{ applicantGenderLabel ?? '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">NRC/Passport: {{ application.applicant?.nrc_passport ?? '—' }}</div>
            </div>
            <div class="sm:col-span-2">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification(s)</div>
              <div v-if="showQualificationsList" class="mt-3 space-y-3">
                <div
                  v-for="q in qualificationsList"
                  :key="q.id"
                  class="rounded-xl border border-border bg-surface-muted/50 px-4 py-3"
                >
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                      <div class="text-sm font-semibold text-text-primary">{{ q.title ?? '—' }}</div>
                      <div class="mt-1 text-xs text-text-muted">
                        {{ q.country ?? '—' }} • {{ q.awarding_institution ?? '—' }}
                      </div>
                      <div class="mt-1 text-xs text-text-muted">Award date: {{ q.award_date ?? '—' }}</div>
                      <div v-if="q.verification_state" class="mt-1 text-[11px] font-medium text-text-muted">
                        Task: {{ String(q.verification_state).replace(/_/g, ' ') }}
                      </div>
                    </div>
                    <Link
                      :href="q.href"
                      class="zaqa-btn zaqa-btn-secondary h-9 shrink-0 px-3 py-2 text-xs"
                    >
                      Open task
                    </Link>
                  </div>
                </div>
              </div>
              <template v-else>
                <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification?.title ?? '—' }}</div>
                <div class="mt-1 text-xs text-text-muted">
                  {{ application.qualification?.country ?? '—' }} • {{ application.qualification?.awarding_institution ?? '—' }}
                </div>
                <div class="mt-1 text-xs text-text-muted">Award date: {{ application.qualification?.award_date ?? '—' }}</div>
              </template>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-center justify-between gap-4">
            <div class="text-sm font-semibold text-text-primary">Documents</div>
            <div v-if="application.invoice || application.latest_payment" class="text-xs text-text-muted">
              Finance links are available on the right panel.
            </div>
          </div>
          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-4 py-3 text-left">Type</th>
                  <th class="px-4 py-3 text-left">File</th>
                  <th class="px-4 py-3 text-left">Version</th>
                  <th class="px-4 py-3 text-left">Uploaded</th>
                  <th class="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="d in application.documents" :key="d.id">
                  <td class="px-4 py-3 font-semibold text-text-primary">{{ d.document_type }}</td>
                  <td class="px-4 py-3 text-text-primary">
                    <div class="inline-flex items-center gap-2">
                      <FileText class="h-4 w-4 text-text-muted" aria-hidden="true" />
                      <span>{{ d.original_name }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-text-primary">v{{ d.version_number }}<span v-if="!d.is_current_version" class="text-xs text-text-muted"> (old)</span></td>
                  <td class="px-4 py-3 text-text-muted">{{ d.created_at ? new Date(d.created_at).toLocaleString() : '—' }}</td>
                  <td class="px-4 py-3 text-right">
                    <div class="inline-flex items-center gap-2">
                      <a v-if="d.preview_url" :href="d.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                        View
                      </a>
                      <a v-if="d.download_url" :href="d.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                        Download
                      </a>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-center justify-between gap-4">
            <div class="text-sm font-semibold text-text-primary">Comments</div>
            <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="commentOpen = true">
              Add comment
            </button>
          </div>
          <div class="mt-4 space-y-3">
            <div v-if="application.comments.length === 0" class="text-sm text-text-muted">No comments yet.</div>
            <div v-for="c in application.comments" :key="c.id" class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between gap-4">
                <div class="text-xs font-semibold text-text-muted">
                  {{ c.visibility }} • {{ c.type }} • {{ c.author_name ?? '—' }}
                </div>
                <div class="text-xs text-text-muted">{{ c.created_at ? new Date(c.created_at).toLocaleString() : '—' }}</div>
              </div>
              <div class="mt-2 text-sm text-text-primary whitespace-pre-wrap">{{ c.body }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Internal timeline</div>
          <div class="mt-4 space-y-3">
            <div v-if="application.lifecycle.length === 0" class="text-sm text-text-muted">No timeline events.</div>
            <div v-for="e in application.lifecycle" :key="e.id" class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between gap-4">
                <div class="text-sm font-semibold text-text-primary">{{ e.title }}</div>
                <div class="text-xs text-text-muted">{{ e.occurred_at ? new Date(e.occurred_at).toLocaleString() : '—' }}</div>
              </div>
              <div class="mt-1 text-xs text-text-muted">{{ e.stage }} • {{ e.visibility }} • {{ e.actor_name ?? '—' }}</div>
              <div v-if="e.description" class="mt-2 text-sm text-text-primary">{{ e.description }}</div>
              <div v-if="e.comment" class="mt-2 text-sm text-text-primary whitespace-pre-wrap">{{ e.comment }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-6">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Operational snapshot</div>
              <div class="mt-1 text-xs text-text-muted">Age, assignment timing, and SLA health.</div>
            </div>
            <div class="text-right">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">SLA</div>
              <div
                class="mt-1 inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold"
                :class="
                  !slaClockActive
                    ? 'border-border bg-surface-muted text-text-muted'
                    : displayIsOverdue
                      ? 'border-red-300/40 bg-red-500/15 text-red-900'
                      : 'border-emerald-300/40 bg-emerald-500/15 text-emerald-900'
                "
              >
                <Timer class="h-3.5 w-3.5" aria-hidden="true" />
                <span v-if="!deadlineAt">—</span>
                <span v-else-if="!slaClockActive">Closed</span>
                <span v-else-if="displayIsOverdue">Overdue by {{ formatDuration(displayOverdueByMs) }}</span>
                <span v-else>Due in {{ formatDuration(displayDueInMs) }}</span>
              </div>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application age</div>
                <Clock class="h-4 w-4 text-text-muted" aria-hidden="true" />
              </div>
              <div class="mt-2 text-lg font-semibold text-text-primary">{{ formatDuration(ageMs) }}</div>
              <div class="mt-1 text-xs text-text-muted">
                Since {{ submittedAt ? submittedAt.toLocaleString() : '—' }}
              </div>
            </div>

            <div class="rounded-xl border border-border bg-surface-muted p-4">
              <div class="flex items-center justify-between">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Since Level 1 assignment</div>
                <Timer class="h-4 w-4 text-text-muted" aria-hidden="true" />
              </div>
              <div class="mt-2 text-lg font-semibold text-text-primary">{{ latestAssignmentAt ? formatDuration(sinceAssignedMs) : '—' }}</div>
              <div class="mt-1 text-xs text-text-muted">
                {{ latestAssignmentAt ? `Assigned ${latestAssignmentAt.toLocaleString()}` : 'Not assigned yet' }}
              </div>
            </div>
          </div>

          <div v-if="slaProgressPct !== null" class="mt-4">
            <div class="flex items-center justify-between text-xs text-text-muted">
              <div>Elapsed SLA</div>
              <div class="font-semibold text-text-primary">{{ slaProgressPct }}%</div>
            </div>
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-muted">
              <div
                class="h-full rounded-full transition-[width]"
                :class="displayIsOverdue ? 'bg-red-500/60' : 'bg-emerald-500/60'"
                :style="{ width: `${Math.min(100, slaProgressPct)}%` }"
              />
            </div>
          </div>
          <p v-else-if="deadlineAt && !slaClockActive" class="mt-4 text-xs text-text-muted">
            Service target window is closed for this application; countdown and progress are not shown.
          </p>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm space-y-3">
          <div class="text-sm font-semibold text-text-primary">Actions</div>

          <div v-if="can.assign" class="rounded-xl border border-border bg-surface-muted p-4 text-xs text-text-muted">
            Level 1 assignment is handled per qualification item. Open a qualification below to assign or reassign.
          </div>

          <button
            v-if="canShowSendBack"
            type="button"
            class="zaqa-btn w-full justify-center border border-amber-300/40 bg-amber-500/15 text-amber-900 hover:bg-amber-500/20"
            @click="sendBackOpen = true"
          >
            Send back to applicant
          </button>

          <button
            v-if="canShowLevel1Complete"
            type="button"
            class="zaqa-btn w-full justify-center border border-sky-300/40 bg-sky-500/15 text-sky-900 hover:bg-sky-500/20"
            @click="level1CompleteOpen = true"
          >
            Mark Level 1 complete
          </button>

          <button
            v-if="canShowReturnToLevel1"
            type="button"
            class="zaqa-btn w-full justify-center border border-amber-300/40 bg-amber-500/15 text-amber-900 hover:bg-amber-500/20"
            @click="returnOpen = true"
          >
            Return to Level 1
          </button>

          <button
            v-if="canShowApprove"
            type="button"
            class="zaqa-btn w-full justify-center border border-emerald-300/40 bg-emerald-500/15 text-emerald-900 hover:bg-emerald-500/20"
            @click="approveOpen = true"
          >
            Approve
          </button>

          <button
            v-if="canShowReject"
            type="button"
            class="zaqa-btn w-full justify-center border border-red-300/40 bg-red-500/15 text-red-900 hover:bg-red-500/20"
            @click="rejectOpen = true"
          >
            Reject
          </button>

          <button
            v-if="canShowIssue"
            type="button"
            class="zaqa-btn w-full justify-center border border-emerald-300/40 bg-emerald-500/15 text-emerald-900 hover:bg-emerald-500/20"
            @click="issueOpen = true"
          >
            Issue certificate (hook)
          </button>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Billing</div>

          <div v-if="application.invoice" class="mt-3 rounded-xl border border-border bg-surface-muted p-4">
            <div class="flex items-center justify-between gap-4">
              <div class="text-sm font-semibold text-text-primary">Invoice {{ application.invoice.invoice_number }}</div>
              <span class="zaqa-badge zaqa-badge-secondary">{{ application.invoice.status }}</span>
            </div>
            <div class="mt-2 text-sm text-text-primary">
              {{ formatMoneyFromCents(application.invoice.amount_cents, application.invoice.currency) }}
            </div>
            <div class="mt-1 text-xs text-text-muted">
              Issued {{ application.invoice.issued_at ? new Date(application.invoice.issued_at).toLocaleString() : '—' }}
            </div>
            <div v-if="application.invoice.paid_at" class="mt-1 text-xs text-text-muted">
              Paid {{ new Date(application.invoice.paid_at).toLocaleString() }}
            </div>
          </div>
          <div v-else class="mt-3 text-sm text-text-muted">No invoice found.</div>

          <div v-if="application.latest_payment" class="mt-4 rounded-xl border border-border bg-surface-muted p-4">
            <div class="flex items-center justify-between gap-4">
              <div class="text-sm font-semibold text-text-primary">Latest payment</div>
              <span class="zaqa-badge zaqa-badge-secondary">{{ application.latest_payment.status }}</span>
            </div>
            <div class="mt-2 text-xs text-text-muted">
              {{ application.latest_payment.method }} • {{ formatMoneyFromCents(application.latest_payment.amount_cents, application.latest_payment.currency) }}
            </div>
            <div class="mt-1 text-xs text-text-muted">
              {{ application.latest_payment.created_at ? new Date(application.latest_payment.created_at).toLocaleString() : '—' }}
            </div>
            <div v-if="application.latest_payment.proof_document" class="mt-3 flex items-center gap-2">
              <a
                :href="application.latest_payment.proof_document.preview_url"
                target="_blank"
                rel="noopener"
                class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
              >
                View proof
              </a>
              <a :href="application.latest_payment.proof_document.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                Download proof
              </a>
            </div>
          </div>

          <div v-if="can.finance_view" class="mt-4">
            <a :href="`/finance/applications/${application.id}/track`" class="zaqa-btn zaqa-btn-secondary w-full justify-center px-4 py-2 text-sm">
              View finance record
            </a>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Status</div>
          <div class="mt-3 grid gap-3 text-sm">
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Application</div>
              <div class="font-semibold text-text-primary">{{ application.current_status }}</div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Verification</div>
              <div class="font-semibold text-text-primary">{{ application.verification_state ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-text-muted">Deadline</div>
              <div class="font-semibold text-text-primary">
                {{ application.service_deadline_at ? new Date(application.service_deadline_at).toLocaleDateString() : '—' }}
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <AdminActionModal v-model="sendBackOpen" title="Send back to applicant" description="A comment is required and will be visible to the applicant.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Comment</label>
        <textarea v-model="sendBackForm.comment" class="zaqa-input mt-2 h-auto min-h-[8rem] py-3" placeholder="Explain what must be corrected or added." />
        <div v-if="sendBackForm.errors.comment" class="mt-1 text-xs text-danger">{{ sendBackForm.errors.comment }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="sendBackOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="sendBackForm.processing"
          @click="sendBackForm.post(`/admin/verification/applications/${application.id}/send-back`, { preserveScroll: true, onSuccess: () => (sendBackOpen = false) })"
        >
          Send back
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="level1CompleteOpen" title="Mark Level 1 review complete" description="Your findings are internal and will be sent to Level 2 for final review.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Findings</label>
        <textarea v-model="level1CompleteForm.findings" class="zaqa-input mt-2 h-auto min-h-[10rem] py-3" placeholder="Summarize checks performed, issues found, and recommendation." />
        <div v-if="level1CompleteForm.errors.findings" class="mt-1 text-xs text-danger">{{ level1CompleteForm.errors.findings }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="level1CompleteOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="level1CompleteForm.processing"
          @click="level1CompleteForm.post(`/admin/verification/applications/${application.id}/level1-complete`, { preserveScroll: true, onSuccess: () => (level1CompleteOpen = false) })"
        >
          Confirm
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="returnOpen" title="Return to Level 1" description="Provide an internal comment for the Level 1 reviewer.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Comment</label>
        <textarea v-model="returnForm.comment" class="zaqa-input mt-2 h-auto min-h-[8rem] py-3" placeholder="What needs to be checked or corrected?" />
        <div v-if="returnForm.errors.comment" class="mt-1 text-xs text-danger">{{ returnForm.errors.comment }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="returnOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="returnForm.processing"
          @click="returnForm.post(`/admin/verification/applications/${application.id}/level2-return-to-level1`, { preserveScroll: true, onSuccess: () => (returnOpen = false) })"
        >
          Send
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="approveOpen" title="Approve application" description="Optional internal comment. Applicant will see status change.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
        <textarea v-model="approveForm.comment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional internal note." />
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="approveOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="approveForm.processing"
          @click="approveForm.post(`/admin/verification/applications/${application.id}/approve`, { preserveScroll: true, onSuccess: () => (approveOpen = false) })"
        >
          Approve
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="rejectOpen" title="Reject application" description="Reason is required and will be visible to the applicant.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Reason</label>
        <textarea v-model="rejectForm.reason" class="zaqa-input mt-2 h-auto min-h-[10rem] py-3" placeholder="Provide a clear rejection reason." />
        <div v-if="rejectForm.errors.reason" class="mt-1 text-xs text-danger">{{ rejectForm.errors.reason }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="rejectOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="rejectForm.processing"
          @click="rejectForm.post(`/admin/verification/applications/${application.id}/reject`, { preserveScroll: true, onSuccess: () => (rejectOpen = false) })"
        >
          Reject
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="commentOpen"
      title="Add comment"
      description="Choose whether this comment is internal (staff only) or visible to the applicant (it will appear in their tracking timeline)."
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Visibility</label>
          <select v-model="commentForm.visibility" class="zaqa-input mt-2">
            <option value="internal">Internal (staff only)</option>
            <option value="applicant_visible">Visible to applicant</option>
          </select>
          <div v-if="(commentForm.errors as any).visibility" class="mt-1 text-xs text-danger">{{ (commentForm.errors as any).visibility }}</div>
        </div>

        <div>
          <label class="text-sm font-semibold text-text-primary">Comment</label>
          <textarea v-model="commentForm.body" class="zaqa-input mt-2 h-auto min-h-[8rem] py-3" placeholder="Write a comment…" />
          <div v-if="(commentForm.errors as any).body" class="mt-1 text-xs text-danger">{{ (commentForm.errors as any).body }}</div>
        </div>
      </div>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="commentOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="commentForm.processing"
          @click="
            commentForm.post(`/admin/verification/applications/${application.id}/comments`, {
              preserveScroll: true,
              onSuccess: () => {
                commentForm.reset()
                commentForm.clearErrors()
                commentOpen = false
              },
            })
          "
        >
          Save
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="issueOpen" title="Issue certificate (hook)" description="This marks the certificate as issued for workflow purposes.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
        <textarea v-model="issueForm.comment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional internal note." />
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="issueOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="issueForm.processing"
          @click="issueForm.post(`/admin/verification/applications/${application.id}/issue-certificate`, { preserveScroll: true, onSuccess: () => (issueOpen = false) })"
        >
          Confirm
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
