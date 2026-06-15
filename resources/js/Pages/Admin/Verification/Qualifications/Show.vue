<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRight,
  Building2,
  ChevronDown,
  ClipboardCheck,
  CornerDownLeft,
  Clock,
  Copy,
  ExternalLink,
  FileDown,
  FileStack,
  Globe2,
  LayoutList,
  Link2,
  Pencil,
  Shield,
  RotateCcw,
  Sparkles,
  Timer,
  UserMinus,
  UserRound,
} from 'lucide-vue-next'

const props = defineProps<{
  qualification: any
  viewerUserId: number | null
  level1Users: Array<{ id: number; name: string; email: string }>
  send_back_timeline?: Array<{
    kind: string
    at: string | null
    author_name?: string | null
    body?: string | null
    title?: string | null
    description?: string | null
  }>
  can: {
    assign: boolean
    send_back: boolean
    level1_process: boolean
    level2_review?: boolean
    approve?: boolean
    reject?: boolean
    edit_qualification?: boolean
    issue_certificate?: boolean
    is_super_admin?: boolean
    view_learner_records?: boolean
  }
}>()

const assignOpen = ref(false)
const revokeOpen = ref(false)
const sendBackOpen = ref(false)
const level1CompleteOpen = ref(false)
const approveOpen = ref(false)
const rejectOpen = ref(false)
const sendBackHistoryOpen = ref(false)
const copiedRef = ref(false)
const copiedPageUrl = ref(false)

const assignForm = useForm({ assigned_to_user_id: props.qualification.assigned_verifier_id ?? '', comment: '' })
const revokeForm = useForm({ comment: '' })
const sendBackForm = useForm({ comment: '' })
const level1CompleteForm = useForm<{ findings: string; attachment: File | null }>({ findings: '', attachment: null })
const level1AttachmentInput = ref<HTMLInputElement | null>(null)
const issueCveqForm = useForm<{ reissue: boolean }>({ reissue: false })
const approveForm = useForm<{ comment: string; issue_certificate: boolean }>({ comment: '', issue_certificate: false })
const rejectForm = useForm<{ reason: string }>({ reason: '' })
const recheckAutoVerificationForm = useForm({})
const autoAssignLevel1Form = useForm({})

function clearLevel1Attachment() {
  level1CompleteForm.attachment = null
  if (level1AttachmentInput.value) {
    level1AttachmentInput.value.value = ''
  }
}

function submitIssueCveq() {
  issueCveqForm.reissue = false
  issueCveqForm.post(props.qualification.issue_certificate_url, { preserveScroll: true })
}

function submitReissueCveq() {
  if (
    !confirm(
      'Reissue creates a new CVEQ PDF and marks the previous certificate as superseded. Continue?',
    )
  ) {
    return
  }
  router.post(
    props.qualification.issue_certificate_url,
    { reissue: true },
    { preserveScroll: true },
  )
}

const isForeign = computed(() => !!props.qualification.is_foreign)
const appNum = computed(() => props.qualification.application?.application_number ?? '—')

/** Pool intake rows created before per-qualification state may have null in DB; treat as awaiting assignment. */
const state = computed(() => {
  const s = (props.qualification.verification_state ?? '').toString().trim()
  return s === '' ? 'awaiting_assignment' : s
})

const stateDisplay = computed(() => {
  const labels: Record<string, string> = {
    awaiting_auto_verification: 'Awaiting auto-verification',
    awaiting_assignment: 'Awaiting assignment',
    assigned_to_level1: 'Assigned — Level 1',
    under_level1_review: 'Under Level 1 review',
    under_level2_review: 'Under Level 2 review',
    auto_verified_pending_level2: 'Auto-verified — pending Level 2',
    returned_to_applicant: 'Returned to applicant',
    approved_for_certificate: 'Approved for certificate',
    rejected: 'Rejected',
    certificate_issued: 'Certificate issued',
    closed: 'Closed',
  }
  return labels[state.value] ?? state.value.replace(/_/g, ' ')
})

const isViewerAssignedLevel1 = computed(() => {
  if (!props.viewerUserId) return false
  return (props.qualification.assigned_verifier_id ?? null) === props.viewerUserId
})

const isRestrictedLevel1 = computed(() => {
  return (
    props.can.level1_process === true &&
    props.can.assign !== true &&
    props.can.approve !== true &&
    props.can.reject !== true &&
    props.can.issue_certificate !== true
  )
})

const restrictedLevel1CanAct = computed(() => {
  if (!isRestrictedLevel1.value) return true
  return ['assigned_to_level1', 'under_level1_review'].includes(state.value)
})

const canShowAssign = computed(() => {
  if (!props.can.assign) return false
  return ['awaiting_assignment', 'assigned_to_level1', 'under_level1_review'].includes(state.value)
})

const canShowSendBack = computed(() => {
  if (!props.can.send_back) return false
  if (isRestrictedLevel1.value) {
    return ['assigned_to_level1', 'under_level1_review'].includes(state.value)
  }
  return !['approved_for_certificate', 'rejected', 'certificate_issued', 'closed', 'returned_to_applicant'].includes(
    state.value,
  )
})

const canShowLevel1Complete = computed(() => {
  if (!props.can.level1_process) return false
  if (!isViewerAssignedLevel1.value) return false
  return ['assigned_to_level1', 'under_level1_review'].includes(state.value)
})

/** Level 2 / Super Admin: remove Level 1 assignee and return task to the assignment pool. */
const canEditQualificationDetails = computed(() => props.can.edit_qualification === true && restrictedLevel1CanAct.value)

const canShowApprove = computed(() => props.can.approve === true && ['under_level2_review', 'auto_verified_pending_level2'].includes(state.value))
const canShowReject = computed(() => props.can.reject === true && ['under_level2_review', 'auto_verified_pending_level2'].includes(state.value))

const level2Lock = computed(() => props.qualification.level2_review_lock ?? {})
const isAutoVerifiedPendingL2 = computed(() => state.value === 'auto_verified_pending_level2')
const isLevel2Viewer = computed(() => props.can.level2_review === true)
const isSuperAdmin = computed(() => props.can.is_super_admin === true)
const lockIsActive = computed(() => !!level2Lock.value?.is_locked)
const viewerHasLock = computed(() => {
  if (!props.viewerUserId) return false
  return lockIsActive.value && Number(level2Lock.value?.locked_by_user_id ?? 0) === Number(props.viewerUserId)
})
const lockBlocksActions = computed(() => isAutoVerifiedPendingL2.value && lockIsActive.value && !viewerHasLock.value && !isSuperAdmin.value)
const lockMissingForActions = computed(() => isAutoVerifiedPendingL2.value && !viewerHasLock.value && !isSuperAdmin.value)

const canAcquireLock = computed(() => isAutoVerifiedPendingL2.value && isLevel2Viewer.value && (!lockIsActive.value || isSuperAdmin.value || viewerHasLock.value))
const canReleaseLock = computed(() => isAutoVerifiedPendingL2.value && isLevel2Viewer.value && lockIsActive.value && (viewerHasLock.value || isSuperAdmin.value))

function lockForReview() {
  router.post(props.qualification.level2_lock_url, {}, { preserveScroll: true })
}

function unlockReview() {
  router.post(props.qualification.level2_unlock_url, {}, { preserveScroll: true })
}

function sendToManualReview() {
  if (!confirm('Send this auto-verified qualification to manual verification (Level 1 assignment queue)?')) return
  router.post(props.qualification.send_to_manual_review_url, {}, { preserveScroll: true })
}

function queueAutoVerificationRecheck() {
  if (!props.qualification.recheck_auto_verification_url) return
  recheckAutoVerificationForm.post(props.qualification.recheck_auto_verification_url, { preserveScroll: true })
}

function retryAutoAssignLevel1() {
  if (!props.qualification.auto_assign_level1_url) return
  autoAssignLevel1Form.post(props.qualification.auto_assign_level1_url, { preserveScroll: true })
}

const autoStatus = computed(() => (props.qualification.auto_verification?.status ?? '').toString())
const autoConfidence = computed(() => {
  const v = props.qualification.auto_verification?.confidence
  if (v == null) return null
  const n = Number(v)
  if (Number.isNaN(n)) return null
  return Math.min(100, Math.max(0, Math.round(n)))
})
const autoMatchedFields = computed<Record<string, boolean>>(() => {
  const raw = props.qualification.auto_verification?.match_summary?.matched_fields
  if (!raw || typeof raw !== 'object') return {}
  return raw as Record<string, boolean>
})

const autoRecommendation = computed(() => {
  if (!isAutoVerifiedPendingL2.value) return null
  const status = autoStatus.value
  const conf = autoConfidence.value ?? 0
  if (status === 'matched' && conf >= 70) return 'Recommended: Approve and issue certificate'
  if (['ambiguous', 'possible_match'].includes(status) && conf >= 70) return 'Recommended: Level 2 review (do not auto-issue)'
  return 'Recommended: Manual review'
})

const canManageRetryActions = computed(() => props.can.level2_review === true || props.can.is_super_admin === true)
const canViewLearnerRecords = computed(() => props.can.view_learner_records === true)

const canShowRevokeAssignment = computed(() => {
  if (!props.can.assign) return false
  if (!props.qualification.assigned_verifier_id) return false
  return ['assigned_to_level1', 'under_level1_review'].includes(state.value)
})

/** Which step in the ladder is active (0–3) for highlight */
const workflowActiveStep = computed(() => {
  const s = state.value
  if (['returned_to_applicant'].includes(s)) return -1
  if (s === 'awaiting_auto_verification') return 0
  if (s === 'awaiting_assignment') return 0
  if (['assigned_to_level1', 'under_level1_review'].includes(s)) return 1
  if (['under_level2_review', 'auto_verified_pending_level2'].includes(s)) return 2
  if (['approved_for_certificate', 'rejected', 'certificate_issued', 'closed'].includes(s)) return 3
  return 0
})

const workflowSteps = computed(() => [
  { key: 'intake', label: 'Intake', sub: 'Pool / assignment' },
  { key: 'l1', label: 'Level 1', sub: 'Verifier review' },
  { key: 'l2', label: 'Level 2', sub: 'Final review' },
  { key: 'outcome', label: 'Outcome', sub: 'Decision / issue' },
])

const ownerLine = computed(() => {
  const name = props.qualification.assigned_verifier_name
  if (name) return { title: 'Level 1 owner', name, hint: 'Responsible verifier for this task' }
  return { title: 'Level 1 owner', name: null, hint: 'Unassigned — Level 2 must assign a Level 1 officer' }
})

async function copyVerificationRef() {
  const t = props.qualification.verification_reference_number
  if (!t || typeof navigator?.clipboard?.writeText !== 'function') return
  try {
    await navigator.clipboard.writeText(t)
    copiedRef.value = true
    window.setTimeout(() => {
      copiedRef.value = false
    }, 2000)
  } catch {
    // ignore
  }
}

async function copyPageUrl() {
  if (typeof navigator?.clipboard?.writeText !== 'function') return
  try {
    await navigator.clipboard.writeText(window.location.href)
    copiedPageUrl.value = true
    window.setTimeout(() => {
      copiedPageUrl.value = false
    }, 2000)
  } catch {
    // ignore
  }
}

const viewerHint = computed(() => {
  if (!props.viewerUserId) return null
  if (isViewerAssignedLevel1.value) return 'You are the assigned Level 1 verifier for this task.'
  if (props.can.assign && !props.can.level1_process) return 'Level 2 access: you can assign or reassign Level 1.'
  if (props.can.level1_process && !props.can.assign) return 'Level 1 access: review when assigned to you.'
  return null
})

function parseIso(value: unknown): Date | null {
  if (!value) return null
  const d = new Date(value as string)
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

const documentTypeLabels: Record<string, string> = {
  level1_review_attachment: 'Level 1 review attachment',
}
function documentTypeLabel(raw: string) {
  return documentTypeLabels[raw] ?? raw.replace(/_/g, ' ')
}

const level1Findings = computed(() => (props.qualification?.reviewer_notes ?? '').toString().trim())
const level1ReviewedAt = computed(() => parseIso(props.qualification?.reviewed_at))
const hasLevel1Findings = computed(() => level1Findings.value.length > 0)
const level1Attachment = computed(() => {
  const docs = (props.qualification?.documents ?? []) as any[]
  return docs.find((d) => d.document_type === 'level1_review_attachment' && d.is_current_version) ?? null
})

const nowMs = ref<number>(Date.now())
let slaTick: number | null = null
onMounted(() => {
  slaTick = window.setInterval(() => (nowMs.value = Date.now()), 30_000)
})
onBeforeUnmount(() => {
  if (slaTick) window.clearInterval(slaTick)
})

/** Parent application payload remains for context; SLA timing is qualification-scoped. */
const slaApplication = computed(() => props.qualification?.application ?? {})

const slaStartedAt = computed(
  () =>
    parseIso(props.qualification?.service_started_at) ??
    parseIso(slaApplication.value.submitted_at) ??
    parseIso(slaApplication.value.created_at),
)
const deadlineAt = computed(
  () =>
    parseIso(props.qualification?.service_deadline_at) ??
    parseIso(slaApplication.value.service_deadline_at),
)
/** Latest Level 1 assignment event on this qualification task (sorted newest first). */
const latestAssignmentAt = computed(() => parseIso(props.qualification.assignments?.[0]?.assigned_at))

function isClosedForServiceSla(qualification: Record<string, unknown>, app: Record<string, unknown>): boolean {
  const qState = (qualification.verification_state ?? '').toString()
  if (
    ['returned_to_applicant', 'approved_for_certificate', 'rejected', 'certificate_issued', 'closed'].includes(
      qState,
    )
  ) {
    return true
  }

  if (app.completed_at) return true
  const cs = (app.current_status ?? '').toString()
  if (['rejected', 'certificate_ready', 'completed'].includes(cs)) return true
  return false
}

const slaClockActive = computed(
  () => !isClosedForServiceSla(props.qualification ?? {}, slaApplication.value as Record<string, unknown>),
)

const ageMs = computed(() => (slaStartedAt.value ? nowMs.value - slaStartedAt.value.getTime() : null))
const sinceAssignedMs = computed(() =>
  latestAssignmentAt.value ? nowMs.value - latestAssignmentAt.value.getTime() : null,
)

const dueInMs = computed(() => (deadlineAt.value ? deadlineAt.value.getTime() - nowMs.value : null))
const displayDueInMs = computed(() => (slaClockActive.value ? dueInMs.value : null))
const displayIsOverdue = computed(() => (displayDueInMs.value !== null ? displayDueInMs.value < 0 : false))
const displayOverdueByMs = computed(() => (displayIsOverdue.value ? Math.abs(displayDueInMs.value ?? 0) : 0))

const slaProgressPct = computed(() => {
  if (!slaClockActive.value) return null
  if (!slaStartedAt.value || !deadlineAt.value) return null
  const total = deadlineAt.value.getTime() - slaStartedAt.value.getTime()
  if (total <= 0) return 100
  const elapsed = nowMs.value - slaStartedAt.value.getTime()
  return Math.max(0, Math.min(100, Math.round((elapsed / total) * 100)))
})

const sendBackTimeline = computed(() => (Array.isArray(props.send_back_timeline) ? props.send_back_timeline : []).filter((r) => r.at))

function formatTimelineAt(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function formatDateTime(value: Date | null | undefined) {
  if (!value) return '—'
  try {
    return value.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return '—'
  }
}

function formatDateOnly(value: string | null | undefined) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' })
  } catch {
    return value
  }
}

function displayValue(value: unknown) {
  if (value === null || value === undefined) return '—'
  const text = String(value).trim()
  return text === '' ? '—' : text
}

function matchedStateClass(matched: boolean | undefined) {
  return matched
    ? 'border-emerald-300/40 bg-emerald-500/15 text-emerald-900'
    : 'border-border/70 bg-surface text-text-muted'
}

function matchedStateLabel(matched: boolean | undefined) {
  return matched ? 'Matched' : 'Not matched'
}

const qualificationFacts = computed(() => [
  {
    label: 'Qualification type',
    value: props.qualification.qualification_type ?? '—',
  },
  {
    label: 'Scope / locality',
    value: isForeign.value ? 'Foreign qualification' : 'Local qualification',
  },
  {
    label: 'Awarding institution',
    value: props.qualification.awarding_institution ?? '—',
  },
  {
    label: 'Country of award',
    value: props.qualification.country ?? '—',
  },
  {
    label: 'Award date',
    value: props.qualification.award_date ? formatDateOnly(props.qualification.award_date) : '—',
  },
  {
    label: 'Identifier',
    value: props.qualification.student_number || props.qualification.certificate_number || '—',
  },
])

const titleComparisonRows = computed(() => [
  {
    label: 'Applicant title',
    value: props.qualification.title ?? '—',
    meta: `Source: ${props.qualification.qualification_title_source || '—'}`,
  },
  {
    label: 'Applicant typed (Other)',
    value: props.qualification.applicant_entered_qualification_title || '—',
    meta: null,
  },
  {
    label: 'Verified title',
    value: props.qualification.verified_qualification_title || '—',
    meta: null,
  },
])

const matchedFieldItems = computed(() => [
  { key: 'awarding_institution_id', label: 'Awarding institution', matched: autoMatchedFields.value.awarding_institution_id },
  { key: 'year_awarded', label: 'Award year', matched: autoMatchedFields.value.year_awarded },
  { key: 'student_id', label: 'Student number', matched: autoMatchedFields.value.student_id },
  { key: 'certificate_no', label: 'Certificate number', matched: autoMatchedFields.value.certificate_no },
  { key: 'nrc_number', label: 'NRC', matched: autoMatchedFields.value.nrc_number },
  { key: 'passport_no', label: 'Passport', matched: autoMatchedFields.value.passport_no },
  { key: 'name', label: 'Name', matched: autoMatchedFields.value.name },
  { key: 'program_of_study', label: 'Programme / title', matched: autoMatchedFields.value.program_of_study },
])

const evidenceRows = computed(() => [
  {
    key: 'student_id',
    field: 'Student number',
    applicant: props.qualification.student_number,
    record: props.qualification.learner_record?.student_id,
    matched: autoMatchedFields.value.student_id,
  },
  {
    key: 'certificate_no',
    field: 'Certificate number',
    applicant: props.qualification.certificate_number,
    record: props.qualification.learner_record?.certificate_no,
    matched: autoMatchedFields.value.certificate_no,
  },
  {
    key: 'awarding_institution_id',
    field: 'Awarding institution',
    applicant: props.qualification.awarding_institution,
    record:
      props.qualification.learner_record?.awarding_institution?.name ||
      props.qualification.learner_record?.institution_name_raw,
    matched: autoMatchedFields.value.awarding_institution_id,
  },
  {
    key: 'year_awarded',
    field: 'Award year',
    applicant: props.qualification.award_date ? new Date(props.qualification.award_date).getFullYear() : null,
    record: props.qualification.learner_record?.year_awarded,
    matched: autoMatchedFields.value.year_awarded,
  },
])

const documentsCount = computed(() => props.qualification.documents?.length ?? 0)
const subjectResultsCount = computed(() => props.qualification.subject_results?.length ?? 0)
</script>

<template>
  <AdminLayout>
    <div class="-mx-4 w-[calc(100%+2rem)] max-w-none sm:-mx-6 sm:w-[calc(100%+3rem)] lg:-mx-8 lg:w-[calc(100%+4rem)]">
      <div class="space-y-6 px-4 pb-10 sm:px-6 lg:px-8">
      <!-- Command header: identity + status at a glance -->
      <section
        class="relative overflow-hidden rounded-2xl border border-brand-dark/20 bg-gradient-to-br from-brand-dark via-[#0c4a7c] to-brand shadow-[0_4px_24px_-4px_rgba(11,58,102,0.35)]"
      >
        <div
          class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-brand/30 blur-3xl"
          aria-hidden="true"
        />
        <div class="relative px-5 py-5 sm:px-7 sm:py-6">
          <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.2em] text-white/90"
                >
                  Verification task
                </span>
                <span
                  class="inline-flex items-center gap-1 rounded-full border border-white/15 bg-black/20 px-2.5 py-0.5 text-[11px] font-semibold text-white/95"
                >
                  <Shield class="h-3.5 w-3.5 opacity-90" aria-hidden="true" />
                  {{ isForeign ? 'Foreign qualification' : 'Local qualification' }}
                </span>
              </div>

              <h1 class="mt-3 text-2xl font-bold tracking-tight text-white sm:text-3xl">
                {{ qualification.title ?? 'Qualification' }}
              </h1>

              <div class="mt-4 grid gap-x-4 gap-y-2 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,0.95fr)_minmax(0,1fr)_auto] xl:items-end">
                <div class="min-w-0">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Verification reference</div>
                  <div class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-lg font-semibold tracking-wide text-white sm:text-xl">
                      {{ qualification.verification_reference_number ?? '—' }}
                    </span>
                    <button
                      v-if="qualification.verification_reference_number"
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-white/25 bg-white/10 px-2 py-1 text-xs font-medium text-white transition hover:bg-white/20"
                      @click="copyVerificationRef"
                    >
                      <Copy class="h-3.5 w-3.5" aria-hidden="true" />
                      {{ copiedRef ? 'Copied' : 'Copy' }}
                    </button>
                  </div>
                </div>
                <div class="min-w-0">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Application</div>
                  <div class="mt-1 font-mono text-base font-semibold text-white">{{ appNum }}</div>
                </div>
                <div class="min-w-0">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Holder</div>
                  <div class="mt-1 truncate text-sm font-semibold text-white sm:text-base">{{ qualification.holder_name ?? '—' }}</div>
                </div>
                <div class="min-w-0">
                  <div class="text-[10px] font-semibold uppercase tracking-wider text-white/60">Internal ID</div>
                  <div class="mt-1 font-mono text-sm text-white/90">#{{ qualification.id }}</div>
                </div>
              </div>
            </div>

            <div class="grid shrink-0 gap-3 sm:grid-cols-2 xl:w-[22rem] xl:grid-cols-2">
              <div class="rounded-xl border border-white/20 bg-black/25 px-4 py-3 backdrop-blur-sm">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Workflow status</div>
                <div class="mt-1.5 text-sm font-semibold leading-snug text-white">{{ stateDisplay }}</div>
              </div>
              <div class="rounded-xl border border-white/20 bg-black/25 px-4 py-3 backdrop-blur-sm">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-white/65">Payment status</div>
                <div class="mt-1.5 text-sm font-semibold capitalize text-white">
                  {{ qualification.application?.payment_status ?? '—' }}
                </div>
              </div>
            </div>
          </div>

          <p v-if="viewerHint" class="mt-4 max-w-3xl border-t border-white/15 pt-3 text-sm leading-relaxed text-white/85">
            {{ viewerHint }}
          </p>

          <div class="mt-5 border-t border-white/15 pt-4">
            <div class="flex flex-wrap gap-2">
              <Link
                :href="`/admin/verification/applications/${qualification.application?.id}`"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200/30 bg-slate-950/25 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-950/35"
              >
                <ExternalLink class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Parent application
              </Link>
              <Link
                href="/admin/verification/pool"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200/30 bg-slate-800/30 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800/40"
              >
                <LayoutList class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Verification pool
              </Link>
              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-black/25 px-3.5 py-2 text-sm font-medium text-white/95 transition hover:bg-black/35"
                @click="copyPageUrl"
              >
                <Link2 class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ copiedPageUrl ? 'Link copied' : 'Copy page link' }}
              </button>

              <button
                v-if="sendBackTimeline.length > 0"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-amber-300/40 bg-amber-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-amber-500/25"
                @click="sendBackHistoryOpen = true"
              >
                Returned to applicant ({{ sendBackTimeline.length }})
              </button>

              <Link
                v-if="canEditQualificationDetails"
                :href="`/admin/verification/qualifications/${qualification.id}/edit`"
                class="inline-flex items-center gap-2 rounded-xl border border-sky-300/40 bg-sky-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500/25"
              >
                <Pencil class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Edit details
              </Link>

              <button
                v-if="canShowAssign"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-sky-300/40 bg-sky-500/20 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500/30"
                @click="assignOpen = true"
              >
                <ArrowRight class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ qualification.assigned_verifier_id ? 'Reassign Level 1' : 'Assign Level 1' }}
              </button>

              <button
                v-if="canShowRevokeAssignment"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-rose-300/40 bg-rose-600/20 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-rose-600/30"
                @click="revokeOpen = true"
              >
                <UserMinus class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Remove assignment
              </button>

              <button
                v-if="canShowSendBack"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-amber-300/40 bg-amber-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-amber-500/25"
                :disabled="isAutoVerifiedPendingL2 && lockMissingForActions"
                :title="isAutoVerifiedPendingL2 && lockMissingForActions ? 'Lock for review before taking Level 2 actions.' : ''"
                @click="sendBackOpen = true"
              >
                Send back
              </button>

              <button
                v-if="isAutoVerifiedPendingL2 && isLevel2Viewer && canAcquireLock"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-sky-300/40 bg-sky-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500/25"
                @click="lockForReview"
              >
                <Timer class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ lockIsActive ? 'Take over lock' : 'Start review' }}
              </button>

              <button
                v-if="isAutoVerifiedPendingL2 && isLevel2Viewer && canReleaseLock"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
                @click="unlockReview"
              >
                <RotateCcw class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Release lock
              </button>

              <button
                v-if="isAutoVerifiedPendingL2 && isLevel2Viewer"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200/30 bg-slate-950/20 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-950/30"
                :disabled="lockMissingForActions"
                :title="lockMissingForActions ? 'Lock for review before taking Level 2 actions.' : ''"
                @click="sendToManualReview"
              >
                <CornerDownLeft class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Manual review
              </button>

              <button
                v-if="canShowLevel1Complete"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-sky-300/40 bg-sky-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500/25"
                @click="level1CompleteOpen = true"
              >
                Mark Level 1 complete
              </button>

              <button
                v-if="canShowApprove"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-emerald-300/40 bg-emerald-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500/25"
                :disabled="isAutoVerifiedPendingL2 && lockMissingForActions"
                :title="isAutoVerifiedPendingL2 && lockMissingForActions ? 'Lock for review before approving.' : ''"
                @click="approveOpen = true"
              >
                Approve
              </button>

              <button
                v-if="canShowReject"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-rose-300/40 bg-rose-600/20 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-rose-600/30"
                :disabled="isAutoVerifiedPendingL2 && lockMissingForActions"
                :title="isAutoVerifiedPendingL2 && lockMissingForActions ? 'Lock for review before rejecting.' : ''"
                @click="rejectOpen = true"
              >
                Reject
              </button>

              <button
                v-if="qualification.can_issue_cveq_certificate && can.issue_certificate"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-emerald-300/40 bg-emerald-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500/25"
                :disabled="issueCveqForm.processing"
                @click="submitIssueCveq"
              >
                Issue certificate
              </button>

              <a
                v-if="qualification.cveq_certificate?.admin_download_url && can.issue_certificate"
                :href="qualification.cveq_certificate.admin_download_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
              >
                <FileDown class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                Download certificate
              </a>

              <button
                v-if="qualification.can_reissue_cveq_certificate && can.issue_certificate"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-amber-300/40 bg-amber-500/15 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-amber-500/25"
                @click="submitReissueCveq"
              >
                Reissue certificate
              </button>
            </div>
          </div>
        </div>
      </section>

      <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
        <div class="space-y-5 lg:col-span-8">
          <section class="rounded-2xl border border-border/70 bg-surface p-5 shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <h2 class="text-base font-bold tracking-tight text-text-primary">Qualification record</h2>
                  <p class="mt-1 text-sm text-text-muted">Compact reference for the qualification being reviewed.</p>
                </div>
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent/15 text-accent-deep">
                  <ClipboardCheck class="h-5 w-5" aria-hidden="true" />
                </span>
              </div>

              <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div
                  v-for="fact in qualificationFacts"
                  :key="fact.label"
                  class="min-w-0 rounded-xl border border-border/60 bg-surface-muted/35 px-4 py-3"
                >
                  <dt class="text-[11px] font-bold uppercase tracking-wider text-text-muted">{{ fact.label }}</dt>
                  <dd class="mt-1 break-words text-sm font-semibold text-text-primary">{{ fact.value }}</dd>
                </div>
              </dl>

              <details
                v-if="qualification.certificate_template?.requires_subjects"
                class="group mt-4 rounded-xl border border-border/70 bg-surface-muted/25"
              >
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Subject results</div>
                    <div class="mt-1 text-xs text-text-muted">Expand to review captured subjects for this school-level qualification.</div>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-border/70 bg-surface px-2.5 py-0.5 text-[11px] font-semibold text-text-primary">
                      {{ subjectResultsCount }} subject{{ subjectResultsCount === 1 ? '' : 's' }}
                    </span>
                    <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                  </div>
                </summary>
                <div class="border-t border-border/60 px-4 py-4">
                  <div
                    v-if="qualification.subject_results?.length"
                    class="overflow-hidden rounded-xl border border-border/70"
                  >
                    <table class="min-w-full divide-y divide-border/70 text-sm">
                      <thead class="bg-surface">
                        <tr class="text-left text-[11px] font-bold uppercase tracking-wider text-text-muted">
                          <th class="px-4 py-3">#</th>
                          <th class="px-4 py-3">Subject</th>
                          <th class="px-4 py-3">Grade</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-border/60 bg-white/70">
                        <tr
                          v-for="subject in qualification.subject_results"
                          :key="subject.id"
                          class="align-top"
                        >
                          <td class="px-4 py-3 font-medium text-text-muted">{{ subject.index }}</td>
                          <td class="px-4 py-3 font-semibold text-text-primary">{{ subject.subject_name || '—' }}</td>
                          <td class="px-4 py-3 text-text-primary">{{ subject.grade || '—' }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  <div
                    v-else
                    class="rounded-xl border border-dashed border-border bg-surface px-4 py-5 text-sm text-text-muted"
                  >
                    No subject results have been captured for this qualification yet.
                  </div>
                </div>
              </details>
          </section>

          <section class="rounded-2xl border border-border/70 bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h2 class="text-base font-bold tracking-tight text-text-primary">Decision summary</h2>
                <p class="mt-1 text-sm text-text-muted">Key reviewer inputs first, with supporting detail below when needed.</p>
              </div>
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/15 text-sky-700">
                <CornerDownLeft class="h-5 w-5" aria-hidden="true" />
              </span>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[1.15fr_0.85fr]">
              <div class="rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
                <div class="flex flex-wrap items-center gap-2">
                  <span
                    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
                    :class="
                      state === 'under_level2_review' || workflowActiveStep >= 2
                        ? 'border-emerald-300/40 bg-emerald-500/15 text-emerald-900'
                        : 'border-border/70 bg-surface text-text-muted'
                    "
                  >
                    {{ state === 'under_level2_review' || workflowActiveStep >= 2 ? 'Ready for Level 2 decision' : 'Waiting for Level 1 recommendation' }}
                  </span>
                  <span v-if="level1ReviewedAt" class="text-[11px] text-text-muted">
                    Submitted {{ formatDateTime(level1ReviewedAt) }}
                  </span>
                  <span v-if="qualification.assigned_verifier_name" class="text-[11px] text-text-muted">
                    · Verifier <span class="font-semibold text-text-primary">{{ qualification.assigned_verifier_name }}</span>
                  </span>
                </div>

                <div v-if="hasLevel1Findings" class="mt-3">
                  <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Level 1 recommendation</div>
                  <div class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-text-primary">
                    {{ qualification.reviewer_notes }}
                  </div>
                </div>
                <div v-else class="mt-3 text-sm text-text-muted">
                  No Level 1 recommendation submitted yet.
                </div>

                <div v-if="level1Attachment" class="mt-4 rounded-xl border border-border/70 bg-surface px-3 py-3">
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                      <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Attachment</div>
                      <div class="mt-1 truncate text-sm font-semibold text-text-primary">{{ level1Attachment.original_name }}</div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <a :href="level1Attachment.preview_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Preview</a>
                      <a :href="level1Attachment.download_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Download</a>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-if="
                  can.issue_certificate &&
                    (qualification.can_issue_cveq_certificate ||
                      qualification.cveq_certificate ||
                      qualification.can_reissue_cveq_certificate)
                "
                class="rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4"
              >
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Certificate readiness</div>
                <div class="mt-2 text-sm font-semibold text-text-primary">
                  {{ qualification.can_issue_cveq_certificate ? 'Eligible to issue certificate' : 'Certificate not ready yet' }}
                </div>
                <p class="mt-2 text-xs leading-relaxed text-text-muted">
                  Issue the Certificate of Verification and Evaluation of Qualification once approval and payment conditions are satisfied.
                </p>
                <p v-if="qualification.application?.payment_satisfied === false" class="mt-3 text-xs font-medium text-amber-900">
                  Payment is not satisfied — certificate issuance is blocked until fees are covered.
                </p>
                <div
                  v-if="qualification.certificate_template"
                  class="mt-3 rounded-xl border border-border/70 bg-surface px-3 py-3 text-xs text-text-muted"
                >
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-text-primary">Template:</span>
                    <span class="zaqa-badge" :class="qualification.certificate_template.key === 'school_subjects' ? 'zaqa-badge-info' : 'zaqa-badge-secondary'">
                      {{ qualification.certificate_template.label }}
                    </span>
                    <span v-if="qualification.certificate_template.requires_subjects">
                      · {{ qualification.certificate_template.subject_count ?? 0 }} subject{{ (qualification.certificate_template.subject_count ?? 0) === 1 ? '' : 's' }}
                    </span>
                  </div>
                  <p v-if="qualification.certificate_template.warning" class="mt-2 text-xs font-medium text-amber-900">
                    {{ qualification.certificate_template.warning }}
                  </p>
                </div>
                <div
                  v-if="qualification.cveq_certificate?.certificate_number"
                  class="mt-3 rounded-xl border border-border/70 bg-surface px-3 py-3 text-xs text-text-muted"
                >
                  <span class="font-semibold text-text-primary">Active certificate:</span>
                  {{ qualification.cveq_certificate.certificate_number }}
                  <span v-if="qualification.cveq_certificate.issued_at" class="ml-1">
                    · Issued {{ formatTimelineAt(qualification.cveq_certificate.issued_at) }}
                  </span>
                </div>
              </div>
            </div>
          </section>

          <section class="rounded-2xl border border-border/70 bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h2 class="text-base font-bold tracking-tight text-text-primary">Auto-verification result</h2>
                <p class="mt-1 text-sm text-text-muted">Internal match against ZAQA learner achievement records only. Review the match outcome first. Expand the lower sections only when deeper evidence is needed.</p>
              </div>
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
                <Sparkles class="h-5 w-5" aria-hidden="true" />
              </span>
            </div>

            <div v-if="isAutoVerifiedPendingL2" class="mt-4 rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Level 2 review lock</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    <span v-if="lockIsActive">Locked by {{ level2Lock.locked_by_name || '—' }}</span>
                    <span v-else>Unlocked</span>
                  </div>
                  <div v-if="lockIsActive" class="mt-1 text-xs text-text-muted">Expires at {{ formatTimelineAt(level2Lock.expires_at) }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <button
                    v-if="isLevel2Viewer && canAcquireLock"
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                    @click="lockForReview"
                  >
                    {{ lockIsActive ? 'Take over lock' : 'Start review' }}
                  </button>
                  <button
                    v-if="isLevel2Viewer && canReleaseLock"
                    type="button"
                    class="zaqa-btn zaqa-btn-ghost h-9 px-3 py-2 text-xs"
                    @click="unlockReview"
                  >
                    Release lock
                  </button>
                </div>
              </div>

              <div v-if="lockBlocksActions" class="mt-3 rounded-xl border border-amber-300/40 bg-amber-500/10 px-4 py-3 text-xs text-text-primary">
                This qualification is currently being reviewed by {{ level2Lock.locked_by_name || 'another officer' }}. Level 2 actions are disabled until the lock expires or is released.
              </div>
              <div v-else-if="lockMissingForActions" class="mt-3 rounded-xl border border-border/70 bg-surface px-4 py-3 text-xs text-text-muted">
                Start review to lock this qualification before approving, rejecting, sending back, or routing to manual review.
              </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div class="rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Confidence</div>
                <div class="mt-2 text-xl font-bold tracking-tight text-text-primary">
                  <span v-if="autoConfidence != null">{{ autoConfidence }}%</span>
                  <span v-else class="text-text-muted">—</span>
                </div>
              </div>
              <div class="rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Match status</div>
                <div class="mt-2 text-sm font-semibold text-text-primary">{{ autoStatus || '—' }}</div>
                <div v-if="['ambiguous', 'possible_match'].includes(autoStatus)" class="mt-2 text-xs text-amber-900">
                  Warning: match is not definitive.
                </div>
              </div>
              <div class="rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Source</div>
                <div class="mt-2 text-sm font-semibold text-text-primary">{{ qualification.auto_verification?.source || 'ZAQA learner achievement records' }}</div>
                <div class="mt-1 text-[11px] text-text-muted">Auto-verification checks ZAQA learner achievement records only.</div>
                <div class="mt-1 text-[11px] text-text-muted">Attempted {{ formatTimelineAt(qualification.auto_verification?.attempted_at) }}</div>
              </div>
              <div class="rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Recommendation</div>
                <div class="mt-2 text-sm font-semibold text-text-primary">{{ autoRecommendation || '—' }}</div>
              </div>
            </div>

            <div v-if="canManageRetryActions || canViewLearnerRecords" class="mt-4 rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
              <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Admin action strip</div>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-if="canManageRetryActions"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl border border-violet-300/40 bg-violet-500/10 px-3.5 py-2 text-sm font-semibold text-violet-900 transition hover:bg-violet-500/15 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="recheckAutoVerificationForm.processing || !qualification.recheck_auto_verification_enabled"
                    :title="qualification.recheck_auto_verification_disabled_reason || ''"
                    @click="queueAutoVerificationRecheck"
                  >
                    <Sparkles class="h-4 w-4" aria-hidden="true" />
                    {{ recheckAutoVerificationForm.processing ? 'Queueing…' : 'Recheck auto-verification' }}
                  </button>

                  <button
                    v-if="canManageRetryActions"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl border border-sky-300/40 bg-sky-500/10 px-3.5 py-2 text-sm font-semibold text-sky-900 transition hover:bg-sky-500/15 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="autoAssignLevel1Form.processing || !qualification.auto_assign_level1_enabled"
                    :title="qualification.auto_assign_level1_disabled_reason || ''"
                    @click="retryAutoAssignLevel1"
                  >
                    <ArrowRight class="h-4 w-4" aria-hidden="true" />
                    {{ autoAssignLevel1Form.processing ? 'Retrying…' : 'Auto-assign Level 1' }}
                  </button>

                  <Link
                    v-if="canViewLearnerRecords && qualification.learner_records_url"
                    :href="qualification.learner_records_url"
                    class="inline-flex items-center gap-2 rounded-xl border border-teal-300/40 bg-teal-500/10 px-3.5 py-2 text-sm font-semibold text-teal-900 transition hover:bg-teal-500/15"
                  >
                    <FileStack class="h-4 w-4" aria-hidden="true" />
                    View learner records
                  </Link>
                  <button
                    v-else-if="canViewLearnerRecords"
                    type="button"
                    disabled
                    class="inline-flex cursor-not-allowed items-center gap-2 rounded-xl border border-teal-300/30 bg-teal-500/10 px-3.5 py-2 text-sm font-semibold text-teal-900 opacity-60"
                    :title="qualification.learner_records_disabled_reason || ''"
                  >
                    <FileStack class="h-4 w-4" aria-hidden="true" />
                    View learner records
                  </button>
                </div>
              </div>

              <div v-if="qualification.recheck_auto_verification_disabled_reason || qualification.auto_assign_level1_disabled_reason || qualification.learner_records_disabled_reason" class="mt-3 space-y-1 text-xs text-text-muted">
                <p v-if="qualification.recheck_auto_verification_disabled_reason">{{ qualification.recheck_auto_verification_disabled_reason }}</p>
                <p v-if="qualification.auto_assign_level1_disabled_reason">{{ qualification.auto_assign_level1_disabled_reason }}</p>
                <p v-if="canViewLearnerRecords && qualification.learner_records_disabled_reason">{{ qualification.learner_records_disabled_reason }}</p>
              </div>
            </div>

            <div class="mt-4 space-y-3">
              <details class="group rounded-xl border border-border/70 bg-surface-muted/25">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Titles comparison</div>
                    <div class="mt-1 text-xs text-text-muted">Applicant, other title entry, and verified title.</div>
                  </div>
                  <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="border-t border-border/60 px-4 py-4">
                  <div class="grid gap-3 sm:grid-cols-3">
                    <div v-for="row in titleComparisonRows" :key="row.label" class="rounded-xl border border-border/70 bg-surface px-4 py-3">
                      <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">{{ row.label }}</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ row.value }}</div>
                      <div v-if="row.meta" class="mt-1 text-xs text-text-muted">{{ row.meta }}</div>
                    </div>
                  </div>
                </div>
              </details>

              <details class="group rounded-xl border border-border/70 bg-surface-muted/25">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Matched fields</div>
                    <div class="mt-1 text-xs text-text-muted">Quick view of what matched between the applicant data and learner records.</div>
                  </div>
                  <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="border-t border-border/60 px-4 py-4">
                  <div class="flex flex-wrap gap-2">
                    <span
                      v-for="item in matchedFieldItems"
                      :key="item.key"
                      class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold"
                      :class="matchedStateClass(item.matched)"
                    >
                      <span>{{ item.label }}</span>
                      <span class="opacity-75">{{ matchedStateLabel(item.matched) }}</span>
                    </span>
                  </div>
                </div>
              </details>

              <details class="group rounded-xl border border-border/70 bg-surface-muted/25">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Evidence comparison</div>
                    <div class="mt-1 text-xs text-text-muted">Compare applicant values with the matched learner record.</div>
                  </div>
                  <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="border-t border-border/60 px-4 py-4">
                  <div class="overflow-hidden rounded-xl border border-border/70">
                    <table class="min-w-full text-sm">
                      <thead class="bg-surface-muted/80 text-left text-[11px] font-bold uppercase tracking-wider text-text-muted">
                        <tr>
                          <th class="px-4 py-3">Field</th>
                          <th class="px-4 py-3">Applicant value</th>
                          <th class="px-4 py-3">Learner record value</th>
                          <th class="px-4 py-3">Result</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-border/60 bg-surface">
                        <tr v-for="row in evidenceRows" :key="row.key">
                          <td class="px-4 py-3 font-semibold text-text-primary">{{ row.field }}</td>
                          <td class="px-4 py-3 text-text-primary">{{ displayValue(row.applicant) }}</td>
                          <td class="px-4 py-3 text-text-primary">{{ displayValue(row.record) }}</td>
                          <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold" :class="matchedStateClass(row.matched)">
                              {{ matchedStateLabel(row.matched) }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </details>

              <details v-if="qualification.learner_record" class="group rounded-xl border border-border/70 bg-surface-muted/25">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Matched learner record</div>
                    <div class="mt-1 text-xs text-text-muted">Expand to inspect the linked record metadata.</div>
                  </div>
                  <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="border-t border-border/60 px-4 py-4">
                  <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-border/70 bg-surface px-4 py-3">
                      <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Program</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ qualification.learner_record.program_of_study || '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-border/70 bg-surface px-4 py-3">
                      <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Institution</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ qualification.learner_record.awarding_institution?.name || qualification.learner_record.institution_name_raw || '—' }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 text-sm text-text-primary">
                      <span class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Student ID</span>
                      <div class="mt-1 font-mono">{{ qualification.learner_record.student_id || '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 text-sm text-text-primary">
                      <span class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Certificate #</span>
                      <div class="mt-1 font-mono">{{ qualification.learner_record.certificate_no || '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 text-sm text-text-primary">
                      <span class="text-[11px] font-bold uppercase tracking-wider text-text-muted">NRC</span>
                      <div class="mt-1 font-mono">{{ qualification.learner_record.nrc_number || '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-border/70 bg-surface px-4 py-3 text-sm text-text-primary">
                      <span class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Passport</span>
                      <div class="mt-1 font-mono">{{ qualification.learner_record.passport_no || '—' }}</div>
                    </div>
                  </div>
                </div>
              </details>

              <details v-if="qualification.match_attempts?.length" class="group rounded-xl border border-border/70 bg-surface-muted/25">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Match attempt history</div>
                    <div class="mt-1 text-xs text-text-muted">Historical auto-match runs and their outcomes.</div>
                  </div>
                  <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="border-t border-border/60 px-4 py-4">
                  <div class="overflow-hidden rounded-xl border border-border/70">
                    <table class="min-w-full text-sm">
                      <thead class="bg-surface-muted/80 text-left text-[11px] font-bold uppercase tracking-wider text-text-muted">
                        <tr>
                          <th class="px-4 py-3">When</th>
                          <th class="px-4 py-3">Status</th>
                          <th class="px-4 py-3">Confidence</th>
                          <th class="px-4 py-3">Source</th>
                          <th class="px-4 py-3">Failure</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-border/60 bg-surface">
                        <tr v-for="a in qualification.match_attempts" :key="a.id" class="transition hover:bg-surface-muted/40">
                          <td class="px-4 py-3 text-xs text-text-muted">{{ formatTimelineAt(a.created_at) }}</td>
                          <td class="px-4 py-3 font-semibold text-text-primary">{{ a.status ?? '—' }}</td>
                          <td class="px-4 py-3 text-text-primary">
                            <span v-if="a.confidence != null">{{ a.confidence }}%</span>
                            <span v-else class="text-text-muted">—</span>
                          </td>
                          <td class="px-4 py-3 text-text-primary">{{ a.source ?? '—' }}</td>
                          <td class="px-4 py-3 text-xs text-text-muted">{{ a.failure_reason ?? '—' }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </details>
            </div>
          </section>

          <details class="group rounded-2xl border border-border/70 bg-surface shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 [&::-webkit-details-marker]:hidden">
              <div>
                <h2 class="text-base font-bold tracking-tight text-text-primary">Documents</h2>
                <p class="mt-1 text-sm text-text-muted">Evidence attached to this qualification item.</p>
              </div>
              <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/45 px-2.5 py-0.5 text-[11px] font-semibold text-text-primary">
                  {{ documentsCount }}
                </span>
                <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
              </div>
            </summary>
            <div class="border-t border-border/60 px-5 py-4">
              <div v-if="qualification.documents?.length" class="overflow-hidden rounded-xl border border-border/80">
                <table class="min-w-full text-sm">
                  <thead class="bg-surface-muted/90 text-left text-[11px] font-bold uppercase tracking-wider text-text-muted">
                    <tr>
                      <th class="px-4 py-3">Type</th>
                      <th class="px-4 py-3">File</th>
                      <th class="px-4 py-3">Version</th>
                      <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-border/60 bg-surface">
                    <tr v-for="d in qualification.documents" :key="d.id" class="transition hover:bg-surface-muted/40">
                      <td class="px-4 py-3 font-medium text-text-primary">{{ documentTypeLabel(d.document_type) }}</td>
                      <td class="px-4 py-3 text-text-primary">{{ d.original_name }}</td>
                      <td class="px-4 py-3 tabular-nums text-text-muted">v{{ d.version_number }}</td>
                      <td class="px-4 py-3 text-right">
                        <a :href="d.preview_url" class="zaqa-btn zaqa-btn-secondary mr-1 inline-flex h-9 items-center px-3 py-2 text-xs">Preview</a>
                        <a :href="d.download_url" class="zaqa-btn zaqa-btn-secondary inline-flex h-9 items-center px-3 py-2 text-xs">Download</a>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-else class="rounded-xl border border-dashed border-border bg-surface-muted/40 px-4 py-5 text-sm text-text-muted">
                No documents uploaded for this qualification item yet.
              </div>
            </div>
          </details>
        </div>

        <aside class="space-y-4 lg:col-span-4">
          <section class="rounded-2xl border border-border/70 bg-surface p-5 shadow-sm">
            <h2 class="text-sm font-bold tracking-tight text-text-primary">Two-level workflow</h2>
            <p class="mt-1 text-xs leading-relaxed text-text-muted">
              Level 2 assigns and oversees; Level 1 performs desk review on this task.
            </p>
            <ol class="mt-4 space-y-0">
              <li
                v-for="(step, idx) in workflowSteps"
                :key="step.key"
                class="relative flex gap-3 pb-4 last:pb-0"
              >
                <div
                  v-if="idx < workflowSteps.length - 1"
                  class="absolute left-[15px] top-8 h-[calc(100%-0.25rem)] w-px bg-border"
                  aria-hidden="true"
                />
                <div
                  class="relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold"
                  :class="
                    workflowActiveStep === idx
                      ? 'border-brand bg-brand text-white shadow-sm'
                      : workflowActiveStep > idx
                        ? 'border-success/40 bg-success/10 text-success'
                        : 'border-border bg-surface-muted text-text-muted'
                  "
                >
                  {{ idx + 1 }}
                </div>
                <div class="min-w-0 pt-0.5">
                  <div class="text-sm font-semibold text-text-primary">{{ step.label }}</div>
                  <div class="text-xs text-text-muted">{{ step.sub }}</div>
                </div>
              </li>
            </ol>
            <div
              v-if="workflowActiveStep === -1"
              class="mt-4 rounded-lg border border-amber-300/50 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-950"
            >
              Task is with the applicant for amendment.
            </div>
          </section>

          <section class="rounded-2xl border border-border/70 bg-surface p-5 shadow-sm">
            <div class="flex items-center gap-2 text-sm font-bold text-text-primary">
              <UserRound class="h-4 w-4 text-brand" aria-hidden="true" />
              Assignment
            </div>
            <p class="mt-1 text-xs text-text-muted">Current task owner, status, and assignment history.</p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
              <div class="rounded-xl border border-border/70 bg-surface-muted/35 px-4 py-3">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Level 1 owner</div>
                <div v-if="ownerLine.name" class="mt-1 text-sm font-semibold text-text-primary">{{ ownerLine.name }}</div>
                <div v-else class="mt-1 text-sm font-medium italic text-text-muted">Not assigned</div>
                <div v-if="qualification.assigned_at" class="mt-1 text-[11px] text-text-muted">
                  Assigned {{ formatTimelineAt(qualification.assigned_at) }}
                </div>
              </div>

              <div class="rounded-xl border border-border/70 bg-surface-muted/35 px-4 py-3">
                <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Task status</div>
                <div class="mt-1 inline-flex items-center rounded-full border border-border/70 bg-surface px-2.5 py-1 text-[11px] font-semibold text-text-primary">
                  {{ stateDisplay }}
                </div>
                <p v-if="qualification.returned_to_applicant_at" class="mt-2 text-[11px] leading-relaxed text-amber-900">
                  With applicant since {{ formatTimelineAt(qualification.returned_to_applicant_at) }}
                </p>
              </div>
            </div>

            <details v-if="qualification.assignments?.length" class="group mt-4 rounded-xl border border-border/70 bg-surface-muted/25">
              <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                <div>
                  <div class="text-sm font-semibold text-text-primary">Assignment history</div>
                  <div class="mt-1 text-xs text-text-muted">Expand to review previous assignment moves.</div>
                </div>
                <div class="flex items-center gap-2">
                  <span class="inline-flex items-center rounded-full border border-border/70 bg-surface px-2.5 py-0.5 text-[11px] font-semibold text-text-primary">
                    {{ qualification.assignments.length }}
                  </span>
                  <ChevronDown class="h-4 w-4 text-text-muted transition group-open:rotate-180" aria-hidden="true" />
                </div>
              </summary>
              <div class="border-t border-border/60 px-4 py-4">
                <ul class="space-y-3">
                  <li
                    v-for="a in qualification.assignments"
                    :key="a.id"
                    class="rounded-xl border border-border/60 bg-surface px-3 py-2.5 text-sm"
                  >
                    <div class="text-[11px] font-medium text-text-muted">{{ formatTimelineAt(a.assigned_at) }}</div>
                    <div class="mt-0.5 font-medium text-text-primary">{{ a.assigned_by }} → {{ a.assigned_to }}</div>
                    <div v-if="a.comment" class="mt-1.5 border-t border-border/50 pt-1.5 text-xs text-text-muted">{{ a.comment }}</div>
                  </li>
                </ul>
              </div>
            </details>
          </section>

          <section class="rounded-2xl border border-border/70 bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-sm font-semibold text-text-primary">Qualification SLA snapshot</div>
                <div class="mt-1 text-xs text-text-muted">
                  Qualification-scoped timing for operational review.
                </div>
              </div>
              <div class="text-right">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">SLA</div>
                <div
                  class="mt-1 inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold"
                  :class="
                    !slaClockActive
                      ? 'border-border bg-surface-muted text-text-muted'
                      : displayIsOverdue
                        ? 'border-red-300/40 bg-red-500/15 text-red-900'
                        : 'border-emerald-300/40 bg-emerald-500/15 text-emerald-900'
                  "
                >
                  <Timer class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                  <span v-if="!deadlineAt">—</span>
                  <span v-else-if="!slaClockActive">Closed</span>
                  <span v-else-if="displayIsOverdue">Overdue by {{ formatDuration(displayOverdueByMs) }}</span>
                  <span v-else>Due in {{ formatDuration(displayDueInMs) }}</span>
                </div>
              </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
              <div class="rounded-xl border border-border bg-surface-muted p-4">
                <div class="flex items-center justify-between">
                  <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification SLA age</div>
                  <Clock class="h-4 w-4 text-text-muted" aria-hidden="true" />
                </div>
                <div class="mt-2 text-lg font-semibold text-text-primary">{{ formatDuration(ageMs) }}</div>
                <div class="mt-1 text-xs text-text-muted">
                  Since {{ formatDateTime(slaStartedAt) }}
                </div>
              </div>

              <div class="rounded-xl border border-border bg-surface-muted p-4">
                <div class="flex items-center justify-between">
                  <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Since Level 1 assignment</div>
                  <Timer class="h-4 w-4 text-text-muted" aria-hidden="true" />
                </div>
                <div class="mt-2 text-lg font-semibold text-text-primary">
                  {{ latestAssignmentAt ? formatDuration(sinceAssignedMs) : '—' }}
                </div>
                <div class="mt-1 text-xs text-text-muted">
                  {{ latestAssignmentAt ? `Assigned ${formatDateTime(latestAssignmentAt)}` : 'Not assigned yet' }}
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
              Service target window is closed for this qualification; countdown and progress are not shown.
            </p>
          </section>
        </aside>
      </div>
      </div>
    </div>

    <AdminActionModal v-model="assignOpen" title="Assign Level 1 reviewer" description="Only Level 2 can assign qualification tasks to Level 1.">
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Assign to</label>
          <select v-model="assignForm.assigned_to_user_id" class="zaqa-input mt-2">
            <option value="" disabled>Select reviewer…</option>
            <option v-for="u in level1Users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <div v-if="assignForm.errors.assigned_to_user_id" class="mt-1 text-xs text-danger">{{ assignForm.errors.assigned_to_user_id }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
          <textarea v-model="assignForm.comment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional internal comment" />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="assignOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="assignForm.processing"
          @click="
            assignForm.post(`/admin/verification/qualifications/${qualification.id}/assign`, {
              preserveScroll: true,
              onSuccess: () => {
                assignOpen = false
              },
            })
          "
        >
          Save
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="revokeOpen"
      title="Remove Level 1 assignment"
      description="The task returns to the verification pool as awaiting assignment. The previous Level 1 officer will no longer see this qualification until someone is assigned again."
    >
      <div>
        <label class="text-sm font-semibold text-text-primary">Internal note (optional)</label>
        <textarea
          v-model="revokeForm.comment"
          class="zaqa-input mt-2 h-auto min-h-[6rem] py-3"
          placeholder="Optional reason for auditors (shown on the application record)."
        />
        <div v-if="revokeForm.errors.comment" class="mt-1 text-xs text-danger">{{ revokeForm.errors.comment }}</div>
        <div v-if="revokeForm.errors.qualification" class="mt-1 text-xs text-danger">{{ revokeForm.errors.qualification }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="revokeOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn border border-rose-400/40 bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700"
          :disabled="revokeForm.processing"
          @click="
            revokeForm.post(`/admin/verification/qualifications/${qualification.id}/revoke-assignment`, {
              preserveScroll: true,
              onSuccess: () => {
                revokeOpen = false
                revokeForm.reset()
              },
            })
          "
        >
          Remove assignment
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="sendBackOpen"
      title="Send qualification back to applicant"
      description="Only this qualification is returned for amendment. The applicant will receive your comment and can update this item without reopening the whole application."
    >
      <div>
        <label class="text-sm font-semibold text-text-primary">Comment</label>
        <textarea v-model="sendBackForm.comment" class="zaqa-input mt-2 h-auto min-h-[8rem] py-3" placeholder="Explain what must be corrected for this qualification." />
        <div v-if="sendBackForm.errors.comment" class="mt-1 text-xs text-danger">{{ sendBackForm.errors.comment }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="sendBackOpen = false">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="sendBackForm.processing"
          @click="
            sendBackForm.post(`/admin/verification/qualifications/${qualification.id}/send-back`, {
              preserveScroll: true,
              onSuccess: () => (sendBackOpen = false),
            })
          "
        >
          Send back
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="sendBackHistoryOpen"
      title="Return & resubmission history"
      description="This qualification was returned to the applicant at least once. Officer feedback is retained even after resubmission."
    >
      <div v-if="sendBackTimeline.length === 0" class="text-sm text-text-muted">
        No history recorded for this qualification.
      </div>
      <div v-else class="max-h-[60vh] overflow-auto pr-1">
        <ol class="space-y-3">
          <li v-for="(row, idx) in sendBackTimeline" :key="`${row.kind}-${row.at}-${idx}`">
            <div class="rounded-xl border border-border bg-surface-muted/30 px-4 py-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                  <span
                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-bold"
                    :class="
                      row.kind === 'send_back'
                        ? 'border-amber-400/60 bg-amber-50 text-amber-950'
                        : 'border-emerald-400/60 bg-emerald-50 text-emerald-950'
                    "
                  >
                    <RotateCcw v-if="row.kind === 'send_back'" class="mr-1 h-3.5 w-3.5" aria-hidden="true" />
                    <CornerDownLeft v-else class="mr-1 h-3.5 w-3.5" aria-hidden="true" />
                    {{ row.kind === 'send_back' ? 'Returned to applicant' : 'Resubmitted' }}
                  </span>
                  <span v-if="row.author_name" class="text-xs text-text-muted">
                    <span class="font-semibold text-text-primary">{{ row.author_name }}</span>
                  </span>
                </div>
                <div class="text-xs font-medium text-text-muted">{{ formatTimelineAt(row.at) }}</div>
              </div>

              <div v-if="row.kind === 'send_back' && row.body && row.body.trim().length > 0" class="mt-3 whitespace-pre-wrap text-sm text-text-primary">
                {{ row.body }}
              </div>

              <div v-if="row.kind !== 'send_back' && (row.title || row.description)" class="mt-3 text-sm text-text-primary">
                <div v-if="row.title" class="font-semibold">{{ row.title }}</div>
                <div v-if="row.description" class="mt-1 text-text-muted">{{ row.description }}</div>
              </div>
            </div>
          </li>
        </ol>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="sendBackHistoryOpen = false">
          Close
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="level1CompleteOpen" title="Mark Level 1 review complete" description="Your findings stay with this qualification and move the task to Level 2 review.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Findings</label>
        <textarea v-model="level1CompleteForm.findings" class="zaqa-input mt-2 h-auto min-h-[10rem] py-3" placeholder="Summarize checks, issues, and recommendation." />
        <div v-if="level1CompleteForm.errors.findings" class="mt-1 text-xs text-danger">{{ level1CompleteForm.errors.findings }}</div>
      </div>
      <div class="mt-4">
        <label class="text-sm font-semibold text-text-primary">Attachment (optional)</label>
        <p class="mt-1 text-xs text-text-secondary">Upload a supporting file (PDF, Word, or image) for Level 2 — max 10&nbsp;MB.</p>
        <input
          ref="level1AttachmentInput"
          type="file"
          class="zaqa-input mt-2"
          accept=".pdf,.doc,.docx,image/jpeg,image/png,image/gif,image/webp"
          @change="
            (e) => {
              const t = e.target as HTMLInputElement
              level1CompleteForm.attachment = t.files?.[0] ?? null
            }
          "
        />
        <div v-if="level1CompleteForm.errors.attachment" class="mt-1 text-xs text-danger">{{ level1CompleteForm.errors.attachment }}</div>
      </div>
      <template #footer>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          @click="
            () => {
              level1CompleteOpen = false
              level1CompleteForm.clearErrors()
              level1CompleteForm.reset()
              clearLevel1Attachment()
            }
          "
        >
          Cancel
        </button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="level1CompleteForm.processing"
          @click="
            level1CompleteForm.post(`/admin/verification/qualifications/${qualification.id}/level1-complete`, {
              preserveScroll: true,
              forceFormData: true,
              onSuccess: () => {
                level1CompleteOpen = false
                level1CompleteForm.reset()
                clearLevel1Attachment()
              },
            })
          "
        >
          Submit
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="approveOpen"
      title="Approve qualification"
      description="Optional internal comment. Qualification will be marked approved for certificate."
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
          <textarea v-model="approveForm.comment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional internal note." />
          <div v-if="approveForm.errors.comment" class="mt-1 text-xs text-danger">{{ approveForm.errors.comment }}</div>
        </div>

        <div v-if="can.issue_certificate" class="rounded-xl border border-border/70 bg-surface-muted/40 px-4 py-3">
          <label class="flex items-start gap-2 text-sm font-semibold text-text-primary">
            <input
              v-model="approveForm.issue_certificate"
              type="checkbox"
              class="mt-0.5"
              :disabled="qualification.application?.payment_satisfied === false"
            />
            Generate certificate of Recognition
          </label>
          <p class="mt-1 text-xs text-text-muted">
            Payment must be satisfied. This will email the applicant and mark the qualification as certificate issued.
          </p>
          <p v-if="qualification.application?.payment_satisfied === false" class="mt-2 text-xs font-medium text-amber-900">
            Payment is not satisfied — certificate issuance is blocked until fees are covered.
          </p>
          <div v-if="approveForm.errors.issue_certificate" class="mt-1 text-xs text-danger">{{ approveForm.errors.issue_certificate }}</div>
          <div v-if="(approveForm.errors as any).payment" class="mt-1 text-xs text-danger">{{ (approveForm.errors as any).payment }}</div>
          <div v-if="(approveForm.errors as any).application" class="mt-1 text-xs text-danger">{{ (approveForm.errors as any).application }}</div>
          <div v-if="(approveForm.errors as any).qualification" class="mt-1 text-xs text-danger">{{ (approveForm.errors as any).qualification }}</div>
          <div v-if="(approveForm.errors as any).lock" class="mt-1 text-xs text-danger">{{ (approveForm.errors as any).lock }}</div>
        </div>
      </div>
      <template #footer>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          @click="
            () => {
              approveOpen = false
              approveForm.clearErrors()
              approveForm.reset()
            }
          "
        >
          Cancel
        </button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="approveForm.processing"
          @click="
            approveForm.post(`/admin/verification/qualifications/${qualification.id}/approve`, {
              preserveScroll: true,
              onSuccess: () => {
                approveOpen = false
                approveForm.reset()
              },
            })
          "
        >
          Approve
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="rejectOpen" title="Reject qualification" description="Reason is required and will be visible to the applicant.">
      <div>
        <label class="text-sm font-semibold text-text-primary">Reason</label>
        <textarea v-model="rejectForm.reason" class="zaqa-input mt-2 h-auto min-h-[10rem] py-3" placeholder="Provide a clear rejection reason." />
        <div v-if="rejectForm.errors.reason" class="mt-1 text-xs text-danger">{{ rejectForm.errors.reason }}</div>
        <div v-if="(rejectForm.errors as any).qualification" class="mt-1 text-xs text-danger">{{ (rejectForm.errors as any).qualification }}</div>
        <div v-if="(rejectForm.errors as any).lock" class="mt-1 text-xs text-danger">{{ (rejectForm.errors as any).lock }}</div>
      </div>
      <template #footer>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          @click="
            () => {
              rejectOpen = false
              rejectForm.clearErrors()
              rejectForm.reset()
            }
          "
        >
          Cancel
        </button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="rejectForm.processing"
          @click="
            rejectForm.post(`/admin/verification/qualifications/${qualification.id}/reject`, {
              preserveScroll: true,
              onSuccess: () => {
                rejectOpen = false
                rejectForm.reset()
              },
            })
          "
        >
          Reject
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
