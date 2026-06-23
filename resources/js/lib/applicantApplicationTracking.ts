export type TimelineEvent = {
  id: number | string
  event_type?: string
  event_code?: string
  stage?: string
  title?: string
  description?: string | null
  comment?: string | null
  occurred_at?: string | null
  qualification_id?: number | null
  qualification_title?: string | null
  metadata?: Record<string, unknown>
}

export type TrackingApplication = {
  id: number
  application_number?: string | null
  current_status?: string | null
  status_label?: string | null
  display_status_label?: string | null
  correction_required?: boolean
  submitted_at?: string | null
  service_deadline_at?: string | null
  payment?: { status?: string | null; confirmed_at?: string | null } | null
  invoice?: { amount_cents?: number; currency?: string | null } | null
}

export type SelectedQualification = {
  id: number
  title_of_qualification?: string | null
  verification_reference_number?: string | null
  status_label?: string
} | null

export type TrackingStatusKey =
  | 'draft'
  | 'pending_payment'
  | 'awaiting_finance_approval'
  | 'awaiting_assignment'
  | 'under_level1_review'
  | 'awaiting_level2_review'
  | 'under_level2_review'
  | 'under_review'
  | 'returned_for_corrections'
  | 'approved'
  | 'rejected'
  | 'certificate_issued'
  | 'closed'

export type TrackingCopy = {
  key: TrackingStatusKey
  headline: string
  stage: string
  nextStep: string
  whatNext: string
  badgeClass: string
}

const TECHNICAL_EVENT_PREFIXES = ['draft.', 'wizard.']
const TECHNICAL_EVENT_CODES = new Set([
  'payment.method_selected',
  'payment.method_preference_updated',
  'payment.invoice_issued',
  'payment.quotation_issued',
  'payment.supplementary_invoice_issued',
  'payment.manual_proof_uploaded',
  'status.fallback',
])

const TECHNICAL_EVENT_INCLUDES = [
  'payment.card_initiated',
  'payment.mobile_money_initiated',
  'payment.invoice_updated',
  'submission.qualification_amended',
]

export function isTechnicalTimelineEvent(eventCode: string | undefined | null): boolean {
  const code = (eventCode ?? '').toString().trim()
  if (!code) return false
  if (TECHNICAL_EVENT_CODES.has(code)) return true
  if (TECHNICAL_EVENT_PREFIXES.some((prefix) => code.startsWith(prefix))) return true
  return TECHNICAL_EVENT_INCLUDES.some((needle) => code.includes(needle))
}

export function isMilestoneTimelineEvent(eventCode: string | undefined | null): boolean {
  const code = (eventCode ?? '').toString().trim()
  if (!code || isTechnicalTimelineEvent(code)) return false

  if (
    code.startsWith('submission.')
    || code.startsWith('payment.confirmed')
    || code === 'payment.finance_approved'
    || code.startsWith('review.')
    || code.startsWith('decision.')
    || code.startsWith('certificate.')
    || code.startsWith('verification.level1_resubmitted')
  ) {
    return true
  }

  return false
}

export function milestoneDisplayTitle(event: TimelineEvent): string {
  const code = (event.event_code ?? '').toString()
  const title = (event.title ?? '').toString().trim()

  const labels: Record<string, string> = {
    'submission.auto_submitted': 'Application submitted',
    'submission.submitted': 'Application submitted',
    'submission.resubmitted': 'Application resubmitted',
    'payment.confirmed': 'Payment confirmed',
    'payment.finance_approved': 'Payment confirmed',
    'review.started': 'Assigned for verification',
    'review.level1_completed': 'Level 1 review completed',
    'review.level2_returned_to_level1': 'Returned to Level 1 review',
    'review.sent_back_to_applicant': 'Returned for correction',
    'decision.approved': 'Approved',
    'decision.rejected': 'Rejected',
    'certificate.issued': 'Certificate issued',
  }

  if (labels[code]) return labels[code]
  if (code.includes('qualification_sent_back')) return 'Returned for correction'
  if (code.includes('level2') && title) return title
  if (title) return title

  return 'Update'
}

export function eventQualificationId(event: TimelineEvent): number | null {
  const raw = event.qualification_id ?? event.metadata?.qualification_id
  const parsed = Number(raw ?? 0)
  return parsed > 0 ? parsed : null
}

export function eventMatchesQualification(event: TimelineEvent, qualificationId: number | null): boolean {
  if (!qualificationId) return true
  const qid = eventQualificationId(event)
  return qid === null || qid === qualificationId
}

export function buildTimeline(
  events: TimelineEvent[],
  statusHistoryFallback: Array<{
    id: number
    from_status?: string | null
    to_status?: string | null
    comment?: string | null
    changed_at?: string | null
  }>,
  qualificationId: number | null,
): TimelineEvent[] {
  const base =
    (events ?? []).length > 0
      ? events
      : (statusHistoryFallback ?? []).map((history) => ({
          id: `status-${history.id}`,
          title: history.from_status
            ? `${history.from_status} → ${history.to_status}`
            : `Status: ${history.to_status}`,
          description: history.comment ?? null,
          occurred_at: history.changed_at,
          event_code: 'status.fallback',
          comment: null,
        }))

  return base.filter((event) => eventMatchesQualification(event, qualificationId))
}

function latestMilestoneEvent(timeline: TimelineEvent[]): TimelineEvent | null {
  return timeline.find((event) => isMilestoneTimelineEvent(event.event_code)) ?? null
}

function statusFromQualificationLabel(label: string | null | undefined): TrackingStatusKey | null {
  const normalized = (label ?? '').toString().trim().toLowerCase()
  if (!normalized) return null
  if (normalized === 'sent back') return 'returned_for_corrections'
  if (normalized === 'approved') return 'approved'
  if (normalized === 'rejected') return 'rejected'
  if (normalized === 'certificate issued') return 'certificate_issued'
  if (normalized === 'closed') return 'closed'
  if (normalized === 'draft') return 'draft'
  if (normalized === 'pending payment') return 'pending_payment'
  return null
}

function statusFromApplication(application: TrackingApplication): TrackingStatusKey | null {
  const status = (application.current_status ?? '').toString()
  if (application.correction_required) return 'returned_for_corrections'
  if (status === 'draft') return 'draft'
  if (status === 'pending_payment') {
    if ((application.payment?.status ?? '') === 'awaiting_finance_review') {
      return 'awaiting_finance_approval'
    }
    return 'pending_payment'
  }
  if (status === 'sent_back') return 'returned_for_corrections'
  if (status === 'approved' || status === 'certificate_ready') return 'approved'
  if (status === 'rejected') return 'rejected'
  if (status === 'completed') return 'certificate_issued'
  return null
}

function statusFromMilestoneEvent(event: TimelineEvent | null): TrackingStatusKey | null {
  if (!event) return null
  const code = (event.event_code ?? '').toString()
  const title = (event.title ?? '').toString().toLowerCase()

  if (code.startsWith('certificate.')) return 'certificate_issued'
  if (code === 'decision.rejected') return 'rejected'
  if (code === 'decision.approved') return 'approved'
  if (code.includes('qualification_sent_back') || code === 'review.sent_back_to_applicant') {
    return 'returned_for_corrections'
  }
  if (code === 'review.level1_completed' || title.includes('level 2')) {
    return title.includes('under level 2') ? 'under_level2_review' : 'awaiting_level2_review'
  }
  if (code === 'review.started' || title.includes('level 1')) return 'under_level1_review'
  if (code.startsWith('submission.')) return 'awaiting_assignment'
  if (code.startsWith('payment.')) return 'pending_payment'

  return null
}

export function resolveTrackingStatus(
  application: TrackingApplication,
  selectedQualification: SelectedQualification,
  timeline: TimelineEvent[],
): TrackingCopy {
  const milestone = latestMilestoneEvent(timeline)
  const key =
    statusFromQualificationLabel(selectedQualification?.status_label)
    ?? statusFromMilestoneEvent(milestone)
    ?? statusFromApplication(application)
    ?? 'under_review'

  return TRACKING_COPY[key]
}

const TRACKING_COPY: Record<TrackingStatusKey, TrackingCopy> = {
  draft: {
    key: 'draft',
    headline: 'Draft',
    stage: 'Draft',
    nextStep: 'Complete your application and proceed to payment',
    whatNext: 'Finish the application wizard and upload all required documents before payment.',
    badgeClass: 'zaqa-badge zaqa-badge-warning',
  },
  pending_payment: {
    key: 'pending_payment',
    headline: 'Pending payment',
    stage: 'Payment',
    nextStep: 'Complete payment to submit your application',
    whatNext: 'Pay the invoice to submit your application to ZAQA for verification.',
    badgeClass: 'zaqa-badge zaqa-badge-warning',
  },
  awaiting_finance_approval: {
    key: 'awaiting_finance_approval',
    headline: 'Payment pending approval',
    stage: 'Payment',
    nextStep: 'Awaiting finance confirmation',
    whatNext:
      'Your proof of payment has been submitted and is being reviewed by ZAQA finance. No further action is needed until approval is complete.',
    badgeClass: 'zaqa-badge zaqa-badge-warning',
  },
  awaiting_assignment: {
    key: 'awaiting_assignment',
    headline: 'Awaiting assignment',
    stage: 'Under review',
    nextStep: 'Assignment to a verification officer',
    whatNext:
      'Your application has been received and is waiting to be assigned to a verification officer.',
    badgeClass: 'zaqa-badge zaqa-badge-info',
  },
  under_level1_review: {
    key: 'under_level1_review',
    headline: 'Under Level 1 review',
    stage: 'Under review',
    nextStep: 'Level 1 verification in progress',
    whatNext: 'A verification officer is currently reviewing your qualification at Level 1.',
    badgeClass: 'zaqa-badge zaqa-badge-info',
  },
  awaiting_level2_review: {
    key: 'awaiting_level2_review',
    headline: 'Awaiting Level 2 review',
    stage: 'Under review',
    nextStep: 'Assignment for Level 2 review',
    whatNext: 'Level 1 review is complete. Your qualification is waiting for Level 2 review.',
    badgeClass: 'zaqa-badge zaqa-badge-info',
  },
  under_level2_review: {
    key: 'under_level2_review',
    headline: 'Under Level 2 review',
    stage: 'Under review',
    nextStep: 'Level 2 verification in progress',
    whatNext: 'A senior verification officer is completing the final review of your qualification.',
    badgeClass: 'zaqa-badge zaqa-badge-info',
  },
  under_review: {
    key: 'under_review',
    headline: 'Under review',
    stage: 'Under review',
    nextStep: 'Verification in progress',
    whatNext: 'A verification officer is currently reviewing your qualification.',
    badgeClass: 'zaqa-badge zaqa-badge-info',
  },
  returned_for_corrections: {
    key: 'returned_for_corrections',
    headline: 'Returned for corrections',
    stage: 'Corrections required',
    nextStep: 'Upload corrected information',
    whatNext: 'You must upload the requested corrections before review can continue.',
    badgeClass: 'zaqa-badge zaqa-badge-warning',
  },
  approved: {
    key: 'approved',
    headline: 'Approved',
    stage: 'Decision',
    nextStep: 'Certificate generation',
    whatNext: 'Your qualification has been approved. ZAQA will prepare your certificate.',
    badgeClass: 'zaqa-badge zaqa-badge-success',
  },
  rejected: {
    key: 'rejected',
    headline: 'Rejected',
    stage: 'Decision',
    nextStep: 'Outcome finalised',
    whatNext: 'Your qualification could not be verified. Check your application for the outcome details.',
    badgeClass: 'zaqa-badge zaqa-badge-danger',
  },
  certificate_issued: {
    key: 'certificate_issued',
    headline: 'Certificate issued',
    stage: 'Completed',
    nextStep: 'Download your certificate',
    whatNext: 'Your certificate is ready for download from your application.',
    badgeClass: 'zaqa-badge zaqa-badge-success',
  },
  closed: {
    key: 'closed',
    headline: 'Closed',
    stage: 'Completed',
    nextStep: 'No further action required',
    whatNext: 'This qualification record has been closed.',
    badgeClass: 'zaqa-badge',
  },
}

export type ProgressStep = {
  key: string
  label: string
  state: 'complete' | 'current' | 'upcoming'
}

export function buildProgressSteps(statusKey: TrackingStatusKey, application: TrackingApplication): ProgressStep[] {
  const steps = [
    { key: 'draft', label: 'Draft' },
    { key: 'payment', label: 'Payment' },
    { key: 'submitted', label: 'Submitted' },
    { key: 'review', label: 'Under review' },
    { key: 'decision', label: 'Decision' },
  ]

  const appStatus = (application.current_status ?? '').toString()
  const paymentConfirmed = ['confirmed', 'awaiting_finance_review'].includes(
    (application.payment?.status ?? '').toString(),
  )
  const submitted = Boolean(application.submitted_at)

  let currentIndex = 0
  switch (statusKey) {
    case 'draft':
      currentIndex = 0
      break
    case 'pending_payment':
    case 'awaiting_finance_approval':
      currentIndex = 1
      break
    case 'awaiting_assignment':
      currentIndex = 2
      break
    case 'under_level1_review':
    case 'awaiting_level2_review':
    case 'under_level2_review':
    case 'under_review':
    case 'returned_for_corrections':
      currentIndex = 3
      break
    case 'approved':
    case 'rejected':
    case 'certificate_issued':
    case 'closed':
      currentIndex = 4
      break
    default:
      currentIndex = submitted ? 3 : paymentConfirmed ? 2 : 0
  }

  if (appStatus === 'pending_payment' && !paymentConfirmed) currentIndex = Math.max(currentIndex, 1)
  if (submitted && currentIndex < 2) currentIndex = 2

  return steps.map((step, index) => ({
    ...step,
    state: index < currentIndex ? 'complete' : index === currentIndex ? 'current' : 'upcoming',
  }))
}

export type ActionRequired = {
  title: string
  body: string
  tone: 'neutral' | 'warning' | 'success' | 'danger'
  ctaLabel?: string
  ctaHref?: string
}

export function buildActionRequired(
  statusKey: TrackingStatusKey,
  applicationId: number,
  selectedQualification: SelectedQualification,
): ActionRequired {
  if (statusKey === 'returned_for_corrections') {
    const href = selectedQualification?.id
      ? `/applicant/applications/${applicationId}/qualifications/${selectedQualification.id}/amend`
      : `/applicant/applications/${applicationId}`

    return {
      title: 'Documents requested',
      body: 'Please upload corrected documents and resubmit the qualification for review.',
      tone: 'warning',
      ctaLabel: 'Upload corrections',
      ctaHref: href,
    }
  }

  if (statusKey === 'certificate_issued' || statusKey === 'approved') {
    return {
      title: 'Application approved',
      body: 'You may now download your certificate from the application details page when it is available.',
      tone: 'success',
      ctaLabel: 'View application',
      ctaHref: `/applicant/applications/${applicationId}`,
    }
  }

  if (statusKey === 'rejected') {
    return {
      title: 'Application rejected',
      body: 'Review the outcome on your application details page.',
      tone: 'danger',
      ctaLabel: 'View application',
      ctaHref: `/applicant/applications/${applicationId}`,
    }
  }

  if (statusKey === 'pending_payment') {
    return {
      title: 'Payment required',
      body: 'Complete payment to submit your application for verification.',
      tone: 'warning',
      ctaLabel: 'Continue application',
      ctaHref: `/applicant/applications/${applicationId}/edit?step=payment`,
    }
  }

  if (statusKey === 'awaiting_finance_approval') {
    return {
      title: 'No action required',
      body: 'Your payment proof is with ZAQA finance for approval. We will notify you once it is confirmed.',
      tone: 'neutral',
    }
  }

  return {
    title: 'No action required',
    body: 'Your application is currently under review. We will notify you if anything is needed.',
    tone: 'neutral',
  }
}

export function paymentStatusLabel(status: string | null | undefined): string {
  const value = (status ?? '').toString().trim()
  if (!value) return 'Not paid'
  if (value === 'confirmed') return 'Confirmed'
  if (value === 'awaiting_finance_review') return 'Pending approval'
  if (value === 'pending_confirmation') return 'Pending confirmation'
  return value.replaceAll('_', ' ')
}

export function formatTrackingDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso))
  } catch {
    return String(iso)
  }
}

export function formatTrackingDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return String(iso)
  }
}
