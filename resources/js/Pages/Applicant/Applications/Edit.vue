<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, withDefaults } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import QualificationAmendmentBanner from '@/Components/QualificationAmendmentBanner.vue'
import InputError from '@/Components/InputError.vue'
import WizardStepper from '@/Components/WizardStepper.vue'
import WizardShell from '@/Components/WizardShell.vue'
import WizardFooterBar from '@/Components/WizardFooterBar.vue'
import DocumentManager from '@/Components/DocumentManager.vue'
import ActionModal from '@/Components/ActionModal.vue'
import Swal from 'sweetalert2'
import { isAllowedCertificateSubjectGrade } from '@/lib/certificateSubjectGrades'
import {
  AlertCircle,
  CheckCircle2,
  CreditCard,
  FileDown,
  GraduationCap,
  Landmark,
  Lock,
  PenLine,
  PlusCircle,
  Smartphone,
  Upload,
} from 'lucide-vue-next'

type ApplicantPayload = {
  applicant_type?: string
  email?: string
  phone_primary?: string
  phone_secondary?: string | null
  applicant_profile?: any | null
  institution_profile?: any | null
}

const props = withDefaults(
  defineProps<{
    application: any
    cgrate?: { enabled: boolean; poll_interval_seconds: number; payment_expiry_minutes: number }
    bankTransfer?: {
      deposit_account: {
        bank_name: string
        account_name: string
        account_number: string
        branch_code: string
      }
    }
    applicant: ApplicantPayload
    serviceTypes: Array<{ value: string; label: string }>
    qualificationTypes: Array<any>
    countries: Array<{ id: number; name: string; iso_code?: string | null }>
    awardingInstitutions: Array<{ id: number; name: string }>
    /** Admin-managed subject catalog for school-certificate rows */
    certificateSubjects?: Array<{ id: number; name: string }>
    foreignFeePreview?: any | null
    /** Wizard step 3 — declarations copy (terms / accuracy); separate from qualification consent */
    declarationsCopy?: Record<string, string>
    /** Open qualification amendment flow for one returned qualification */
    amendmentQualificationId?: number | null
  }>(),
  {
    cgrate: () => ({ enabled: false, poll_interval_seconds: 10, payment_expiry_minutes: 10 }),
    bankTransfer: () => ({
      deposit_account: {
        bank_name: '',
        account_name: '',
        account_number: '',
        branch_code: '',
      },
    }),
    certificateSubjects: () => [],
    declarationsCopy: () => ({}),
    amendmentQualificationId: null,
  },
)

type StepKey = 'applicant' | 'qualification' | 'consent' | 'payment'

const steps = computed(() => [
  { key: 'applicant' as const, label: 'Applicant' },
  { key: 'qualification' as const, label: 'Qualification' },
  { key: 'consent' as const, label: 'Confirm' },
  { key: 'payment' as const, label: 'Payment' },
])

const activeStep = ref<StepKey>('applicant')
const saveState = ref<{ state: 'idle' | 'saving' | 'saved' | 'error'; message?: string }>({ state: 'idle' })
const page = usePage()

function setSaved(message = 'Saved') {
  saveState.value = { state: 'saved', message }
  window.setTimeout(() => {
    if (saveState.value.state === 'saved') saveState.value = { state: 'idle' }
  }, 2500)
}

function setSaving(message = 'Saving…') {
  saveState.value = { state: 'saving', message }
}

function setError(message = 'Could not save. Please retry.') {
  saveState.value = { state: 'error', message }
}

function formatCveqIssuedAt(iso: string | null | undefined): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium' })
  } catch {
    return iso
  }
}

function formatDateTimeValue(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function goToStep(key: StepKey) {
  activeStep.value = key
  try {
    localStorage.setItem(`zaqa:wizard:${props.application.id}:step`, key)
    const url = new URL(window.location.href)
    url.searchParams.set('step', key)
    window.history.replaceState({}, '', url.toString())
  } catch {
    // ignore
  }
}

const prepareInvoiceForm = useForm({})

const declarationsForm = useForm({
  accept_terms: !!(props.application?.wizard_declarations?.terms_accepted_at),
  confirm_information_correct: !!(props.application?.wizard_declarations?.information_confirmed_at),
})

watch(
  () => declarationsForm.accept_terms,
  (accepted) => {
    declarationsForm.confirm_information_correct = accepted
  },
)

watch(
  () => props.application?.wizard_declarations,
  (wd) => {
    declarationsForm.defaults({
      accept_terms: !!(wd?.terms_accepted_at),
      confirm_information_correct: !!(wd?.information_confirmed_at),
    })
    if (!(declarationsForm.isDirty ?? false)) {
      declarationsForm.reset()
      declarationsForm.clearErrors()
    }
  },
  { deep: true },
)

function isStepDirty(step: StepKey): boolean {
  if (step === 'applicant') return (applicantForm.isDirty ?? false) === true
  if (step === 'consent') return (declarationsForm.isDirty ?? false) === true
  return false
}

function qualificationSubjectsSatisfied(q: any): boolean {
  const typeId = Number(q.qualification_type_id ?? 0)
  const type = (props.qualificationTypes ?? []).find((t: any) => Number(t.id) === typeId)
  if (!type?.requires_subject_results) return true
  const rows = (q.subject_results ?? []) as Array<{
    subject_name?: string
    grade?: string
    certificate_subject_id?: number | null
  }>
  if (rows.length === 0) return false
  return rows.every((r) => {
    const gradeOk = isAllowedCertificateSubjectGrade(r.grade)
    const catalogId = Number(r.certificate_subject_id ?? 0)
    const subjectOk =
      catalogId > 0 || (r.subject_name ?? '').toString().trim() !== ''
    return gradeOk && subjectOk
  })
}

function trimStr(v: unknown): string {
  return (v ?? '').toString().trim()
}

/**
 * Gate rules match server-side checks but merge live form values + saved props
 * so applicants are not blocked after upload or while editing before save.
 */
function evaluateApplicantStep(): { ok: boolean; missing: string[] } {
  const missing: string[] = []
  const submittingFor = (
    submittingForForm.submitting_for ??
    props.application?.metadata?.submitting_for ??
    'self'
  )
    .toString()
    .trim()

  const emailEff = trimStr(applicantForm.email) || trimStr(props.applicant?.email)
  const phoneEff = trimStr(applicantForm.phone_primary) || trimStr(props.applicant?.phone_primary)
  if (!emailEff && !phoneEff) {
    missing.push('Provide at least one contact method (email or primary phone).')
  }

  const applicantTypeStr = trimStr(props.applicant?.applicant_type ?? props.application?.applicant_type)

  if (submittingFor === 'other') {
    const firstName =
      trimStr(props.application?.metadata?.verification_subject?.first_name) ||
      trimStr(submittingForForm.subject_first_name)
    const lastName =
      trimStr(props.application?.metadata?.verification_subject?.last_name) ||
      trimStr(submittingForForm.subject_last_name)
    if (!firstName) missing.push('Enter the holder’s first name.')
    if (!lastName) missing.push('Enter the holder’s last name.')

    const gender =
      trimStr(props.application?.metadata?.verification_subject?.gender) ||
      trimStr(submittingForForm.gender)
    if (!gender) missing.push('Select the holder’s gender.')

    const identityType =
      trimStr(props.application?.metadata?.verification_subject?.identity_type) ||
      trimStr(submittingForForm.identity_type)
    const identityNumberFromMeta =
      identityType.toLowerCase() === 'passport'
        ? trimStr(props.application?.metadata?.verification_subject?.passport_number)
        : trimStr(props.application?.metadata?.verification_subject?.nrc_number)
    const identityNumber = identityNumberFromMeta || trimStr(submittingForForm.identity_number)
    if (!identityType) missing.push('Select identity type (NRC or Passport).')
    if (!identityNumber) missing.push('Enter the holder’s NRC or passport number.')
  } else if (applicantTypeStr === 'individual') {
    const gender =
      trimStr(props.applicant?.applicant_profile?.gender) ||
      trimStr(applicantForm.gender)
    if (!gender) missing.push('Select your gender under Biodata.')

    const identityType =
      trimStr(applicantForm.identity_type) ||
      trimStr(props.applicant?.applicant_profile?.identity_type) ||
      (trimStr(props.applicant?.applicant_profile?.nrc_number) ? 'nrc' : trimStr(props.applicant?.applicant_profile?.passport_number) ? 'passport' : '')

    const identityNumber =
      trimStr(applicantForm.identity_number) ||
      (identityType.toLowerCase() === 'passport'
        ? trimStr(props.applicant?.applicant_profile?.passport_number)
        : trimStr(props.applicant?.applicant_profile?.nrc_number))

    if (!identityType) missing.push('Select identity type under Biodata.')
    if (!identityNumber) missing.push('Enter your NRC or passport number under Biodata.')
  }

  const identityUploadOk =
    submittingFor === 'other'
      ? hasApplicationIdentityDoc()
      : hasApplicationIdentityDoc() || !!(props.applicant?.applicant_profile?.identity_document_uploaded_at ?? false)

  if (!identityUploadOk) {
    missing.push('Upload a clear copy of the holder’s NRC or passport in the Identity document section.')
  }

  return { ok: missing.length === 0, missing }
}

const hasUnsavedChanges = computed(() => isStepDirty(activeStep.value))

function discardChangesForActiveStep() {
  if (activeStep.value === 'applicant') {
    applicantForm.reset()
    applicantForm.clearErrors()
  }
  if (activeStep.value === 'consent') {
    declarationsForm.defaults({
      accept_terms: !!(props.application?.wizard_declarations?.terms_accepted_at),
      confirm_information_correct: !!(props.application?.wizard_declarations?.information_confirmed_at),
    })
    declarationsForm.reset()
    declarationsForm.clearErrors()
  }
}

function confirmDiscardIfDirty(): boolean {
  if (!isStepDirty(activeStep.value)) return true
  const ok = window.confirm('You have unsaved changes on this step. Discard changes and continue?')
  if (ok) discardChangesForActiveStep()
  return ok
}

function requestStepChange(key: StepKey) {
  // Gate steps first (can’t jump ahead)
  if (disabledStepKeys.value.includes(key)) return
  if (!confirmDiscardIfDirty()) return
  goToStep(key)
}

onMounted(() => {
  try {
    const url = new URL(window.location.href)
    const requested = (url.searchParams.get('step') ?? '').toString().trim() as StepKey
    if (requested && (steps.value as any[]).some((s) => s.key === requested)) {
      goToStep(requested)
    } else {
      const stored = localStorage.getItem(`zaqa:wizard:${props.application.id}:step`) as StepKey | null
      if (stored && (steps.value as any[]).some((s) => s.key === stored)) activeStep.value = stored
    }
  } catch {
    // ignore
  }

  const quals = (props.application as any)?.qualifications ?? []
  if (Array.isArray(quals) && quals.length > 0 && !selectedQualificationId.value) {
    selectedQualificationId.value = quals[0].id
  }
})

const applicantType = computed(() => props.applicant?.applicant_type ?? props.application.applicant_type)
const applicantForm = useForm<any>({
  email: props.applicant?.email ?? '',
  phone_primary: props.applicant?.phone_primary ?? '',
  phone_secondary: props.applicant?.phone_secondary ?? '',
  ...(applicantType.value === 'institution'
    ? {
        institution_name: props.applicant?.institution_profile?.institution_name ?? '',
        tpin: props.applicant?.institution_profile?.tpin ?? '',
        contact_person_name: props.applicant?.institution_profile?.contact_person_name ?? '',
      }
    : {
        first_name: props.applicant?.applicant_profile?.first_name ?? '',
        middle_name: props.applicant?.applicant_profile?.middle_name ?? '',
        surname: props.applicant?.applicant_profile?.surname ?? '',
        gender: (props.applicant?.applicant_profile?.gender ?? '').toString(),
        identity_type: (
          trimStr(props.applicant?.applicant_profile?.identity_type) ||
          (trimStr(props.applicant?.applicant_profile?.nrc_number) ? 'nrc' : trimStr(props.applicant?.applicant_profile?.passport_number) ? 'passport' : 'nrc')
        ).toString(),
        identity_number: (
          (trimStr(props.applicant?.applicant_profile?.identity_type) || (trimStr(props.applicant?.applicant_profile?.nrc_number) ? 'nrc' : trimStr(props.applicant?.applicant_profile?.passport_number) ? 'passport' : 'nrc')).toString().toLowerCase() === 'passport'
            ? trimStr(props.applicant?.applicant_profile?.passport_number)
            : trimStr(props.applicant?.applicant_profile?.nrc_number)
        ).toString(),
      }),
})

type SubmittingFor = 'self' | 'other'
const institutionOnlyOnBehalf = computed(() => applicantType.value === 'institution')

const applicantIdentityNumberCache = ref<{ nrc: string; passport: string }>({
  nrc: trimStr(props.applicant?.applicant_profile?.nrc_number),
  passport: trimStr(props.applicant?.applicant_profile?.passport_number),
})

watch(
  () => applicantForm.identity_type,
  (next, prev) => {
    if (applicantType.value === 'institution') return
    const prevKey = (prev ?? '').toString().toLowerCase() === 'passport' ? 'passport' : 'nrc'
    applicantIdentityNumberCache.value[prevKey as 'nrc' | 'passport'] = trimStr(applicantForm.identity_number)
    const nextKey = (next ?? '').toString().toLowerCase() === 'passport' ? 'passport' : 'nrc'
    applicantForm.identity_number = (applicantIdentityNumberCache.value[nextKey as 'nrc' | 'passport'] ?? '').toString()
    applicantForm.clearErrors()
  },
)

const applicantIdentityNumberLabel = computed(() =>
  (applicantForm.identity_type ?? '').toString().toLowerCase() === 'passport' ? 'Passport number' : 'NRC number',
)
const applicantAccountEmail = computed(() => (props.applicant?.email ?? '').toString().trim())

const submittingForForm = useForm<{
  submitting_for: SubmittingFor
  subject_first_name: string
  subject_other_names: string
  subject_last_name: string
  notification_contact_mode: 'applicant_account' | 'additional_email'
  additional_notification_email: string
  additional_notification_name: string
  additional_notification_relationship: string
  gender: string
  identity_type: string
  identity_number: string
}>({
  submitting_for: (props.application?.metadata?.submitting_for ?? (institutionOnlyOnBehalf.value ? 'other' : 'self')) as SubmittingFor,
  subject_first_name: (props.application?.metadata?.verification_subject?.first_name ?? '').toString(),
  subject_other_names: (props.application?.metadata?.verification_subject?.other_names ?? '').toString(),
  subject_last_name: (props.application?.metadata?.verification_subject?.last_name ?? '').toString(),
  notification_contact_mode: (
    props.application?.metadata?.notification_contact_mode === 'additional_email' ? 'additional_email' : 'applicant_account'
  ) as 'applicant_account' | 'additional_email',
  additional_notification_email: (props.application?.metadata?.additional_notification_email ?? '').toString(),
  additional_notification_name: (props.application?.metadata?.additional_notification_name ?? '').toString(),
  additional_notification_relationship: (props.application?.metadata?.additional_notification_relationship ?? '').toString(),
  gender: (props.application?.metadata?.verification_subject?.gender ?? '').toString(),
  identity_type: (
    trimStr(props.application?.metadata?.verification_subject?.identity_type) ||
    (trimStr(props.application?.metadata?.verification_subject?.nrc_number) ? 'nrc' : trimStr(props.application?.metadata?.verification_subject?.passport_number) ? 'passport' : 'nrc')
  ).toString(),
  identity_number: (
    (trimStr(props.application?.metadata?.verification_subject?.identity_type) || (trimStr(props.application?.metadata?.verification_subject?.nrc_number) ? 'nrc' : trimStr(props.application?.metadata?.verification_subject?.passport_number) ? 'passport' : 'nrc')).toString().toLowerCase() === 'passport'
      ? trimStr(props.application?.metadata?.verification_subject?.passport_number)
      : trimStr(props.application?.metadata?.verification_subject?.nrc_number)
  ).toString(),
})

const subjectIdentityNumberCache = ref<{ nrc: string; passport: string }>({
  nrc: trimStr(props.application?.metadata?.verification_subject?.nrc_number),
  passport: trimStr(props.application?.metadata?.verification_subject?.passport_number),
})

watch(
  institutionOnlyOnBehalf,
  (isInst) => {
    if (isInst && submittingForForm.submitting_for !== 'other') {
      submittingForForm.submitting_for = 'other'
      submittingForForm.clearErrors()
    }
  },
  { immediate: true },
)

watch(
  () => submittingForForm.identity_type,
  (next, prev) => {
    const prevKey = (prev ?? '').toString().toLowerCase() === 'passport' ? 'passport' : 'nrc'
    subjectIdentityNumberCache.value[prevKey as 'nrc' | 'passport'] = trimStr(submittingForForm.identity_number)
    const nextKey = (next ?? '').toString().toLowerCase() === 'passport' ? 'passport' : 'nrc'
    submittingForForm.identity_number = (subjectIdentityNumberCache.value[nextKey as 'nrc' | 'passport'] ?? '').toString()
    submittingForForm.clearErrors()
  },
)

const submittingForIdentityNumberLabel = computed(() =>
  (submittingForForm.identity_type ?? '').toString().toLowerCase() === 'passport' ? 'Passport number' : 'NRC number',
)

const identityUploadForm = useForm<{ identity_type: string; file: File | null }>({
  identity_type: 'nrc',
  file: null,
})

const savedSubmittingFor = computed(() => trimStr(props.application?.metadata?.submitting_for ?? 'self') || 'self')
const effectiveSubmittingFor = computed(() => (institutionOnlyOnBehalf.value ? 'other' : submittingForForm.submitting_for))

const additionalEmailMatchesAccount = computed(() => {
  const additional = (submittingForForm.additional_notification_email ?? '').toString().trim().toLowerCase()
  const account = applicantAccountEmail.value.toLowerCase()
  return additional !== '' && account !== '' && additional === account
})

const identityUploadDisabled = computed(() => {
  if (identityUploadForm.processing) return true
  return trimStr(effectiveSubmittingFor.value) !== savedSubmittingFor.value
})

const identityUploadLabel = computed(() => {
  const type =
    effectiveSubmittingFor.value === 'other'
      ? trimStr(submittingForForm.identity_type).toLowerCase()
      : trimStr(applicantForm.identity_type).toLowerCase()
  return type === 'passport' ? 'Upload passport copy' : 'Upload NRC copy'
})

function uploadIdentityDocument() {
  const type =
    effectiveSubmittingFor.value === 'other'
      ? trimStr(submittingForForm.identity_type).toLowerCase()
      : trimStr(applicantForm.identity_type).toLowerCase()
  identityUploadForm.identity_type = type === 'passport' ? 'passport' : 'nrc'

  identityUploadForm.post(`/applicant/applications/${props.application.id}/identity-document`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      identityUploadForm.reset('file')
      router.reload({ only: ['application', 'applicant'] })
    },
  })
}

function saveSubmittingFor() {
  setSaving('Saving verification subject…')
  submittingForForm.patch(`/applicant/applications/${props.application.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Verification subject saved.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Could not save verification subject.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

function saveApplicantDetails(nextStep?: StepKey) {
  setSaving('Saving applicant details…')
  applicantForm.put(`/applicant/applications/${props.application.id}/applicant-details`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Applicant details saved.')
      if (nextStep) goToStep(nextStep)
    },
    onError: () => setError('Applicant details could not be saved.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const applicationLocked = computed(() => {
  if (props.amendmentQualificationId) return false
  return props.application?.can_edit === false
})

const correctionRequiredMode = computed(() => props.application?.correction_required_mode === true)

const displayStatusLabel = computed(
  () => props.application?.display_status_label ?? props.application?.status_label ?? 'Application',
)

const paymentAwaitingFinanceReview = computed(() => (payment.value?.status ?? '') === 'awaiting_finance_review')
const applicationLockMessage = computed(() =>
  paymentAwaitingFinanceReview.value
    ? 'Proof submitted and awaiting finance review. This application is temporarily read-only.'
    : 'Payment is confirmed. This application is read-only.',
)

const qualifications = computed<any[]>(() => {
  const list = (props.application as any)?.qualifications
  if (Array.isArray(list)) return list
  if (props.application?.qualification) return [props.application.qualification]
  return []
})

const selectedQualificationId = ref<number | null>(qualifications.value?.[0]?.id ?? null)
const selectedQualification = computed<any | null>(() => {
  if (!selectedQualificationId.value) return null
  return qualifications.value.find((q) => Number(q.id) === Number(selectedQualificationId.value)) ?? null
})

function openQualificationWorkspace(mode: 'add' | 'edit', qual?: any) {
  if ((applicationLocked.value || correctionRequiredMode.value) && mode === 'add') return
  if (mode === 'edit' && qual && correctionRequiredMode.value && (qual.verification_state ?? '') !== 'returned_to_applicant') {
    return
  }
  if (qual?.id) selectedQualificationId.value = qual.id
  const base = `/applicant/applications/${props.application.id}`
  if (mode === 'add') {
    router.visit(`${base}/qualifications/create`, {
      data: { return: `${base}/edit?step=qualification` },
      preserveScroll: true,
    })
    return
  }
  if (qual?.id) {
    router.visit(`${base}/qualifications/${qual.id}/edit`, {
      data: { return: `${base}/edit?step=qualification` },
      preserveScroll: true,
    })
  }
}

function removeQualification(id: number) {
  if (applicationLocked.value) return
  router.delete(`/applicant/applications/${props.application.id}/qualifications/${id}`, {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['application'] }),
  })
}

function saveDeclarations() {
  setSaving('Saving confirmations…')
  declarationsForm.patch(`/applicant/applications/${props.application.id}/wizard-declarations`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Confirmations saved.')
      router.reload({
        only: ['application'],
        onSuccess: () => {
          const next = stepNav.value.next
          if (next) {
            goToStep(next)
            void nextTick().then(() => window.scrollTo({ top: 0, behavior: 'smooth' }))
          }
        },
        onFinish: () => {
          if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
        },
      })
    },
    onError: () => {
      setError('Confirmations could not be saved.')
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

function escapeHtml(unsafe: string): string {
  return unsafe
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

function openTermsModal() {
  const title = (props.declarationsCopy?.terms_title ?? 'Terms and conditions').toString()
  const body = (props.declarationsCopy?.terms_body ?? '').toString()
  void Swal.fire({
    icon: 'info',
    titleText: title,
    html: `<div class="text-left whitespace-pre-wrap text-sm leading-relaxed text-text-primary max-h-[60vh] overflow-auto">${escapeHtml(body)}</div>`,
    confirmButtonText: 'Close',
    confirmButtonColor: '#0076BD',
    width: 860,
  })
}

function formatPersonName(firstName: string, otherNames: string, lastName: string): string {
  const parts = [trimStr(firstName), trimStr(otherNames), trimStr(lastName)].filter(Boolean)
  return parts.join(' ')
}

const consentSubjectSummary = computed(() => {
  const submittingFor = (effectiveSubmittingFor.value ?? 'self').toString().trim()
  const vs = (props.application?.metadata?.verification_subject ?? {}) as Record<string, any>

  const profile = (props.applicant?.applicant_profile ?? {}) as Record<string, any>
  const fullName =
    formatPersonName(vs.first_name ?? '', vs.other_names ?? '', vs.last_name ?? '') ||
    formatPersonName(profile.first_name ?? '', profile.middle_name ?? '', profile.surname ?? '') ||
    trimStr(props.applicant?.name)

  const identityTypeRaw =
    trimStr(vs.identity_type) ||
    (trimStr(vs.passport_number) ? 'passport' : trimStr(vs.nrc_number) ? 'nrc' : '') ||
    trimStr(profile.identity_type) ||
    (trimStr(profile.passport_number) ? 'passport' : trimStr(profile.nrc_number) ? 'nrc' : '')

  const identityType = identityTypeRaw.toLowerCase() === 'passport' ? 'Passport' : identityTypeRaw ? 'NRC' : ''
  const identityNumber =
    identityTypeRaw.toLowerCase() === 'passport'
      ? trimStr(vs.passport_number) || trimStr(profile.passport_number)
      : trimStr(vs.nrc_number) || trimStr(profile.nrc_number)

  return {
    submittingFor,
    fullName: fullName || '—',
    identification: identityType && identityNumber ? `${identityType} ${identityNumber}` : '—',
    applicationReference: trimStr(props.application?.application_number) || '—',
  }
})

// Manual application submission has been removed; payment confirmation triggers automatic submission.
// Keep no-op bindings to avoid template errors if legacy review markup remains.
const submitForm = useForm({})
const declarationAccepted = ref(false)
const canSubmitNow = computed(() => false)
async function submitApplication() {
  await Swal.fire({
    icon: 'info',
    title: 'Automatic submission',
    text: 'Your application is automatically submitted once payment is confirmed.',
    confirmButtonColor: '#0076BD',
  })
}

const payment = computed(() => props.application?.payment ?? null)
const invoice = computed(() => props.application?.invoice ?? null)

type InvoiceLineItem = {
  description: string
  quantity: number
  amount_cents: number
  total_cents: number
}

const invoiceLineItems = computed<InvoiceLineItem[]>(() => {
  const items = invoice.value?.line_items
  if (Array.isArray(items) && items.length > 0) {
    return items.map((row: any) => ({
      description: (row.description ?? '').toString(),
      quantity: Number(row.quantity ?? 1) || 1,
      amount_cents: Number(row.amount_cents ?? 0),
      total_cents: Number(row.total_cents ?? row.amount_cents ?? 0),
    }))
  }
  if (!invoice.value) return []
  return [
    {
      description: 'Application verification fee',
      quantity: 1,
      amount_cents: Number(invoice.value.amount_cents ?? 0),
      total_cents: Number(invoice.value.amount_cents ?? 0),
    },
  ]
})

const invoiceBreakdownTotalCents = computed(() =>
  invoiceLineItems.value.reduce((sum, row) => sum + Number(row.total_cents ?? 0), 0),
)
const invoiceSettled = computed(() => props.application?.payment_satisfied === true)

const applicationPaymentSatisfied = computed(() => props.application?.payment_satisfied === true)
const paymentOutstandingCents = computed(() => Number(props.application?.payment_outstanding_cents ?? 0))

const qualificationsReturnedForAmendment = computed(() =>
  qualifications.value.filter((q: any) => (q.verification_state ?? '') === 'returned_to_applicant'),
)

const finalizeAmendmentForm = useForm({})

function formatMoneyCents(cents: number) {
  const c = (invoice.value?.currency ?? 'ZMW').toString()
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format((cents || 0) / 100)
}

function paymentStatusLabel(status: unknown): string {
  const s = (status ?? '').toString().trim()
  if (!s) return 'Pending payment'
  switch (s) {
    case 'draft':
      return 'Pending payment'
    case 'initiated':
      return 'Payment started'
    case 'pending_confirmation':
      return 'Awaiting confirmation'
    case 'awaiting_finance_review':
      return 'Awaiting finance review'
    case 'confirmed':
      return 'Payment confirmed'
    case 'rejected':
      return 'Rejected'
    case 'failed':
      return 'Failed'
    case 'expired':
      return 'Expired'
    default:
      return s.replaceAll('_', ' ')
  }
}

function paymentStatusBadgeClass(status: unknown): string {
  const s = (status ?? '').toString().trim()
  if (s === 'confirmed') return 'zaqa-badge-success'
  if (s === 'rejected' || s === 'failed' || s === 'expired') return 'zaqa-badge-danger'
  if (s === 'awaiting_finance_review' || s === 'pending_confirmation' || s === 'initiated') return 'zaqa-badge-warning'
  return 'zaqa-badge-warning'
}

function invoiceStatusLabel(status: unknown): string {
  const s = (status ?? '').toString().trim()
  if (!s) return 'Pending payment'
  switch (s) {
    case 'draft':
      return 'Preparing'
    case 'issued':
      return 'Pending payment'
    case 'paid':
      return 'Paid'
    case 'void':
      return 'Voided'
    default:
      return s.replaceAll('_', ' ')
  }
}

function invoiceStatusBadgeClass(status: unknown): string {
  const s = (status ?? '').toString().trim()
  if (s === 'paid') return 'zaqa-badge-success'
  if (s === 'void') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
}

function submitCorrectionsToZaqa(qualificationId: number) {
  finalizeAmendmentForm.post(`/applicant/applications/${props.application.id}/qualifications/${qualificationId}/finalize-amendment`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Corrections sent to ZAQA.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Could not submit corrections. Fix any issue below and try again.'),
  })
}

// Applicant wizard is now full-width on all steps (no right-side sidebar panels).
const showSidebar = computed(() => false)

type MobileMoneyApplicantStatus = {
  attempt_id?: number | null
  id?: number | null
  status: 'pending' | 'successful' | 'failed'
  message: string
  paid?: boolean
  redirect_url?: string | null
  mobile_number?: string | null
  amount_cents?: number
  currency?: string
  initiated_at?: string | null
  can_retry?: boolean
  already_pending?: boolean
}

const mobileMoneyForm = useForm<{ mobile_number: string }>({ mobile_number: '' })
const mobileMoneySubmitting = ref(false)
const mobileMoneyModalOpen = ref(false)
const mobileMoneyLive = ref<MobileMoneyApplicantStatus | null>(null)
const mobileMoneyPollingId = ref<number | null>(null)

const mobileMoneyAttempt = computed(() => mobileMoneyLive.value ?? payment.value?.latest_attempt ?? null)
const mobileMoneyAttemptId = computed(() => {
  const attempt = mobileMoneyAttempt.value
  if (!attempt) return null
  return Number(attempt.attempt_id ?? attempt.id ?? 0) || null
})
const mobileMoneyIsPending = computed(() => (mobileMoneyAttempt.value?.status ?? '') === 'pending')
const mobileMoneyIsFailed = computed(() => (mobileMoneyAttempt.value?.status ?? '') === 'failed')
const mobileMoneyIsSuccessful = computed(() => (mobileMoneyAttempt.value?.status ?? '') === 'successful' || (payment.value?.status ?? '') === 'confirmed')
const mobileMoneyCanInitiate = computed(() => !mobileMoneyIsPending.value && !mobileMoneyIsSuccessful.value && (payment.value?.status ?? '') !== 'confirmed')

const feedbackUrl = computed(() => `/applicant/applications/${props.application.id}/feedback`)
const paymentCelebrationInFlight = ref(false)

async function celebratePaymentSuccessAndGoToFeedback() {
  if (paymentCelebrationInFlight.value) return
  paymentCelebrationInFlight.value = true
  stopMobileMoneyPolling()
  mobileMoneyModalOpen.value = false

  await Swal.fire({
    icon: 'success',
    title: 'Payment confirmed',
    html:
      '<p class="text-sm text-left">Your payment was successful and your application has been submitted to ZAQA for verification.</p>' +
      '<p class="mt-3 text-sm text-left text-text-muted">Next, please share a quick rating of your submission experience.</p>',
    confirmButtonText: 'Continue to feedback',
    confirmButtonColor: '#0076BD',
    allowOutsideClick: false,
  })

  router.visit(feedbackUrl.value)
}

function mobileMoneyStatusBadgeClass(status: unknown): string {
  const s = (status ?? '').toString()
  if (s === 'successful') return 'zaqa-badge-success'
  if (s === 'failed') return 'zaqa-badge-danger'
  return 'zaqa-badge-warning'
}

function mobileMoneyStatusLabel(status: unknown): string {
  const s = (status ?? '').toString()
  if (s === 'successful') return 'Successful'
  if (s === 'failed') return 'Failed'
  return 'Pending'
}

async function initiateMobileMoney() {
  if (!mobileMoneyCanInitiate.value || mobileMoneySubmitting.value) return

  mobileMoneySubmitting.value = true
  setSaving('Sending payment prompt…')

  try {
    const res = await (window as any).axios.post(
      `/applicant/applications/${props.application.id}/payment/initiate-mobile-money`,
      { mobile_number: mobileMoneyForm.mobile_number },
      { headers: { Accept: 'application/json' } },
    )

    mobileMoneyLive.value = res.data ?? null
    mobileMoneyModalOpen.value = true
    setSaved(res.data?.already_pending ? 'A payment request is already pending.' : 'Payment request sent.')
    startMobileMoneyPolling()
    router.reload({ only: ['application'] })
  } catch (error: any) {
    const message = error?.response?.data?.message ?? 'Could not send payment prompt.'
    setError(message)
    if (error?.response?.data?.errors?.mobile_number) {
      mobileMoneyForm.setError('mobile_number', error.response.data.errors.mobile_number[0])
    }
  } finally {
    mobileMoneySubmitting.value = false
    if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
  }
}

watch(
  () => payment.value?.id,
  () => {
    if (!mobileMoneyModalOpen.value) {
      mobileMoneyLive.value = null
    }
  },
)

function stopMobileMoneyPolling() {
  if (mobileMoneyPollingId.value) {
    window.clearInterval(mobileMoneyPollingId.value)
    mobileMoneyPollingId.value = null
  }
}

async function checkMobileMoneyStatus() {
  const attemptId = mobileMoneyAttemptId.value
  if (!attemptId) return

  try {
    const res = await (window as any).axios.get(`/applicant/payments/attempts/${attemptId}/status`)
    mobileMoneyLive.value = res.data ?? null

    if (res.data?.paid || res.data?.status === 'successful') {
      await celebratePaymentSuccessAndGoToFeedback()
      return
    }

    if (res.data?.status === 'failed') {
      stopMobileMoneyPolling()
    }
  } catch {
    // Avoid noisy UI errors during auto-poll.
  }
}

function startMobileMoneyPolling() {
  if (mobileMoneyPollingId.value || !mobileMoneyIsPending.value) return

  mobileMoneyPollingId.value = window.setInterval(() => {
    if (!mobileMoneyIsPending.value) {
      stopMobileMoneyPolling()
      return
    }
    void checkMobileMoneyStatus()
  }, 3000)
}

function closeMobileMoneyModal() {
  mobileMoneyModalOpen.value = false
}

watch(
  () => [mobileMoneyAttempt.value?.status, mobileMoneyAttemptId.value],
  () => {
    if (mobileMoneyIsPending.value && mobileMoneyAttemptId.value) {
      startMobileMoneyPolling()
    } else {
      stopMobileMoneyPolling()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => stopMobileMoneyPolling())

const cardInitiateForm = useForm({})
function initiateCardPayment() {
  setSaving('Redirecting to card payment…')
  cardInitiateForm.post(`/applicant/applications/${props.application.id}/payment/initiate-card`, {
    preserveScroll: true,
    onError: () => setError('Could not initiate card payment.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

type PaymentTabKey = 'card' | 'bank_transfer' | 'mobile_money'

function paymentTabFromMethod(method: any): PaymentTabKey | null {
  const m = (method ?? '').toString()
  if (m === 'bank_deposit') return 'bank_transfer'
  if (m === 'card' || m === 'bank_transfer' || m === 'mobile_money') return m as PaymentTabKey
  return null
}

function loadPaymentTabPreference(): PaymentTabKey | null {
  try {
    const v = localStorage.getItem(`zaqa:wizard:${props.application.id}:payment_tab`)
    if (v === 'card' || v === 'bank_transfer' || v === 'mobile_money') return v
  } catch {
    // ignore
  }
  return null
}

function storePaymentTabPreference(tab: PaymentTabKey) {
  try {
    localStorage.setItem(`zaqa:wizard:${props.application.id}:payment_tab`, tab)
  } catch {
    // ignore
  }
}

function setPaymentTab(tab: PaymentTabKey) {
  activePaymentTab.value = tab
  storePaymentTabPreference(tab)
}

const activePaymentTab = ref<PaymentTabKey>(paymentTabFromMethod(payment.value?.method) ?? loadPaymentTabPreference() ?? 'card')

const bankDepositAccount = computed(() => props.bankTransfer?.deposit_account ?? null)

const bankDepositReference = computed(() => {
  const invoiceNumber = (invoice.value?.invoice_number ?? '').toString().trim()
  if (invoiceNumber) return invoiceNumber
  return (props.application?.application_number ?? '').toString().trim() || '—'
})

function bankDepositField(value: string | null | undefined): string {
  const v = (value ?? '').toString().trim()
  return v || '—'
}
watch(
  () => payment.value?.method,
  (m) => {
    if (!m) return
    const tab = paymentTabFromMethod(m)
    if (tab) setPaymentTab(tab)
  },
)

const proofForm = useForm<{ file: File | null }>({ file: null })
function onProofFileChange(e: Event) {
  const target = e.target as HTMLInputElement
  proofForm.file = target.files && target.files.length > 0 ? target.files[0] : null
}
function uploadPaymentProof() {
  setSaving('Uploading proof…')
  proofForm.post(`/applicant/applications/${props.application.id}/payment/upload-proof`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      proofForm.reset('file')
      setSaved('Proof uploaded.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Could not upload proof.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

function refreshPaymentStatus() {
  setSaving('Refreshing payment status…')
  router.reload({
    only: ['application'],
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

// Qualification flow is consolidated into a single step (multiple items).

function hasCurrentDocumentType(type: string) {
  return (props.application?.documents ?? []).some((d: any) => d.document_type === type && d.is_current_version)
}

function hasApplicationIdentityDoc(): boolean {
  return (
    hasCurrentDocumentType('nrc_copy') ||
    hasCurrentDocumentType('passport_copy')
  )
}

function hasCurrentQualificationDocument(qualificationId: number, type: string) {
  return (props.application?.documents ?? []).some(
    (d: any) => d.document_type === type && d.is_current_version && Number(d.qualification_id ?? 0) === Number(qualificationId),
  )
}

const qualificationRows = computed(() => {
  return qualifications.value.map((q) => {
    const id = Number(q.id)
    const isForeign = (q.is_foreign_qualification ?? false) === true
    const hasCert = typeof q.has_certificate_document === 'boolean' ? q.has_certificate_document : hasCurrentQualificationDocument(id, 'certificate_copy')
    const docsOk = hasCert

    const requiresForeignConsent = typeof q.requires_foreign_consent === 'boolean' ? q.requires_foreign_consent : isForeign
    const hasForeignConsent =
      typeof q.has_foreign_consent === 'boolean'
        ? q.has_foreign_consent
        : hasCurrentQualificationDocument(id, 'consent_form_signed')
    const hasLocalConsent = typeof q.has_local_consent === 'boolean' ? q.has_local_consent : false

    const consentOk = requiresForeignConsent ? hasForeignConsent : hasLocalConsent
    return { ...q, _docsOk: docsOk, _consentOk: consentOk, _isForeign: isForeign }
  })
})

const invoiceTotalPreview = computed(() => {
  const currency = (props.foreignFeePreview?.fee_preview?.currency ?? 'ZMW').toString()
  let amountCents = 0
  for (const q of qualifications.value) {
    const typeId = Number(q.qualification_type_id ?? 0)
    if (!typeId) continue
    const type = (props.qualificationTypes ?? []).find((t: any) => Number(t.id) === typeId) ?? null
    const isForeign = (q.is_foreign_qualification ?? false) === true
    const feePreview = isForeign ? props.foreignFeePreview?.fee_preview : type?.fee_preview
    const cents = Number(isForeign ? feePreview?.foreign_fee_cents : feePreview?.local_fee_cents) || 0
    amountCents += cents
  }
  return { currency, amountCents }
})

const applicantStepGate = computed(() => evaluateApplicantStep())

const stepCompletion = computed(() => {
  const applicantOk = applicantStepGate.value.ok

  const qualificationDone =
    qualificationRows.value.length > 0 &&
    qualificationRows.value.every(
      (q) => q._docsOk && qualificationSubjectsSatisfied(q) && q._consentOk,
    )

  const wd = props.application?.wizard_declarations
  const declarationsSaved = !!(wd?.terms_accepted_at && wd?.information_confirmed_at)

  return {
    applicant: applicantOk,
    qualification: qualificationDone,
    consent: declarationsSaved,
    payment: invoiceSettled.value,
  } as Record<StepKey, boolean>
})

const paymentInvoiceReady = computed(() => {
  return (
    stepCompletion.value.applicant &&
    stepCompletion.value.qualification &&
    stepCompletion.value.consent &&
    !applicationLocked.value
  )
})

const paymentInvoiceMissingSteps = computed(() => {
  const missing: StepKey[] = []
  if (!stepCompletion.value.applicant) missing.push('applicant')
  if (!stepCompletion.value.qualification) missing.push('qualification')
  if (!stepCompletion.value.consent) missing.push('consent')
  return missing
})

const invoicePreparation = ref<{ auto_attempted: boolean; auto_failed: boolean }>({ auto_attempted: false, auto_failed: false })

function prepareInvoice(auto = false) {
  if (prepareInvoiceForm.processing) return
  if (applicationLocked.value) return
  if (invoice.value) return

  // Avoid auto-retrying in a loop on validation/config errors.
  if (auto && invoicePreparation.value.auto_attempted) return

  if (auto) {
    invoicePreparation.value.auto_attempted = true
    invoicePreparation.value.auto_failed = false
  }

  prepareInvoiceForm.post(`/applicant/applications/${props.application.id}/payment/prepare`, {
    preserveScroll: true,
    onSuccess: () => {
      invoicePreparation.value.auto_failed = false
      router.reload({ only: ['application'] })
    },
    onError: () => {
      if (auto) invoicePreparation.value.auto_failed = true
    },
  })
}

watch(
  () => activeStep.value,
  (step) => {
    if (step !== 'payment') return
    if (paymentInvoiceMissingSteps.value.length > 0) return
    prepareInvoice(true)
  },
)

const disabledStepKeys = computed<StepKey[]>(() => {
  const keys = steps.value.map((s) => s.key) as StepKey[]
  // find first incomplete step; allow up to that step (inclusive)
  let firstIncompleteIndex = keys.findIndex((k) => !stepCompletion.value[k])
  if (firstIncompleteIndex === -1) firstIncompleteIndex = keys.length - 1
  const allowed = new Set(keys.slice(0, firstIncompleteIndex + 1))
  return keys.filter((k) => !allowed.has(k))
})

const stepNav = computed(() => {
  const keys = steps.value.map((s) => s.key) as StepKey[]
  const idx = keys.indexOf(activeStep.value)
  return {
    prev: idx > 0 ? keys[idx - 1] : null,
    next: idx >= 0 && idx < keys.length - 1 ? keys[idx + 1] : null,
  } as { prev: StepKey | null; next: StepKey | null }
})

function stepIncompleteHtml(step: StepKey): string | null {
  if (step === 'applicant') {
    const { missing } = evaluateApplicantStep()
    if (missing.length === 0) return null
    const items = missing.map((m) => `<li class="mt-1">${m}</li>`).join('')
    return `<ul class="list-disc pl-5 text-left text-sm text-text-primary">${items}</ul><p class="mt-3 text-xs text-text-muted">Save your details when you change them — use <strong>Save &amp; continue</strong> or your browser may still show old checks until the page refreshes.</p>`
  }
  if (step === 'qualification') {
    if (qualifications.value.length === 0) {
      return '<p class="text-sm text-left">Add at least one qualification and complete required documents for each.</p>'
    }
    const pending = qualificationRows.value.filter(
      (q) => !q._docsOk || !qualificationSubjectsSatisfied(q) || !q._consentOk,
    )
    if (pending.length === 0) return null
    const items = pending
      .map((q) => {
        const parts: string[] = []
        if (!q._docsOk) parts.push('documents')
        if (!qualificationSubjectsSatisfied(q)) parts.push('subject grades')
        if (!q._consentOk) parts.push('institution consent (open the qualification workspace)')
        return `<li class="mt-1"><span class="font-semibold">${(q.title_of_qualification ?? 'Qualification').toString()}</span>: complete ${parts.join(', ')}.</li>`
      })
      .join('')
    return `<ul class="list-disc pl-5 text-left text-sm">${items}</ul>`
  }
  if (step === 'consent') {
    const wd = props.application?.wizard_declarations
    if (wd?.terms_accepted_at && wd?.information_confirmed_at) return null
    if (!declarationsForm.accept_terms) {
      return '<p class="text-sm text-left">Tick the declaration checkbox to continue.</p>'
    }
    return '<p class="text-sm text-left">Click <strong>Continue to payment</strong> to record your confirmation and proceed.</p>'
  }
  if (step === 'payment') {
    return '<p class="text-sm text-left">Confirm payment for this application before continuing.</p>'
  }
  return null
}

function goNext(from: StepKey) {
  const keys = steps.value.map((s) => s.key) as StepKey[]
  const idx = keys.indexOf(from)
  const next = keys[idx + 1] ?? null
  if (!next) return

  if (!stepCompletion.value[from]) {
    const detailHtml = stepIncompleteHtml(from)
    void Swal.fire({
      icon: 'warning',
      title: from === 'applicant' ? 'Finish applicant details first' : 'Incomplete step',
      html: detailHtml ?? '<p class="text-sm">Complete the requirements for this step before continuing.</p>',
      showDenyButton: isStepDirty(from),
      confirmButtonText: 'Stay',
      denyButtonText: 'Discard changes',
      confirmButtonColor: '#0076BD',
      denyButtonColor: '#0B3A66',
    }).then((result) => {
      if (result.isDenied) {
        discardChangesForActiveStep()
      }
    })
    return
  }

  if (isStepDirty(from)) {
    void Swal.fire({
      icon: 'info',
      title: 'Unsaved changes',
      html:
        '<p class="text-sm text-left">You edited this step but have not saved. Save with <strong>Save &amp; continue</strong> (Applicant step) or your changes may be lost.</p>',
      showDenyButton: true,
      confirmButtonText: 'Stay',
      denyButtonText: 'Discard & continue',
      confirmButtonColor: '#0076BD',
      denyButtonColor: '#0B3A66',
    }).then((result) => {
      if (result.isDenied) {
        discardChangesForActiveStep()
        requestStepChange(next)
      }
    })
    return
  }

  requestStepChange(next)
}

// Prompt when leaving page with unsaved changes
function beforeUnload(e: BeforeUnloadEvent) {
  if (!hasUnsavedChanges.value) return
  e.preventDefault()
  e.returnValue = ''
}

onMounted(() => {
  window.addEventListener('beforeunload', beforeUnload)

  if (props.amendmentQualificationId) {
    activeStep.value = 'qualification'
    const q = qualifications.value.find((x: any) => Number(x.id) === Number(props.amendmentQualificationId))
    if (q) openQualificationWorkspace('edit', q)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnload)
})
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div
        class="w-full max-w-none mx-auto -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-8 2xl:-mx-10 2xl:px-10"
      >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Application</h1>
            <p class="mt-1 text-sm text-text-muted">
              {{ application.application_number }} • {{ displayStatusLabel }} — work through Applicant, Qualification, Confirm, then Payment.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <div
              v-if="saveState.state !== 'idle'"
              class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold"
              :class="
                saveState.state === 'saving'
                  ? 'border-brand/20 bg-brand/10 text-brand'
                  : saveState.state === 'saved'
                    ? 'border-success/20 bg-success/10 text-success'
                    : 'border-danger/20 bg-danger/10 text-danger'
              "
              role="status"
              aria-live="polite"
            >
              {{ saveState.message }}
            </div>

            <Link :href="`/applicant/applications/${application.id}`" class="zaqa-btn zaqa-btn-secondary px-3 text-sm">
              View
            </Link>
          </div>
        </div>

	        <div class="mt-4">
	          <WizardStepper :steps="steps" :active-key="activeStep" :on-step-click="requestStepChange" :disabled-keys="disabledStepKeys" />
	        </div>
	      </div>
	    </template>

	    <div class="w-full max-w-none mx-auto -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-8 2xl:-mx-10 2xl:px-10">
	      <WizardShell class="zaqa-wizard-shell" :show-sidebar="showSidebar">
        <section v-if="activeStep === 'applicant'" class="rounded-xl border border-border bg-surface p-5">
          <h2 class="text-sm font-semibold text-text-primary">Applicant details</h2>
          <p class="mt-1 text-xs text-text-muted">Confirm your details for communication and verification.</p>

          <div class="mt-4 rounded-2xl border border-border bg-surface-muted p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <div class="text-sm font-semibold text-text-primary">Verification subject</div>
                <div class="mt-1 text-xs text-text-muted">Who is this verification being submitted for?</div>
              </div>
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" :disabled="submittingForForm.processing" @click="saveSubmittingFor">
                Save
              </button>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label
                v-if="!institutionOnlyOnBehalf"
                class="zaqa-radio-card"
                :class="submittingForForm.submitting_for === 'self' ? 'zaqa-radio-card-active' : ''"
              >
                <input v-model="submittingForForm.submitting_for" type="radio" value="self" class="mt-1 rounded border-border text-brand focus:ring-brand/25" />
                <div>
                  <div class="text-sm font-semibold text-text-primary">Myself</div>
                  <div class="mt-1 text-xs text-text-muted">Uses your profile biodata (name + NRC/Passport).</div>
                </div>
              </label>
              <label class="zaqa-radio-card" :class="submittingForForm.submitting_for === 'other' ? 'zaqa-radio-card-active' : ''">
                <input v-model="submittingForForm.submitting_for" type="radio" value="other" class="mt-1 rounded border-border text-brand focus:ring-brand/25" />
                <div>
                  <div class="text-sm font-semibold text-text-primary">On behalf of someone</div>
                  <div class="mt-1 text-xs text-text-muted">Capture the subject’s biodata for this application.</div>
                </div>
              </label>
            </div>
            <InputError :message="(submittingForForm.errors as any).submitting_for" class="mt-2" />

            <div v-if="submittingForForm.submitting_for === 'self' && !institutionOnlyOnBehalf" class="mt-4 space-y-3">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-surface px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Name</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{ props.applicant?.name ?? '—' }}
                  </div>
                </div>
              </div>
              <p
                v-if="applicantType === 'individual'"
                class="mt-3 rounded-lg border border-border bg-surface px-3 py-2 text-xs text-text-muted"
              >
                Your NRC or passport number is entered once under <span class="font-semibold text-text-primary">Biodata</span> below — the same values apply to this application.
              </p>
            </div>

            <div v-else class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="text-sm font-medium">First name</label>
                <input v-model="submittingForForm.subject_first_name" class="zaqa-input" autocomplete="off" />
                <InputError :message="(submittingForForm.errors as any).subject_first_name" />
              </div>
              <div>
                <label class="text-sm font-medium">Other names (optional)</label>
                <input v-model="submittingForForm.subject_other_names" class="zaqa-input" autocomplete="off" />
                <InputError :message="(submittingForForm.errors as any).subject_other_names" />
              </div>
              <div>
                <label class="text-sm font-medium">Last name</label>
                <input v-model="submittingForForm.subject_last_name" class="zaqa-input" autocomplete="off" />
                <InputError :message="(submittingForForm.errors as any).subject_last_name" />
              </div>
              <div>
                <label class="text-sm font-medium">Gender</label>
                <select v-model="submittingForForm.gender" class="zaqa-input">
                  <option value="" disabled>Select gender</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
                <InputError :message="(submittingForForm.errors as any).gender" />
              </div>

              <div>
                <label class="text-sm font-medium">Identity type</label>
                <select v-model="submittingForForm.identity_type" class="zaqa-input">
                  <option value="nrc">NRC</option>
                  <option value="passport">Passport</option>
                </select>
                <InputError :message="(submittingForForm.errors as any).identity_type" />
              </div>
              <div>
                <label class="text-sm font-medium">{{ submittingForIdentityNumberLabel }}</label>
                <input v-model="submittingForForm.identity_number" class="zaqa-input" autocomplete="off" />
                <InputError :message="(submittingForForm.errors as any).identity_number" />
              </div>

              <div class="sm:col-span-2 rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
                <div class="text-sm font-semibold text-text-primary">Notification contact</div>
                <p class="mt-1 text-xs text-text-muted">
                  Choose where important updates for this application should be sent. The authenticated applicant will always be able to view the application from their account.
                </p>

                <div class="mt-4 grid gap-3">
                  <label
                    class="zaqa-radio-card"
                    :class="submittingForForm.notification_contact_mode === 'applicant_account' ? 'zaqa-radio-card-active' : ''"
                  >
                    <input
                      v-model="submittingForForm.notification_contact_mode"
                      type="radio"
                      value="applicant_account"
                      class="mt-1 rounded border-border text-brand focus:ring-brand/25"
                    />
                    <span>
                      <span class="block text-sm font-semibold text-text-primary">Use my account contact details</span>
                      <span class="mt-1 block text-xs text-text-muted">Updates will be sent to the email or phone number linked to your account.</span>
                    </span>
                  </label>

                  <label
                    class="zaqa-radio-card"
                    :class="submittingForForm.notification_contact_mode === 'additional_email' ? 'zaqa-radio-card-active' : ''"
                  >
                    <input
                      v-model="submittingForForm.notification_contact_mode"
                      type="radio"
                      value="additional_email"
                      class="mt-1 rounded border-border text-brand focus:ring-brand/25"
                    />
                    <span>
                      <span class="block text-sm font-semibold text-text-primary">Add another email recipient</span>
                      <span class="mt-1 block text-xs text-text-muted">A final approval, rejection, or certificate notification can also be sent to another email address.</span>
                    </span>
                  </label>
                </div>
                <InputError :message="(submittingForForm.errors as any).notification_contact_mode" class="mt-2" />

                <div v-if="submittingForForm.notification_contact_mode === 'additional_email'" class="mt-4 space-y-3">
                  <div>
                    <label class="text-sm font-medium text-text-primary">Additional recipient email</label>
                    <input v-model="submittingForForm.additional_notification_email" type="email" class="zaqa-input" autocomplete="off" />
                    <p class="mt-1 text-xs text-text-muted">This does not create a portal account. The application will still remain under your account.</p>
                    <p v-if="additionalEmailMatchesAccount" class="mt-1 text-xs text-warning">
                      This matches your account email. Updates will only be sent once to your account address.
                    </p>
                    <InputError :message="(submittingForForm.errors as any).additional_notification_email" />
                  </div>
                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <label class="text-sm font-medium text-text-primary">Recipient name (optional)</label>
                      <input v-model="submittingForForm.additional_notification_name" class="zaqa-input" autocomplete="off" />
                      <InputError :message="(submittingForForm.errors as any).additional_notification_name" />
                    </div>
                    <div>
                      <label class="text-sm font-medium text-text-primary">Relationship (optional)</label>
                      <input v-model="submittingForForm.additional_notification_relationship" class="zaqa-input" autocomplete="off" placeholder="e.g. parent, employer" />
                      <InputError :message="(submittingForForm.errors as any).additional_notification_relationship" />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-6 rounded-2xl border border-border bg-surface-muted/40 p-5">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <div class="text-sm font-semibold text-text-primary">Holder identity document</div>
                <p class="mt-1 text-xs text-text-muted">
                  A clear copy of the holder’s NRC or passport is required before you can submit. If you apply for yourself and already uploaded an identity document on your profile, you do not need to upload again here.
                </p>
              </div>
            </div>

            <div
              v-if="
                (props.application?.metadata?.submitting_for ?? 'self') === 'other'
                  ? !hasApplicationIdentityDoc()
                  : !hasApplicationIdentityDoc() && !props.applicant?.applicant_profile?.identity_document_uploaded_at
              "
              class="mt-4"
            >
              <div v-if="identityUploadDisabled" class="mb-3 rounded-lg border border-warning/25 bg-warning/10 px-3 py-2 text-xs text-warning">
                Save the verification subject selection above before uploading an identity document.
              </div>

              <label class="text-sm font-medium text-text-primary">{{ identityUploadLabel }}</label>
              <input
                type="file"
                class="zaqa-input mt-2"
                accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                :disabled="identityUploadDisabled"
                @change="(e) => {
                  const t = e.target as HTMLInputElement
                  identityUploadForm.file = t.files?.[0] ?? null
                }"
              />
              <InputError :message="identityUploadForm.errors.file" class="mt-1" />

              <div class="mt-3 flex flex-wrap gap-2">
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
                  :disabled="identityUploadDisabled || !identityUploadForm.file"
                  @click="uploadIdentityDocument"
                >
                  Upload document
                </button>
              </div>
            </div>
            <div
              v-else
              class="mt-4 flex items-start gap-3 rounded-xl border border-success/25 bg-success/10 px-4 py-3 text-sm text-text-primary"
            >
              <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-success" aria-hidden="true" />
              <div>
                <div class="font-semibold">Identity document on file</div>
                <p class="mt-1 text-xs text-text-muted">
                  {{
                    hasApplicationIdentityDoc()
                      ? 'Stored on this application.'
                      : 'Using the identity document saved on your applicant profile.'
                  }}
                </p>
              </div>
            </div>
          </div>

          <form class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="saveApplicantDetails">
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Email (required if no phone number provided)</label>
              <input v-model="applicantForm.email" type="email" class="zaqa-input" />
              <InputError :message="applicantForm.errors.email" />
            </div>

            <div>
              <label class="text-sm font-medium">Primary phone (required if no email provided)</label>
              <input v-model="applicantForm.phone_primary" class="zaqa-input" />
              <InputError :message="applicantForm.errors.phone_primary" />
            </div>
            <div>
              <label class="text-sm font-medium">Secondary phone (optional)</label>
              <input v-model="applicantForm.phone_secondary" class="zaqa-input" />
              <InputError :message="applicantForm.errors.phone_secondary" />
            </div>

            <div class="sm:col-span-2 text-xs text-text-muted">
              Provide at least one contact method: <span class="font-semibold text-text-primary">email</span> or <span class="font-semibold text-text-primary">primary phone</span>.
            </div>

            <template v-if="applicantType === 'institution'">
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Institution name</label>
                <input v-model="applicantForm.institution_name" class="zaqa-input" />
                <InputError :message="applicantForm.errors.institution_name" />
              </div>
              <div>
                <label class="text-sm font-medium">TPIN (optional)</label>
                <input v-model="applicantForm.tpin" class="zaqa-input" />
                <InputError :message="applicantForm.errors.tpin" />
              </div>
              <div>
                <label class="text-sm font-medium">Contact person</label>
                <input v-model="applicantForm.contact_person_name" class="zaqa-input" />
                <InputError :message="applicantForm.errors.contact_person_name" />
              </div>
            </template>

            <template v-else>
              <div>
                <label class="text-sm font-medium">First name</label>
                <input v-model="applicantForm.first_name" class="zaqa-input" />
                <InputError :message="applicantForm.errors.first_name" />
              </div>
              <div>
                <label class="text-sm font-medium">Middle name (optional)</label>
                <input v-model="applicantForm.middle_name" class="zaqa-input" />
                <InputError :message="applicantForm.errors.middle_name" />
              </div>
              <div>
                <label class="text-sm font-medium">Surname</label>
                <input v-model="applicantForm.surname" class="zaqa-input" />
                <InputError :message="applicantForm.errors.surname" />
              </div>
              <div>
                <label class="text-sm font-medium">Gender</label>
                <select v-model="applicantForm.gender" class="zaqa-input">
                  <option value="" disabled>Select gender</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
                <InputError :message="applicantForm.errors.gender" />
              </div>
              <div>
                <label class="text-sm font-medium">Identity type</label>
                <select v-model="applicantForm.identity_type" class="zaqa-input">
                  <option value="nrc">NRC</option>
                  <option value="passport">Passport</option>
                </select>
                <InputError :message="applicantForm.errors.identity_type" />
              </div>
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">{{ applicantIdentityNumberLabel }}</label>
                <input v-model="applicantForm.identity_number" class="zaqa-input" autocomplete="off" />
                <InputError :message="applicantForm.errors.identity_number" />
              </div>
            </template>

            <div class="sm:col-span-2">
              <WizardFooterBar
                :show-prev="!!stepNav.prev"
                :show-next="!!stepNav.next"
                prev-label="Previous"
                next-label="Next"
                :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
                :on-next="() => goNext('applicant')"
              >
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto"
                  :disabled="applicantForm.processing || (!applicantForm.isDirty && applicantStepGate.ok)"
                  @click="saveApplicantDetails('qualification')"
                >
                  Save & continue
                </button>
              </WizardFooterBar>
            </div>
          </form>
        </section>

        <section v-else-if="activeStep === 'qualification'" class="rounded-2xl border border-border bg-gradient-to-b from-surface to-surface-muted/25 p-6 shadow-sm ring-1 ring-black/[0.04] sm:p-8">
          <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
              <div class="inline-flex items-center gap-2 rounded-full border border-brand/20 bg-brand/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand">
                <GraduationCap class="h-3.5 w-3.5" aria-hidden="true" />
                Qualifications
              </div>
              <h2 class="mt-3 text-2xl font-semibold tracking-tight text-text-primary">Qualifications for verification</h2>
              <p class="mt-2 text-sm leading-relaxed text-text-muted">
                Add the qualifications you want verified.
              </p>
            </div>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary inline-flex h-11 shrink-0 items-center gap-2 px-5 text-sm font-semibold shadow-md shadow-brand/15"
              :disabled="applicationLocked || correctionRequiredMode"
              @click="openQualificationWorkspace('add')"
            >
              <PlusCircle class="h-4 w-4" aria-hidden="true" />
              Add qualification
            </button>
          </div>

          <div
            v-if="qualificationsReturnedForAmendment.length > 0"
            class="mt-6 space-y-4"
          >
            <div class="rounded-2xl border border-brand/25 bg-brand/[0.06] px-5 py-4 sm:px-6">
              <div class="text-sm font-semibold text-text-primary">Submit corrections back to ZAQA</div>
              <p class="mt-1 text-sm leading-relaxed text-text-muted">
                Updating details in the workspace only saves your draft. When you are satisfied with a qualification, submit it here so verification staff can continue your case.
              </p>
            </div>
            <div v-for="q in qualificationsReturnedForAmendment" :key="'ret-' + q.id" class="space-y-3">
              <QualificationAmendmentBanner :application-id="application.id" :qualification="q" />
              <div class="flex justify-end">
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-primary shrink-0 px-5"
                  :disabled="finalizeAmendmentForm.processing || !applicationPaymentSatisfied"
                  @click="submitCorrectionsToZaqa(q.id)"
                >
                  Submit corrections to ZAQA
                </button>
              </div>
            </div>
            <p v-if="!applicationPaymentSatisfied" class="mt-3 text-sm text-amber-900">
              Pay any additional balance on the
              <button type="button" class="font-semibold underline decoration-brand/40 underline-offset-2 hover:text-brand" @click="goToStep('payment')">
                Payment
              </button>
              step before submitting corrections
              <span v-if="paymentOutstandingCents > 0"> ({{ formatMoneyCents(paymentOutstandingCents) }} outstanding)</span>.
            </p>
            <p v-if="application?.fee_amendment_overpayment_notice" class="mt-2 text-xs leading-relaxed text-amber-900">
              {{ application.fee_amendment_overpayment_notice }}
            </p>
            <p v-else class="mt-2 text-xs leading-relaxed text-text-muted">
              If a qualification change lowered your fee and you overpaid, refunds are not issued automatically — contact finance by email.
            </p>
            <InputError :message="(finalizeAmendmentForm.errors as any).payment" class="mt-2" />
            <InputError :message="(finalizeAmendmentForm.errors as any).qualification" class="mt-1" />
          </div>

          <div v-if="applicationLocked" class="mt-6 rounded-xl border border-warning/25 bg-warning/10 px-4 py-3 text-sm text-warning">
            {{ applicationLockMessage }}
          </div>

          <div class="mt-8 grid gap-8">
            <div class="space-y-4">
              <div v-if="qualificationRows.length === 0" class="rounded-2xl border border-dashed border-border bg-surface-muted/30 px-6 py-14 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                  <GraduationCap class="h-7 w-7" aria-hidden="true" />
                </div>
                <p class="mt-4 text-base font-semibold text-text-primary">No qualifications yet</p>
                <p class="mt-2 max-w-md mx-auto text-sm text-text-muted">
                  Certificates, diplomas, and degrees can be added here..
                </p>
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-primary mt-6 inline-flex items-center gap-2 px-6"
                  :disabled="applicationLocked"
                  @click="openQualificationWorkspace('add')"
                >
                  <PlusCircle class="h-4 w-4" aria-hidden="true" />
                  Add qualification
                </button>
              </div>

              <div v-else class="grid gap-4">
                <article
                  v-for="q in qualificationRows"
                  :key="q.id"
                  class="overflow-hidden rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:border-brand/20 hover:shadow-md"
                  :class="Number(selectedQualificationId) === Number(q.id) ? 'ring-2 ring-brand/20' : ''"
                >
                  <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                      <button type="button" class="w-full text-left" @click="selectedQualificationId = q.id">
                        <div class="flex flex-wrap items-center gap-2">
                          <h3 class="text-lg font-semibold text-text-primary">{{ q.title_of_qualification || 'Untitled qualification' }}</h3>
                          <span class="zaqa-badge text-xs" :class="q._isForeign ? 'zaqa-badge-warning' : 'zaqa-badge-success'">
                            {{ q._isForeign ? 'Outside Zambia' : 'Zambia' }}
                          </span>
                        </div>
                        <p class="mt-1 text-sm text-text-muted">Award date {{ q.award_date || '—' }}</p>
                        <p class="mt-2 rounded-xl border border-brand/20 bg-brand/[0.05] px-3 py-2 text-sm text-text-primary">
                          <span class="text-[10px] font-bold uppercase tracking-wider text-brand">Names on qualification document</span>
                          <span class="mt-1 block font-semibold">{{ q.names_as_on_qualification_document?.trim() || 'Not captured' }}</span>
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-medium">
                          <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-muted px-2.5 py-1 text-text-primary">
                            Documents
                            <span :class="q._docsOk ? 'text-emerald-700' : 'text-warning'">{{ q._docsOk ? 'Ready' : 'Needed' }}</span>
                          </span>
                          <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-muted px-2.5 py-1 text-text-primary">
                            Consent
                            <span :class="q._consentOk ? 'text-emerald-700' : 'text-warning'">{{ q._consentOk ? 'Ready' : 'Needed' }}</span>
                          </span>
                        </div>
                      </button>
                      <div
                        v-if="q.cveq_certificate"
                        class="mt-4 rounded-xl border border-emerald-300/50 bg-emerald-50/90 px-4 py-3 text-left"
                      >
                        <div class="text-xs font-bold uppercase tracking-wider text-emerald-900">ZAQA verification certificate</div>
                        <p class="mt-1 text-sm text-emerald-950">
                          <span class="font-mono font-semibold">{{ q.cveq_certificate.certificate_number }}</span>
                          <span v-if="q.cveq_certificate.issued_at" class="text-xs text-emerald-800">
                            · Issued {{ formatCveqIssuedAt(q.cveq_certificate.issued_at) }}
                          </span>
                        </p>
                        <a
                          v-if="q.cveq_certificate.download_url"
                          :href="q.cveq_certificate.download_url"
                          class="zaqa-btn zaqa-btn-secondary mt-3 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
                        >
                          <FileDown class="h-4 w-4 shrink-0" aria-hidden="true" />
                          Download PDF
                        </a>
                      </div>
                      <div
                        v-else-if="q.rejection_notice"
                        class="mt-4 rounded-xl border border-rose-300/50 bg-rose-50/90 px-4 py-3 text-left"
                      >
                        <div class="text-xs font-bold uppercase tracking-wider text-rose-900">ZAQA rejection notice</div>
                        <p class="mt-1 text-sm text-rose-950">
                          <span class="font-mono font-semibold">{{ q.rejection_notice.certificate_number }}</span>
                          <span v-if="q.rejection_notice.issued_at" class="text-xs text-rose-800">
                            · Issued {{ formatCveqIssuedAt(q.rejection_notice.issued_at) }}
                          </span>
                        </p>
                        <a
                          v-if="q.rejection_notice.download_url"
                          :href="q.rejection_notice.download_url"
                          class="zaqa-btn zaqa-btn-secondary mt-3 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold"
                        >
                          <FileDown class="h-4 w-4 shrink-0" aria-hidden="true" />
                          Download rejection notice
                        </a>
                      </div>
                      <div
                        v-else-if="q.rejection_notice_recalled"
                        class="mt-4 rounded-xl border border-amber-300/50 bg-amber-50/90 px-4 py-3 text-sm text-amber-950"
                      >
                        A previous rejection notice was recalled. Please refer to the latest decision on this qualification.
                      </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                      <button
                        type="button"
                        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
                        @click="openQualificationWorkspace('edit', q)"
                      >
                        <PenLine class="h-4 w-4" aria-hidden="true" />
                        Edit application
                      </button>
                      <button
                        v-if="!applicationLocked"
                        type="button"
                        class="zaqa-btn border border-danger/25 bg-danger/10 px-4 py-2 text-sm font-semibold text-danger hover:bg-danger/15"
                        @click="removeQualification(q.id)"
                      >
                        Remove
                      </button>
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </div>

          <div class="mt-10">
            <WizardFooterBar
              :show-prev="!!stepNav.prev"
              :show-next="!!stepNav.next"
              prev-label="Previous"
              next-label="Next"
              :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
              :on-next="() => goNext('qualification')"
            />
          </div>
        </section>

        <section v-else-if="activeStep === 'consent'" class="px-1 py-2 sm:px-0">
          <div v-if="applicationLocked" class="mx-auto mb-4 w-full max-w-3xl rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning">
            {{ applicationLockMessage }}
          </div>

          <div class="mx-auto w-full max-w-3xl">
            <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]">
              <div class="border-b border-border px-6 py-5 sm:px-8">
                <h2 class="text-xl font-semibold tracking-tight text-text-primary sm:text-2xl">Confirm your application</h2>
                <p class="mt-2 text-sm leading-relaxed text-text-muted">
                  Please review the details below and confirm that ZAQA may process your application.
                </p>
              </div>

              <div class="border-b border-border bg-surface-muted/25 px-6 py-4 sm:px-8">
                <p class="text-sm text-text-muted">
                  <template v-if="consentSubjectSummary.submittingFor === 'other'">
                    You are submitting this application on behalf of:
                    <span class="font-semibold text-text-primary">{{ consentSubjectSummary.fullName }}</span>
                  </template>
                  <template v-else>
                    You are personally authorizing ZAQA to verify your qualifications.
                  </template>
                </p>

                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-6">
                  <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Full name</dt>
                    <dd class="mt-1 truncate text-sm font-semibold text-text-primary">{{ consentSubjectSummary.fullName }}</dd>
                  </div>
                  <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Identification</dt>
                    <dd class="mt-1 truncate font-mono text-sm font-semibold text-text-primary">
                      {{ consentSubjectSummary.identification }}
                    </dd>
                  </div>
                  <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application reference</dt>
                    <dd class="mt-1 truncate font-mono text-sm font-semibold text-text-primary">
                      {{ consentSubjectSummary.applicationReference }}
                    </dd>
                  </div>
                </dl>
              </div>

              <div class="px-6 py-5 sm:px-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <h3 class="text-sm font-semibold text-brand">Consent and verification authorization</h3>
                  <button
                    type="button"
                    class="shrink-0 text-left text-sm font-semibold text-brand hover:underline sm:text-right"
                    @click="openTermsModal"
                  >
                    View full consent terms
                  </button>
                </div>

                <p class="mt-4 text-sm leading-relaxed text-text-primary">By continuing, I confirm that:</p>

                <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-text-muted">
                  <li class="flex gap-2.5">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                    <span>I authorize ZAQA to verify the qualification information submitted in this application.</span>
                  </li>
                  <li class="flex gap-2.5">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                    <span>I confirm that the submitted information and documents are accurate and complete.</span>
                  </li>
                  <li class="flex gap-2.5">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                    <span>I understand that ZAQA may use the submitted information for verification and evaluation purposes.</span>
                  </li>
                  <li class="flex gap-2.5">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                    <span
                      >I understand that incomplete, inaccurate, or misleading information may delay processing or lead to
                      rejection.</span
                    >
                  </li>
                  <li class="flex gap-2.5">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                    <span
                      >I understand that my account, date, and time of confirmation may be recorded as part of the application
                      audit trail.</span
                    >
                  </li>
                </ul>
              </div>

              <div class="border-t border-border px-6 py-5 sm:px-8">
                <h3 class="text-sm font-semibold text-text-primary">Your confirmation</h3>

                <div class="mt-4 space-y-3">
                  <label
                    class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-surface-muted/30 px-4 py-3.5 transition-colors hover:bg-surface-muted/50"
                  >
                    <input
                      v-model="declarationsForm.accept_terms"
                      type="checkbox"
                      class="mt-0.5 h-4 w-4 shrink-0 rounded border-border text-brand focus:ring-brand/30"
                      :disabled="applicationLocked"
                    />
                    <span class="text-sm leading-relaxed text-text-primary">I consent to the verification terms above.</span>
                  </label>
                  <InputError :message="declarationsForm.errors.accept_terms" />
                </div>
              </div>

              <div class="border-t border-border bg-surface-muted/30 px-6 py-3.5 sm:px-8">
                <p class="text-xs leading-relaxed text-text-muted">
                  Your information is securely processed by ZAQA. You can review and edit your draft until payment is confirmed.
                </p>
              </div>
            </div>
          </div>

          <div class="mx-auto mt-8 w-full max-w-3xl">
            <WizardFooterBar
              :show-prev="!!stepNav.prev"
              :show-next="!!stepNav.next"
              prev-label="Previous"
              next-label="Continue to payment"
              :prev-disabled="applicationLocked || declarationsForm.processing"
              :next-disabled="
                applicationLocked ||
                declarationsForm.processing ||
                !declarationsForm.accept_terms
              "
              :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
              :on-next="saveDeclarations"
            >
              <span
                v-if="application?.wizard_declarations?.terms_accepted_at && application?.wizard_declarations?.information_confirmed_at"
                class="inline-flex items-center gap-2 rounded-full border border-success/20 bg-success/10 px-3 py-1 text-xs font-semibold text-success"
              >
                <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
                Confirmed
              </span>
            </WizardFooterBar>
          </div>
        </section>

	        <section v-else-if="activeStep === 'payment'" class="rounded-xl border border-border bg-surface p-4 sm:p-5">
	          <div>
	            <h2 class="text-sm font-semibold text-text-primary">Payment</h2>
	            <p class="mt-1 text-xs text-text-muted">Choose a payment method below.</p>
	          </div>

	          <!-- Invoice summary -->
	          <div class="mt-3 rounded-xl border border-border bg-surface p-3 shadow-sm ring-1 ring-black/[0.04] sm:p-4">
	            <div class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-x-4 sm:gap-y-2">
	              <div class="flex min-w-0 flex-1 flex-col gap-1.5 text-xs sm:flex-row sm:flex-wrap sm:items-baseline sm:gap-x-4 sm:gap-y-1">
	                <div class="min-w-0">
	                  <span class="text-text-muted">Invoice:</span>
	                  <span class="ml-1 font-semibold text-text-primary">
	                    {{ invoice?.invoice_number ?? (paymentInvoiceReady ? 'Preparing…' : '—') }}
	                  </span>
	                </div>
	                <div class="min-w-0">
	                  <span class="text-text-muted">Application:</span>
	                  <span class="ml-1 font-mono font-semibold text-text-primary">{{ application.application_number }}</span>
	                </div>
	              </div>
	              <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
	                <span class="zaqa-badge text-xs" :class="paymentStatusBadgeClass(payment?.status)">
	                  <component :is="(payment?.status ?? '') === 'confirmed' ? CheckCircle2 : AlertCircle" class="h-3.5 w-3.5" aria-hidden="true" />
	                  {{ paymentStatusLabel(payment?.status) }}
	                </span>
	                <a
	                  v-if="invoice?.download_url"
	                  :href="invoice.download_url"
	                  class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs"
	                >
	                  Download invoice
	                </a>
	                <a
	                  v-if="payment?.receipt_download_url"
	                  :href="payment.receipt_download_url"
	                  class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs"
	                >
	                  Download receipt
	                </a>
	              </div>
	            </div>

	            <div
	              v-if="invoice && invoiceLineItems.length > 0"
	              class="mt-3 border-t border-border/50 pt-3"
	            >
	              <div class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Fee breakdown</div>
	              <div class="mt-1.5 -mx-0.5 overflow-x-auto px-0.5">
	                <table class="w-full min-w-[280px] border-collapse text-[11px] sm:text-xs">
	                  <thead>
	                    <tr class="border-b border-border/40 text-[9px] font-semibold uppercase tracking-wider text-text-muted sm:text-[10px]">
	                      <th class="pb-1.5 pr-2 text-left font-semibold">Item</th>
	                      <th class="pb-1.5 pr-2 text-right font-semibold whitespace-nowrap">Qty</th>
	                      <th class="hidden pb-1.5 pr-2 text-right font-semibold whitespace-nowrap sm:table-cell">Amount</th>
	                      <th class="pb-1.5 text-right font-semibold whitespace-nowrap">Total</th>
	                    </tr>
	                  </thead>
	                  <tbody>
	                    <tr
	                      v-for="(row, idx) in invoiceLineItems"
	                      :key="`${row.description}-${idx}`"
	                      class="border-b border-border/25 last:border-b-0"
	                    >
	                      <td class="py-1.5 pr-2 align-top font-medium leading-snug text-text-primary">
	                        {{ row.description }}
	                      </td>
	                      <td class="py-1.5 pr-2 text-right align-top tabular-nums text-text-muted whitespace-nowrap">
	                        {{ row.quantity }}
	                      </td>
	                      <td class="hidden py-1.5 pr-2 text-right align-top tabular-nums text-text-muted whitespace-nowrap sm:table-cell">
	                        {{ formatMoneyCents(row.amount_cents) }}
	                      </td>
	                      <td class="py-1.5 text-right align-top tabular-nums text-text-primary whitespace-nowrap">
	                        {{ formatMoneyCents(row.total_cents) }}
	                      </td>
	                    </tr>
	                  </tbody>
	                  <tfoot>
	                    <tr class="border-t border-border/50">
	                      <td class="hidden pt-2 pr-2 text-right text-[11px] font-semibold text-text-primary sm:table-cell" colspan="3">Total</td>
	                      <td class="pt-2 pr-2 text-right text-[11px] font-semibold text-text-primary sm:hidden" colspan="2">Total</td>
	                      <td class="pt-2 text-right text-sm font-semibold tabular-nums text-text-primary whitespace-nowrap">
	                        {{ formatMoneyCents(Number(invoice.amount_cents ?? invoiceBreakdownTotalCents)) }}
	                      </td>
	                    </tr>
	                  </tfoot>
	                </table>
	              </div>
	            </div>

	            <div
	              v-if="application?.supplementary_invoice"
	              class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-950"
	            >
	              <div class="font-semibold text-text-primary">Supplementary invoice (top-up)</div>
	              <div class="mt-0.5 font-mono text-[11px]">{{ application.supplementary_invoice.invoice_number }}</div>
	              <div class="mt-0.5">
	                Balance due:
	                <span class="font-semibold">{{ formatMoneyCents(Number(application.supplementary_invoice.amount_cents ?? 0)) }}</span>
	              </div>
	              <p v-if="application.supplementary_invoice.amendment_reason" class="mt-1.5 text-[11px] leading-relaxed opacity-90">
	                {{ application.supplementary_invoice.amendment_reason }}
	              </p>
	              <a
	                v-if="application.supplementary_invoice.download_url"
	                :href="application.supplementary_invoice.download_url"
	                class="zaqa-btn zaqa-btn-secondary mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold"
	              >
	                Download supplementary invoice
	              </a>
	            </div>
	            <p
	              v-else-if="invoice"
	              class="mt-2 border-t border-border/30 pt-2 text-[11px] leading-snug text-text-muted"
	            >
	              You can edit your application until payment is confirmed.
	            </p>

	            <div v-if="!invoice && !applicationLocked" class="mt-3 flex flex-col gap-2 border-t border-border/50 pt-3 sm:flex-row sm:items-center sm:justify-between">
	              <div class="text-xs text-text-muted">
	                <span v-if="paymentInvoiceMissingSteps.length > 0">
	                  Complete the previous steps to unlock payment.
	                </span>
	                <span v-else-if="prepareInvoiceForm.processing">
	                  Preparing your invoice…
	                </span>
	                <span v-else-if="invoicePreparation.auto_failed">
	                  We could not prepare your invoice. Please retry.
	                </span>
	                <span v-else>
	                  Preparing your invoice…
	                </span>
	              </div>
	              <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
	                <button
	                  v-if="paymentInvoiceMissingSteps.length > 0"
	                  type="button"
	                  class="zaqa-btn zaqa-btn-secondary shrink-0 px-3 py-1.5 text-xs"
	                  @click="requestStepChange(paymentInvoiceMissingSteps[0])"
	                >
	                  Go to {{ steps.find((s) => s.key === paymentInvoiceMissingSteps[0])?.label ?? 'previous step' }}
	                </button>
	                <button
	                  v-else-if="invoicePreparation.auto_failed"
	                  type="button"
	                  class="zaqa-btn zaqa-btn-secondary shrink-0 px-3 py-1.5 text-xs"
	                  :disabled="prepareInvoiceForm.processing"
	                  @click="prepareInvoice(false)"
	                >
	                  Retry invoice preparation
	                </button>
	              </div>
	            </div>
	          </div>

	          <!-- Payment tabs -->
	          <div class="mt-3">
            <!-- Confirmed state (read-only) -->
	            <div v-if="invoiceSettled" class="rounded-2xl border border-success/20 bg-success/10 p-4 sm:p-5">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <div class="text-sm font-semibold text-success">Payment confirmed</div>
                  <div class="mt-1 text-xs text-text-muted">
                    This invoice is settled. Your application has been automatically submitted for verification.
                  </div>
                </div>
                <span class="zaqa-badge zaqa-badge-success">Paid</span>
              </div>

              <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-surface px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Invoice number</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ invoice?.invoice_number ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Amount paid</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{ ((invoice?.amount_cents ?? 0) / 100).toFixed(2) }} {{ invoice?.currency ?? 'ZMW' }}
                  </div>
                </div>
                <div class="rounded-xl border border-border bg-surface px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Method</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ payment?.method ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Reference</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ payment?.provider_reference ?? payment?.provider_transaction_id ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface px-4 py-3 sm:col-span-2">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Confirmed at</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ payment?.confirmed_at ?? application.paid_at ?? '—' }}</div>
                </div>
              </div>
            </div>

		            <div v-else-if="invoice">
              <div
                v-if="paymentAwaitingFinanceReview"
                class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 shadow-sm ring-1 ring-black/[0.04] sm:p-5"
              >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <div class="text-sm font-semibold text-text-primary">Proof submitted - awaiting finance review</div>
                    <div class="mt-1 text-xs text-text-muted">
                      Finance will review your proof of payment before your application can proceed.
                    </div>
                  </div>
                  <span class="zaqa-badge zaqa-badge-warning">{{ paymentStatusLabel(payment?.status) }}</span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                  <div class="rounded-xl bg-surface px-4 py-3 ring-1 ring-black/[0.04]">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Submitted</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">
                      {{ formatDateTimeValue(payment?.awaiting_finance_review_at ?? payment?.created_at) }}
                    </div>
                  </div>
                  <div class="rounded-xl bg-surface px-4 py-3 ring-1 ring-black/[0.04]">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Proof file</div>
                    <div class="mt-1 truncate text-sm font-semibold text-text-primary">
                      {{ payment?.proof_document?.original_name ?? 'Uploaded proof' }}
                    </div>
                  </div>
                </div>

                <div v-if="payment?.proof_document" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                  <a :href="payment.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto">
                    Preview proof
                  </a>
                  <a :href="payment.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto">
                    Download proof
                  </a>
                </div>

                <div class="mt-4 inline-flex items-start gap-2 rounded-xl bg-surface px-3 py-2 text-xs text-text-muted ring-1 ring-black/[0.04]">
                  <Lock class="mt-0.5 h-4 w-4 text-brand" aria-hidden="true" />
                  <span>You can make changes again only if finance rejects this proof.</span>
                </div>
              </div>

		              <template v-else>
		              <div
		                class="flex gap-2 overflow-x-auto rounded-2xl bg-surface p-2 shadow-sm ring-1 ring-black/[0.04] sm:grid sm:grid-cols-3 sm:overflow-visible"
		                role="tablist"
		                aria-label="Payment method"
		              >
		                <button
		                  type="button"
		                  role="tab"
		                  :aria-selected="activePaymentTab === 'card'"
		                  class="flex min-w-[9.5rem] shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-background sm:min-w-0 sm:w-full"
		                  :class="activePaymentTab === 'card' ? 'bg-brand/10 text-brand ring-1 ring-brand/20 shadow-sm' : 'text-text-muted hover:bg-surface-muted'"
		                  @click="setPaymentTab('card')"
		                >
		                  <CreditCard class="h-4 w-4" aria-hidden="true" />
	                  <span>Card payment</span>
	                </button>
		                <button
		                  type="button"
		                  role="tab"
		                  :aria-selected="activePaymentTab === 'bank_transfer'"
		                  class="flex min-w-[9.5rem] shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-background sm:min-w-0 sm:w-full"
		                  :class="activePaymentTab === 'bank_transfer' ? 'bg-brand/10 text-brand ring-1 ring-brand/20 shadow-sm' : 'text-text-muted hover:bg-surface-muted'"
		                  @click="setPaymentTab('bank_transfer')"
		                >
		                  <Landmark class="h-4 w-4" aria-hidden="true" />
	                  <span>Bank Deposit or Transfer</span>
	                </button>
		                <button
		                  type="button"
		                  role="tab"
		                  :aria-selected="activePaymentTab === 'mobile_money'"
		                  class="flex min-w-[9.5rem] shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-background sm:min-w-0 sm:w-full"
		                  :class="activePaymentTab === 'mobile_money' ? 'bg-brand/10 text-brand ring-1 ring-brand/20 shadow-sm' : 'text-text-muted hover:bg-surface-muted'"
		                  @click="setPaymentTab('mobile_money')"
		                >
		                  <Smartphone class="h-4 w-4" aria-hidden="true" />
	                  <span>Mobile Money</span>
	                </button>
	              </div>
	
		              <div class="mt-3 rounded-2xl bg-surface p-4 shadow-sm ring-1 ring-black/[0.04] sm:mt-4 sm:p-5">
              <!-- Card -->
              <div v-if="activePaymentTab === 'card'">
                <div class="text-sm font-semibold text-text-primary">Pay by card</div>
                <div class="mt-1 text-xs text-text-muted">You’ll be redirected to the payment gateway and returned here after the attempt.</div>

	                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
	                  <button
	                    type="button"
	                    class="zaqa-btn zaqa-btn-primary w-full sm:w-auto"
	                    :disabled="(payment?.status ?? '') === 'confirmed' || cardInitiateForm.processing"
	                    @click="initiateCardPayment"
	                  >
	                    Pay by card
	                  </button>
		                  <div class="text-xs text-text-muted">
		                    Status: <span class="font-semibold text-text-primary">{{ paymentStatusLabel(payment?.status) }}</span>
		                  </div>
		                </div>
		              </div>

              <!-- Bank transfer -->
	              <div v-else-if="activePaymentTab === 'bank_transfer'">
	                <div class="text-sm font-semibold text-text-primary">Pay by bank transfer or deposit</div>
	                <div class="mt-1 text-xs text-text-muted">
	                  Transfer the invoice amount to the ZAQA account below, then upload your proof of payment for finance review.
	                </div>

	                <div class="mt-4 rounded-xl border border-brand/15 bg-brand/5 p-4">
	                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Deposit account details</div>
	                  <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
	                    <div>
	                      <dt class="text-xs font-medium text-text-muted">Bank name</dt>
	                      <dd class="mt-1 text-sm font-semibold text-text-primary">{{ bankDepositField(bankDepositAccount?.bank_name) }}</dd>
	                    </div>
	                    <div>
	                      <dt class="text-xs font-medium text-text-muted">Branch code</dt>
	                      <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ bankDepositField(bankDepositAccount?.branch_code) }}</dd>
	                    </div>
	                    <div>
	                      <dt class="text-xs font-medium text-text-muted">Account name</dt>
	                      <dd class="mt-1 text-sm font-semibold text-text-primary">{{ bankDepositField(bankDepositAccount?.account_name) }}</dd>
	                    </div>
	                    <div>
	                      <dt class="text-xs font-medium text-text-muted">Account number</dt>
	                      <dd class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ bankDepositField(bankDepositAccount?.account_number) }}</dd>
	                    </div>
	                  </dl>
	                  <p class="mt-3 text-xs leading-relaxed text-text-muted">
	                    Use
	                    <span class="font-mono font-semibold text-text-primary">{{ bankDepositReference }}</span>
	                    as your payment reference where possible.
	                  </p>
	                </div>

	                <div class="mt-4 text-sm font-semibold text-text-primary">Upload proof of payment</div>
	                <div class="mt-1 text-xs text-text-muted">
	                  Upload your bank transfer proof or bank deposit slip once payment has been made.
	                </div>
	
	                <div class="mt-3 flex items-center justify-between gap-3">
		                  <span class="zaqa-badge" :class="paymentStatusBadgeClass(payment?.status)">
		                    {{ paymentStatusLabel(payment?.status) }}
		                  </span>
		                  <div v-if="payment?.proof_document" class="flex flex-wrap gap-2 text-xs">
		                    <a :href="payment.proof_document.preview_url" target="_blank" rel="noopener" class="zaqa-link">Preview proof</a>
		                    <a :href="payment.proof_document.download_url" target="_blank" rel="noopener" class="zaqa-link">Download proof</a>
		                  </div>
	                </div>

                <div v-if="payment?.rejection_reason" class="mt-3 rounded-lg border border-danger/20 bg-danger/10 px-3 py-2 text-xs text-danger">
                  Rejected: {{ payment.rejection_reason }}
                </div>

                <div class="mt-4">
                  <label class="text-sm font-medium">Proof file (PDF or image)</label>
                  <input type="file" accept="application/pdf,image/*" class="zaqa-input" @change="onProofFileChange" />
                  <InputError :message="proofForm.errors.file" />
                </div>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                  <button type="button" class="zaqa-btn zaqa-btn-primary w-full sm:w-auto" :disabled="proofForm.processing || !proofForm.file" @click="uploadPaymentProof">
                    <Upload class="h-4 w-4" aria-hidden="true" />
                    Upload proof
                  </button>
	                  <button
	                    type="button"
	                    class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto"
	                    :disabled="(payment?.status ?? '') === 'confirmed'"
	                    @click="refreshPaymentStatus"
	                  >
	                    Refresh status
	                  </button>
	                </div>
              </div>

              <!-- Mobile money -->
              <div v-else>
                <div class="text-sm font-semibold text-text-primary">Mobile Money</div>
                <div v-if="!props.cgrate?.enabled" class="mt-2 rounded-xl border border-border bg-surface-muted p-4 text-sm text-text-muted">
                  Mobile Money is temporarily unavailable. Please try again later.
                </div>
                <div v-else class="mt-1 text-xs text-text-muted">
                  Enter your mobile number and approve the payment prompt on your phone.
                </div>

                <div v-if="props.cgrate?.enabled" class="mt-4 rounded-xl border border-brand/15 bg-brand/5 p-4">
                  <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Amount to pay</div>
                    <div class="text-lg font-semibold text-text-primary">
                      {{ formatMoneyCents(Number(invoice?.amount_cents ?? payment?.amount_cents ?? 0)) }}
                      {{ invoice?.currency ?? payment?.currency ?? 'ZMW' }}
                    </div>
                  </div>
                </div>

                <div v-if="props.cgrate?.enabled" class="mt-4">
                  <label class="text-sm font-medium">Mobile number</label>
                  <input
                    v-model="mobileMoneyForm.mobile_number"
                    class="zaqa-input h-12 text-base"
                    inputmode="tel"
                    autocomplete="tel"
                    placeholder="e.g. 0971000000 or 260971000000"
                    :disabled="!mobileMoneyCanInitiate || mobileMoneySubmitting"
                  />
                  <InputError :message="mobileMoneyForm.errors.mobile_number" />
                </div>

                <div v-if="props.cgrate?.enabled" class="mt-4 flex flex-col gap-3">
                  <button
                    type="button"
                    class="zaqa-btn zaqa-btn-primary h-12 w-full text-base sm:w-auto"
                    :disabled="!mobileMoneyCanInitiate || mobileMoneySubmitting || !mobileMoneyForm.mobile_number.trim()"
                    :aria-busy="mobileMoneySubmitting ? 'true' : 'false'"
                    @click="initiateMobileMoney"
                  >
                    {{ mobileMoneySubmitting ? 'Sending prompt…' : 'Send payment prompt' }}
                  </button>

                  <p v-if="mobileMoneyIsPending" class="text-xs text-text-muted">
                    A payment request is already pending. You can continue waiting or check back later.
                  </p>
                </div>

                <div
                  v-if="props.cgrate?.enabled && mobileMoneyAttempt"
                  class="mt-4 rounded-xl bg-surface-muted p-4 text-sm ring-1 ring-black/[0.04]"
                >
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Payment status</div>
                      <div class="mt-2">
                        <span class="zaqa-badge" :class="mobileMoneyStatusBadgeClass(mobileMoneyAttempt.status)">
                          {{ mobileMoneyStatusLabel(mobileMoneyAttempt.status) }}
                        </span>
                      </div>
                    </div>
                    <div v-if="mobileMoneyAttempt.mobile_number" class="text-right">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Mobile number</div>
                      <div class="mt-1 font-semibold text-text-primary">{{ mobileMoneyAttempt.mobile_number }}</div>
                    </div>
                  </div>

                  <p class="mt-3 text-xs leading-relaxed text-text-muted">{{ mobileMoneyAttempt.message }}</p>

                  <div v-if="mobileMoneyAttempt.initiated_at" class="mt-3 text-xs text-text-muted">
                    Requested: <span class="font-semibold text-text-primary">{{ formatDateTimeValue(mobileMoneyAttempt.initiated_at) }}</span>
                  </div>

                  <div v-if="mobileMoneyIsPending" class="mt-3 inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-xs text-text-muted">
                    <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-amber-400" aria-hidden="true" />
                    <span>Waiting for payment approval on your phone.</span>
                  </div>

                  <div v-else-if="mobileMoneyIsSuccessful" class="mt-3 inline-flex items-center gap-2 rounded-lg border border-success/20 bg-success/10 px-3 py-2 text-xs text-success">
                    <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
                    <span>Payment confirmed.</span>
                  </div>

                  <div v-else-if="mobileMoneyIsFailed" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <p class="text-xs text-danger">{{ mobileMoneyAttempt.message }}</p>
                    <button
                      type="button"
                      class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto"
                      @click="initiateMobileMoney"
                    >
                      Try again
                    </button>
                  </div>
                </div>
              </div>

              <div class="mt-4 inline-flex items-start gap-2 rounded-xl bg-surface-muted px-3 py-2 text-xs text-text-muted ring-1 ring-black/[0.04]">
                <Lock class="mt-0.5 h-4 w-4 text-brand" aria-hidden="true" />
                <span>Payments are securely processed and verified by ZAQA.</span>
              </div>
            </div>
              </template>
          </div>

            <div v-else class="rounded-xl border border-border bg-surface-muted px-4 py-4 text-sm text-text-muted">
              <div v-if="paymentInvoiceMissingSteps.length > 0">
                Complete the previous steps to prepare your invoice and unlock payment.
              </div>
              <div v-else-if="invoicePreparation.auto_failed">
                Invoice preparation failed. Use <span class="font-semibold text-text-primary">Retry invoice preparation</span> in the invoice card above.
              </div>
              <div v-else>
                Preparing your invoice…
              </div>
            </div>
          </div>

          <WizardFooterBar
            :show-prev="!!stepNav.prev"
            :show-next="!!stepNav.next"
            prev-label="Previous"
            next-label="Next"
            :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
            :on-next="() => goNext('payment')"
          />
        </section>

        <section v-else-if="activeStep === 'review'" class="rounded-xl border border-border bg-surface p-5">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 class="text-base font-semibold text-text-primary">Final review</h2>
              <p class="mt-1 text-xs text-text-muted">Review your application details carefully before final submission.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="zaqa-badge zaqa-badge-warning">
                Once submitted, edits are locked
              </span>
            </div>
          </div>

          <!-- Paper-like review sheet -->
          <div class="mt-4 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border bg-surface-muted px-5 py-4">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application</div>
                  <div class="mt-1 text-lg font-semibold tracking-tight text-text-primary">
                    {{ application.application_number }}
                  </div>
                  <div class="mt-1 text-xs text-text-muted">{{ application.status_label }}</div>
                </div>
                <div class="text-xs text-text-muted">
                  Created: <span class="font-semibold text-text-primary">{{ application.created_at ?? '—' }}</span>
                </div>
              </div>
            </div>

            <div class="px-5 py-5">
              <!-- Applicant Information -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">1. Applicant information</div>
                  <div class="mt-1 text-xs text-text-muted">Contact details and identity information.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('applicant')">Edit</button>
              </div>
              <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Applicant type</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantType }}</div>
                </div>
                <div v-if="trimStr(applicant.email)" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Email</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant.email }}</div>
                </div>
                <div v-if="trimStr(applicant.phone_primary)" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Primary phone</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant.phone_primary }}</div>
                </div>
                <div v-if="trimStr(applicant.phone_secondary)" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Secondary phone</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant.phone_secondary }}</div>
                </div>
                <template v-if="applicantType !== 'institution'">
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">First name</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.first_name || '—' }}</div>
                  </div>
                  <div v-if="trimStr(applicantForm.middle_name)" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Middle name</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.middle_name }}</div>
                  </div>
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Surname</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.surname || '—' }}</div>
                  </div>
                  <div v-if="trimStr(applicantForm.gender)" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Gender</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.gender }}</div>
                  </div>
                  <div v-if="trimStr(applicantForm.identity_type)" class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Identity type</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.identity_type }}</div>
                  </div>
                  <div v-if="trimStr(applicantForm.identity_number)" class="rounded-xl border border-border bg-surface-muted px-4 py-3 sm:col-span-2">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">
                      {{ (trimStr(applicantForm.identity_type).toLowerCase() === 'passport') ? 'Passport number' : 'NRC number' }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.identity_number }}</div>
                  </div>
                </template>
              </div>

              <div class="my-6 h-px bg-border/70" />

              <!-- Qualification Information -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">2. Qualification information</div>
                  <div class="mt-1 text-xs text-text-muted">Qualification details as entered in the wizard.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('qualification')">Edit</button>
              </div>

              <div v-if="qualifications.length === 0" class="mt-3 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">
                Qualification details are missing.
                <InputError :message="(submitForm.errors as any).qualification" class="mt-2" />
              </div>
              <div v-else class="mt-3 space-y-5">
                <div
                  v-for="q in qualifications"
                  :key="q.id"
                  class="rounded-xl border border-border bg-surface-muted/60 p-4"
                >
                  <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ q.title_of_qualification || 'Untitled qualification' }}</div>
                  <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification type</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ q.qualification_type_master?.level_label }} — {{ q.qualification_type_master?.name }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Award scope</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ q.is_foreign_qualification ? 'Foreign' : 'Local' }}</div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Country of award</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ countries.find((c) => c.id === q.country_id)?.name ?? q.country?.name ?? '—' }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Awarding institution</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ q.awarding_institution_name_other || q.awarding_institution?.name || q.awarding_institution_name || '—' }}
                      </div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Award date</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ q.award_date || '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Certificate / student / exam ID</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">
                        {{ q.certificate_number || q.student_number || q.examination_number || '—' }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="my-6 h-px bg-border/70" />

              <!-- Documents -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">3. Supporting documents</div>
                  <div class="mt-1 text-xs text-text-muted">Uploaded documents required for processing.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('qualification')">Edit</button>
              </div>

              <div class="mt-3 overflow-hidden rounded-xl border border-border">
                <div class="divide-y divide-border/60">
                  <div v-for="d in application.documents" :key="d.id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                      <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ d.document_type }}</div>
                      <div class="mt-1 text-sm font-semibold text-text-primary">{{ d.original_name || '—' }}</div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="zaqa-badge zaqa-badge-success">Uploaded</span>
                      <a v-if="d.preview_url && application.can_edit" :href="d.preview_url" target="_blank" rel="noopener" class="zaqa-link text-xs">Preview</a>
                      <a v-if="d.download_url && application.can_edit" :href="d.download_url" target="_blank" rel="noopener" class="zaqa-link text-xs">Download</a>
                    </div>
                  </div>
                </div>
              </div>
              <InputError :message="(submitForm.errors as any).documents" class="mt-2" />

              <div class="my-6 h-px bg-border/70" />

              <!-- Qualification (institution) consent -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">4. Qualification consent</div>
                  <div class="mt-1 text-xs text-text-muted">Per-qualification institution consent (foreign upload or local acceptance).</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('qualification')">Edit</button>
              </div>
              <div class="mt-3 space-y-2">
                <div
                  v-for="row in qualificationRows"
                  :key="row.id"
                  class="flex flex-col gap-1 rounded-xl border border-border bg-surface-muted px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div class="min-w-0 text-sm font-semibold text-text-primary">{{ row.title_of_qualification || 'Untitled qualification' }}</div>
                  <span class="zaqa-badge shrink-0" :class="row._consentOk ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                    {{ row._consentOk ? 'Consent complete' : 'Consent pending' }}
                  </span>
                </div>
              </div>
              <InputError :message="(submitForm.errors as any).consent" class="mt-2" />

              <div class="my-6 h-px bg-border/70" />

              <!-- Application declarations (terms / accuracy) -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">5. Confirm</div>
                  <div class="mt-1 text-xs text-text-muted">Portal terms and confirmation that your application information is correct.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('consent')">Edit</button>
              </div>
              <div class="mt-3">
                <span
                  class="zaqa-badge"
                  :class="application.wizard_declarations?.terms_accepted_at ? 'zaqa-badge-success' : 'zaqa-badge-warning'"
                >
                  {{ application.wizard_declarations?.terms_accepted_at ? 'Recorded' : 'Not recorded' }}
                </span>
              </div>
              <InputError :message="(submitForm.errors as any).declarations" class="mt-2" />

              <div class="my-6 h-px bg-border/70" />

              <!-- Payment -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">6. Payment</div>
                  <div class="mt-1 text-xs text-text-muted">Invoice and payment confirmation.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('payment')">Edit</button>
              </div>
              <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Invoice number</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.invoice?.invoice_number ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Amount</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{ ((application.invoice?.amount_cents ?? 0) / 100).toFixed(2) }} {{ application.invoice?.currency ?? 'ZMW' }}
                  </div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Method</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.payment?.method ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Payment status</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.payment?.status ?? '—' }}</div>
                </div>
              </div>
              <InputError :message="(submitForm.errors as any).payment" class="mt-2" />

              <div class="my-6 h-px bg-border/70" />

              <!-- Declaration -->
              <div>
                <div class="text-sm font-semibold text-text-primary">7. Final declaration</div>
                <div class="mt-1 text-xs text-text-muted">
                  Please confirm before submitting.
                </div>

                <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <input v-model="declarationAccepted" type="checkbox" class="mt-1 h-4 w-4 rounded border-border text-brand focus:ring-brand/30" />
                  <span class="text-sm text-text-primary">
                    I confirm that the information provided is accurate, and I understand that final submission locks this application unless ZAQA reopens it.
                  </span>
                </label>
              </div>
            </div>
          </div>

          <WizardFooterBar
            :show-prev="!!stepNav.prev"
            :show-next="false"
            :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
          >
            <button type="button" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto" @click="goToStep('applicant')">Back to start</button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary w-full sm:w-auto"
              :disabled="!canSubmitNow"
              @click="submitApplication"
            >
              Submit application
            </button>
          </WizardFooterBar>

          <InputError :message="(submitForm.errors as any).application" class="mt-2" />
        </section>
      <!-- Sidebar intentionally removed from applicant wizard -->
    </WizardShell>

    </div>

    <ActionModal
      v-model="mobileMoneyModalOpen"
      title="Payment request sent"
      description="Please approve the payment prompt on your phone."
      max-width-class="max-w-lg"
    >
      <div class="space-y-4">
        <div class="rounded-xl border border-border bg-surface-muted/70 p-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Mobile number</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">{{ mobileMoneyAttempt?.mobile_number ?? '—' }}</div>
            </div>
            <div>
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Amount</div>
              <div class="mt-1 text-sm font-semibold text-text-primary">
                {{ formatMoneyCents(Number(mobileMoneyAttempt?.amount_cents ?? invoice?.amount_cents ?? payment?.amount_cents ?? 0)) }}
                {{ mobileMoneyAttempt?.currency ?? invoice?.currency ?? payment?.currency ?? 'ZMW' }}
              </div>
            </div>
          </div>

          <div class="mt-4 flex items-center gap-2">
            <span class="zaqa-badge" :class="mobileMoneyStatusBadgeClass(mobileMoneyAttempt?.status)">
              {{ mobileMoneyStatusLabel(mobileMoneyAttempt?.status) }}
            </span>
            <span v-if="mobileMoneyIsPending" class="inline-flex items-center gap-2 text-xs text-text-muted">
              <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-amber-400" aria-hidden="true" />
              Checking status…
            </span>
          </div>

          <p class="mt-3 text-sm leading-relaxed text-text-muted">
            {{ mobileMoneyAttempt?.message ?? 'Waiting for payment approval.' }}
          </p>
        </div>
      </div>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary w-full px-4 py-2 text-sm sm:w-auto" @click="closeMobileMoneyModal">
          Close and check later
        </button>
        <button type="button" class="zaqa-btn zaqa-btn-primary w-full px-4 py-2 text-sm sm:w-auto" @click="closeMobileMoneyModal">
          Continue waiting
        </button>
      </template>
    </ActionModal>
  </ApplicantLayout>
</template>
