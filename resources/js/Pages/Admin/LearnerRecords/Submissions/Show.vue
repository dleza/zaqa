<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import { AlertTriangle, ArrowLeft, ArrowRight, CheckCircle2, Copy, Lock, Unlock, XCircle } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  submission: any
  viewer_user_id?: number | null
  can: { review: boolean; approve: boolean; reject: boolean; is_super_admin?: boolean }
}>()

const isPending = computed(() => props.submission.status === 'pending')
const reviewLock = computed(() => props.submission.review_lock ?? {})
const lockIsActive = computed(() => !!reviewLock.value?.is_locked)
const viewerHasLock = computed(() => {
  if (!props.viewer_user_id) return false
  return lockIsActive.value && Number(reviewLock.value?.locked_by_user_id ?? 0) === Number(props.viewer_user_id)
})
const isSuperAdmin = computed(() => props.can.is_super_admin === true)
const lockBlocksActions = computed(() => isPending.value && lockIsActive.value && !viewerHasLock.value && !isSuperAdmin.value)
const lockMissingForActions = computed(() => isPending.value && !viewerHasLock.value && !isSuperAdmin.value)
const canAcquireLock = computed(() => isPending.value && props.can.review && (!lockIsActive.value || isSuperAdmin.value || viewerHasLock.value))
const canReleaseLock = computed(() => isPending.value && props.can.review && lockIsActive.value && (viewerHasLock.value || isSuperAdmin.value))
const canPerformReviewActions = computed(() => isPending.value && props.can.approve && !lockBlocksActions.value && !lockMissingForActions.value)
const nextSubmissionUrl = computed(() => {
  const nextId = props.submission.next_submission_id
  return nextId ? `/admin/learner-records/submissions/${nextId}` : null
})

function formatLockExpiry(value?: string | null) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

function startReview() {
  router.post(props.submission.start_review_url, {}, { preserveScroll: true })
}

function releaseReview() {
  router.post(props.submission.release_review_url, {}, { preserveScroll: true })
}

async function approveNew() {
  const result = await Swal.fire({
    icon: 'question',
    title: 'Approve as new learner record?',
    text: 'This will create a trusted learner achievement record.',
    showCancelButton: true,
    confirmButtonText: 'Approve as new',
    confirmButtonColor: '#15803D',
  })
  if (!result.isConfirmed) return

  router.post(`/admin/learner-records/submissions/${props.submission.id}/approve`, {
    decision: 'approve_new',
    review_notes: null,
  })
}

async function approveUpdate() {
  const candidates = (props.submission.duplicate_candidates ?? []).filter((c: any) => c.learner_record_id)
  const options: Record<string, string> = {}
  for (const c of candidates) {
    const s = c.summary ?? {}
    options[String(c.learner_record_id)] = `#${c.learner_record_id} — ${s.first_name ?? ''} ${s.last_name ?? ''} (${s.student_id ?? s.certificate_no ?? 'no id'})`
  }

  const result = await Swal.fire({
    icon: 'question',
    title: 'Approve as update to existing record',
    input: candidates.length ? 'select' : 'number',
    inputLabel: 'Target learner record ID',
    inputOptions: candidates.length ? options : undefined,
    inputPlaceholder: candidates.length ? undefined : 'Enter learner record ID',
    showCancelButton: true,
    confirmButtonText: 'Approve update',
    confirmButtonColor: '#15803D',
    preConfirm: (value) => {
      if (!value) {
        Swal.showValidationMessage('Target learner record is required.')
      }
      return value
    },
  })
  if (!result.isConfirmed) return

  router.post(`/admin/learner-records/submissions/${props.submission.id}/approve`, {
    decision: 'approve_update',
    target_learner_record_id: Number(result.value),
    review_notes: null,
    allow_overwrite: false,
  })
}

async function rejectSubmission() {
  const result = await Swal.fire({
    icon: 'warning',
    title: 'Reject submission?',
    input: 'textarea',
    inputLabel: 'Rejection reason (required)',
    showCancelButton: true,
    confirmButtonText: 'Reject',
    confirmButtonColor: '#B42318',
    preConfirm: (value) => {
      if (!value || value.toString().trim().length === 0) {
        Swal.showValidationMessage('Rejection reason is required.')
      }
      return value
    },
  })
  if (!result.isConfirmed) return

  router.post(`/admin/learner-records/submissions/${props.submission.id}/reject`, {
    review_notes: result.value,
  })
}

async function markDuplicate() {
  const result = await Swal.fire({
    icon: 'warning',
    title: 'Mark as duplicate?',
    input: 'textarea',
    inputLabel: 'Reason (required)',
    showCancelButton: true,
    confirmButtonText: 'Mark duplicate',
    confirmButtonColor: '#B42318',
    preConfirm: (value) => {
      if (!value || value.toString().trim().length === 0) {
        Swal.showValidationMessage('Reason is required.')
      }
      return value
    },
  })
  if (!result.isConfirmed) return

  const firstCandidate = (props.submission.duplicate_candidates ?? []).find((c: any) => c.learner_record_id)

  router.post(`/admin/learner-records/submissions/${props.submission.id}/mark-duplicate`, {
    review_notes: result.value,
    target_learner_record_id: firstCandidate?.learner_record_id ?? null,
  })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-3">
        <Link href="/admin/learner-records/submissions" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm">
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          Back
        </Link>
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Review submission #{{ submission.id }}</h1>
          <p class="mt-1 text-sm text-text-muted">
            {{ submission.source_institution?.name ?? 'Unknown institution' }} • {{ submission.source_type }} •
            <span class="font-medium">{{ submission.status }}</span>
          </p>
        </div>
      </div>

      <Link
        v-if="nextSubmissionUrl"
        :href="nextSubmissionUrl"
        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm self-start sm:self-auto"
      >
        Next pending
        <ArrowRight class="h-4 w-4" aria-hidden="true" />
      </Link>
    </div>

    <div v-if="isPending && can.review" class="mt-4 rounded-xl border border-border/70 bg-surface-muted/30 px-4 py-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Submission review lock</div>
          <div class="mt-1 text-sm font-semibold text-text-primary">
            <span v-if="lockIsActive">Locked by {{ reviewLock.locked_by_name || '—' }}</span>
            <span v-else>Unlocked</span>
          </div>
          <div v-if="lockIsActive" class="mt-1 text-xs text-text-muted">Expires at {{ formatLockExpiry(reviewLock.expires_at) }}</div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button
            v-if="canAcquireLock"
            type="button"
            class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm"
            @click="startReview"
          >
            <Lock class="h-4 w-4" aria-hidden="true" />
            {{ lockIsActive && viewerHasLock ? 'Extend review lock' : 'Start review' }}
          </button>
          <button
            v-if="canReleaseLock"
            type="button"
            class="zaqa-btn zaqa-btn-ghost inline-flex items-center gap-2 px-3 py-2 text-sm"
            @click="releaseReview"
          >
            <Unlock class="h-4 w-4" aria-hidden="true" />
            Release lock
          </button>
        </div>
      </div>

      <div v-if="lockBlocksActions" class="mt-3 rounded-xl border border-amber-300/40 bg-amber-500/10 px-4 py-3 text-xs text-text-primary">
        This submission is currently being reviewed by {{ reviewLock.locked_by_name || 'another officer' }}. Review actions are disabled until the lock expires or is released.
      </div>
      <div v-else-if="lockMissingForActions" class="mt-3 rounded-xl border border-border/70 bg-surface px-4 py-3 text-xs text-text-muted">
        Start review to lock this submission before approving, rejecting, or marking it as duplicate.
      </div>
    </div>

    <div v-if="(submission.risk_flags?.length ?? 0) > 0 || (submission.duplicate_candidates?.length ?? 0) > 0" class="mt-4 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
      <div class="flex items-start gap-2 font-semibold">
        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        Review warnings
      </div>
      <ul class="mt-2 list-disc pl-5 text-xs">
        <li v-for="flag in submission.risk_flags ?? []" :key="flag">{{ flag }}</li>
        <li v-if="submission.duplicate_candidates?.length">{{ submission.duplicate_candidates.length }} possible duplicate candidate(s) detected</li>
      </ul>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <section class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-text-primary">Submitted identity</h2>
        <dl class="mt-4 grid gap-2 text-sm">
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Name</dt><dd>{{ submission.first_name }} {{ submission.other_names }} {{ submission.last_name }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Student ID</dt><dd>{{ submission.student_id || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Certificate no.</dt><dd>{{ submission.certificate_no || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">NRC</dt><dd>{{ submission.nrc_number || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Passport</dt><dd>{{ submission.passport_no || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Gender</dt><dd>{{ submission.gender || '—' }}</dd></div>
        </dl>
      </section>

      <section class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-text-primary">Qualification</h2>
        <dl class="mt-4 grid gap-2 text-sm">
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Programme</dt><dd>{{ submission.program_of_study || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Year awarded</dt><dd>{{ submission.year_awarded ?? '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Award date</dt><dd>{{ submission.award_date || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Classification</dt><dd>{{ submission.classification || '—' }}</dd></div>
          <div class="flex justify-between gap-4"><dt class="text-text-muted">Source reference</dt><dd>{{ submission.source_reference || '—' }}</dd></div>
        </dl>
      </section>
    </div>

    <section v-if="submission.duplicate_candidates?.length" class="mt-6 rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <h2 class="text-sm font-semibold text-text-primary">Duplicate candidates</h2>
      <div class="mt-4 space-y-3">
        <div v-for="(candidate, idx) in submission.duplicate_candidates" :key="idx" class="rounded-lg border border-border/70 px-4 py-3 text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="font-semibold">{{ candidate.match_type }} • score {{ candidate.score }}</div>
            <div class="text-xs text-text-muted">matched: {{ (candidate.matched_fields ?? []).join(', ') }}</div>
          </div>
          <div class="mt-2 text-xs text-text-muted">
            <span v-if="candidate.learner_record_id">Learner record #{{ candidate.learner_record_id }}</span>
            <span v-else-if="candidate.submission_id">Pending submission #{{ candidate.submission_id }}</span>
          </div>
          <div class="mt-1">{{ candidate.summary?.first_name }} {{ candidate.summary?.last_name }} — {{ candidate.summary?.program_of_study }} ({{ candidate.summary?.year_awarded }})</div>
        </div>
      </div>
    </section>

    <section v-if="Object.keys(submission.payload_summary ?? {}).length" class="mt-6 rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <h2 class="text-sm font-semibold text-text-primary">Payload summary</h2>
      <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-muted p-3 text-xs">{{ JSON.stringify(submission.payload_summary, null, 2) }}</pre>
    </section>

    <section v-if="submission.approved_learner_record" class="mt-6 rounded-2xl border border-success/30 bg-success/5 p-5 text-sm">
      <h2 class="font-semibold text-success">Approved learner record</h2>
      <p class="mt-2">
        Linked to record #{{ submission.approved_learner_record.id }} —
        {{ submission.approved_learner_record.first_name }} {{ submission.approved_learner_record.last_name }}
      </p>
    </section>

    <section v-if="submission.review_notes" class="mt-6 rounded-2xl border border-border bg-surface p-5 text-sm">
      <h2 class="font-semibold text-text-primary">Review notes</h2>
      <p class="mt-2 whitespace-pre-wrap text-text-muted">{{ submission.review_notes }}</p>
      <p v-if="submission.reviewed_by" class="mt-2 text-xs text-text-muted">By {{ submission.reviewed_by.name }} at {{ submission.reviewed_at }}</p>
    </section>

    <div v-if="canPerformReviewActions" class="mt-6 flex flex-wrap gap-2">
      <button type="button" class="zaqa-btn border border-success/20 bg-success/10 px-4 py-2 text-sm font-semibold text-success" @click="approveNew">
        <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
        Approve as new
      </button>
      <button type="button" class="zaqa-btn border border-primary/20 bg-primary/10 px-4 py-2 text-sm font-semibold text-primary" @click="approveUpdate">
        Approve as update
      </button>
      <button v-if="can.reject" type="button" class="zaqa-btn border border-danger/20 bg-danger/10 px-4 py-2 text-sm font-semibold text-danger" @click="rejectSubmission">
        <XCircle class="h-4 w-4" aria-hidden="true" />
        Reject
      </button>
      <button v-if="can.reject" type="button" class="zaqa-btn border border-danger/20 bg-danger/10 px-4 py-2 text-sm font-semibold text-danger" @click="markDuplicate">
        <Copy class="h-4 w-4" aria-hidden="true" />
        Mark duplicate
      </button>
    </div>
  </AdminLayout>
</template>
