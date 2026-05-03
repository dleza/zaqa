<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, withDefaults } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import WizardStepper from '@/Components/WizardStepper.vue'
import WizardShell from '@/Components/WizardShell.vue'
import WizardFooterBar from '@/Components/WizardFooterBar.vue'
import DocumentManager from '@/Components/DocumentManager.vue'
import QualificationWorkspaceModal from '@/Components/Applicant/QualificationWorkspaceModal.vue'
import Swal from 'sweetalert2'
import {
  AlertCircle,
  CheckCircle2,
  CreditCard,
  FileDown,
  GraduationCap,
  Landmark,
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
    certificateSubjects: () => [],
    declarationsCopy: () => ({}),
    amendmentQualificationId: null,
  },
)

type StepKey = 'applicant' | 'qualification' | 'consent' | 'payment' | 'review'

const steps = computed(() => [
  { key: 'applicant' as const, label: 'Applicant' },
  { key: 'qualification' as const, label: 'Qualification' },
  { key: 'consent' as const, label: 'Declarations' },
  { key: 'payment' as const, label: 'Payment' },
  { key: 'review' as const, label: 'Review & submit' },
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
    const gradeOk = (r.grade ?? '').toString().trim() !== ''
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
  if (!emailEff) missing.push('Enter your email address in the Communication section.')
  if (!phoneEff) missing.push('Enter your primary phone number.')

  const applicantTypeStr = trimStr(props.applicant?.applicant_type ?? props.application?.applicant_type)

  if (submittingFor === 'other') {
    const fullName =
      trimStr(props.application?.metadata?.verification_subject?.full_name) ||
      trimStr(submittingForForm.subject_full_name)
    const nrc =
      trimStr(props.application?.metadata?.verification_subject?.nrc_number) ||
      trimStr(submittingForForm.subject_nrc_number)
    const passport =
      trimStr(props.application?.metadata?.verification_subject?.passport_number) ||
      trimStr(submittingForForm.subject_passport_number)
    if (!fullName) missing.push('Enter the verification subject’s full name.')
    if (!nrc && !passport) missing.push('Enter the subject’s NRC or passport number (at least one).')
  } else if (applicantTypeStr === 'individual') {
    const nrc =
      trimStr(props.applicant?.applicant_profile?.nrc_number) ||
      trimStr(applicantForm.nrc_number) ||
      trimStr(submittingForForm.profile_nrc_number)
    const passport =
      trimStr(props.applicant?.applicant_profile?.passport_number) ||
      trimStr(applicantForm.passport_number) ||
      trimStr(submittingForForm.profile_passport_number)
    if (!nrc && !passport) missing.push('Enter your NRC or passport number under Biodata (at least one).')
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
        nrc_number: props.applicant?.applicant_profile?.nrc_number ?? '',
        passport_number: props.applicant?.applicant_profile?.passport_number ?? '',
      }),
})

type SubmittingFor = 'self' | 'other'
const institutionOnlyOnBehalf = computed(() => applicantType.value === 'institution')
const submittingForForm = useForm<{
  submitting_for: SubmittingFor
  subject_full_name: string
  subject_email: string
  subject_phone: string
  subject_nrc_number: string
  subject_passport_number: string
  profile_nrc_number: string
  profile_passport_number: string
}>({
  submitting_for: (props.application?.metadata?.submitting_for ?? (institutionOnlyOnBehalf.value ? 'other' : 'self')) as SubmittingFor,
  subject_full_name: (props.application?.metadata?.verification_subject?.full_name ?? '').toString(),
  subject_email: (props.application?.metadata?.verification_subject?.email ?? '').toString(),
  subject_phone: (props.application?.metadata?.verification_subject?.phone ?? '').toString(),
  subject_nrc_number: (props.application?.metadata?.verification_subject?.nrc_number ?? '').toString(),
  subject_passport_number: (props.application?.metadata?.verification_subject?.passport_number ?? '').toString(),
  profile_nrc_number: (props.applicant?.applicant_profile?.nrc_number ?? '').toString(),
  profile_passport_number: (props.applicant?.applicant_profile?.passport_number ?? '').toString(),
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

/** Keep PATCH payloads aligned when “Myself” uses only the main biodata fields (no duplicate NRC inputs). */
watch(
  () => [applicantForm.nrc_number, applicantForm.passport_number],
  ([nrc, passport]) => {
    submittingForForm.profile_nrc_number = (nrc ?? '').toString()
    submittingForForm.profile_passport_number = (passport ?? '').toString()
  },
  { immediate: true },
)

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
  if (qualifications.value.some((q: any) => (q.verification_state ?? '') === 'returned_to_applicant')) {
    return false
  }
  return invoiceSettled.value || !!props.application?.paid_at
})

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

const zambiaCountryId = computed(() => {
  const byIso = props.countries?.find((c: any) => (c.iso_code ?? '').toString().toUpperCase() === 'ZMB')
  if (byIso?.id) return byIso.id
  const byName = props.countries?.find((c: any) => (c.name ?? '').toString().toLowerCase() === 'zambia')
  return byName?.id ?? null
})

const qualificationWorkspaceOpen = ref(false)
const qualificationWorkspaceMode = ref<'add' | 'edit'>('add')
const qualificationWorkspaceQual = ref<any | null>(null)

function openQualificationWorkspace(mode: 'add' | 'edit', qual?: any) {
  if (applicationLocked.value && mode === 'add') return
  qualificationWorkspaceMode.value = mode
  qualificationWorkspaceQual.value = qual ?? null
  if (qual?.id) selectedQualificationId.value = qual.id
  qualificationWorkspaceOpen.value = true
}

function onQualificationWorkspaceSaved(payload: { qualificationId: number | null }) {
  if (payload.qualificationId) selectedQualificationId.value = payload.qualificationId
}

function removeQualification(id: number) {
  if (applicationLocked.value) return
  router.delete(`/applicant/applications/${props.application.id}/qualifications/${id}`, {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['application'] }),
  })
}

function saveDeclarations() {
  setSaving('Saving declarations…')
  declarationsForm.patch(`/applicant/applications/${props.application.id}/wizard-declarations`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Declarations saved.')
      router.reload({
        only: ['application'],
        onSuccess: () => {
          const next = stepNav.value.next
          if (next) {
            goToStep(next)
          }
        },
        onFinish: () => {
          if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
        },
      })
    },
    onError: () => {
      setError('Declarations could not be saved.')
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const submitForm = useForm({})
const declarationAccepted = ref(false)

const submissionBlockReasons = computed(() => {
  const reasons: string[] = []
  if (!invoiceSettled.value) {
    reasons.push('Your payments must cover the full fee for your qualifications (including any top-up after a type or locality change).')
  }
  if (!declarationAccepted.value) reasons.push('Please accept the declaration to proceed.')
  return reasons
})

const canSubmitNow = computed(() => submissionBlockReasons.value.length === 0 && !submitForm.processing)

async function submitApplication() {
  if (!canSubmitNow.value) return

  const result = await Swal.fire({
    icon: 'warning',
    title: 'Submit and lock application?',
    html: `<div style="text-align:left">
      <div><strong>Once you submit, you will not be able to change this application</strong> unless ZAQA reopens or returns it for amendment.</div>
      <div style="margin-top:8px; font-size:13px; opacity:.9">Please confirm you are ready to submit for processing.</div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'Submit application',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#0076BD',
  })

  if (!result.isConfirmed) return

  setSaving('Submitting…')
  submitForm.post(`/applicant/applications/${props.application.id}/submit`, {
    onError: () => setError('Could not submit. Please fix the issues and try again.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const payment = computed(() => props.application?.payment ?? null)
const invoice = computed(() => props.application?.invoice ?? null)
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

const mobileMoneyForm = useForm<{ mobile_number: string }>({ mobile_number: '' })
function initiateMobileMoney() {
  setSaving('Initiating Mobile Money…')
  mobileMoneyForm.post(`/applicant/applications/${props.application.id}/payment/initiate-mobile-money`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Mobile Money initiated.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Could not initiate Mobile Money.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

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
    const hasTranscript =
      typeof q.has_transcript_document === 'boolean' ? q.has_transcript_document : hasCurrentQualificationDocument(id, 'transcript')
    const docsOk = hasCert && (isForeign ? hasTranscript : true)

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
    review: invoiceSettled.value && declarationAccepted.value,
  } as Record<StepKey, boolean>
})

const wizardCompletion = computed(() => {
  const stepKeys = steps.value.map((s) => s.key as StepKey)
  const total = stepKeys.length
  const completed = stepKeys.filter((k) => stepCompletion.value[k]).length
  const percent = total > 0 ? Math.min(100, Math.max(0, Math.round((completed / total) * 100))) : 0

  return { total, completed, percent }
})

/** Where you are in the stepper (distinct from checklist completion — requirements can be 0% while you browse steps). */
const wizardStepPosition = computed(() => {
  const keys = steps.value.map((s) => s.key as StepKey)
  const idx = Math.max(0, keys.indexOf(activeStep.value))
  const total = keys.length
  const percent = total > 0 ? Math.min(100, Math.round(((idx + 1) / total) * 100)) : 0
  const stepMeta = steps.value[idx]
  return {
    current: idx + 1,
    total,
    percent,
    label: (stepMeta?.label ?? '') as string,
  }
})

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
    if (!declarationsForm.accept_terms || !declarationsForm.confirm_information_correct) {
      return '<p class="text-sm text-left">Tick both declaration checkboxes to continue.</p>'
    }
    return '<p class="text-sm text-left">Click <strong>Save declarations</strong> to record your confirmation before continuing.</p>'
  }
  if (step === 'payment') {
    return '<p class="text-sm text-left">Confirm payment for this application before continuing.</p>'
  }
  if (step === 'review') {
    return '<p class="text-sm text-left">Confirm payment and accept the final confirmation on the review step.</p>'
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
              {{ application.application_number }} • {{ application.status_label }} — work through Applicant, Qualification, Declarations, Payment, then review and submit.
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

        <div class="mt-3 rounded-xl border border-border bg-surface px-4 py-3">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
              <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Completion</div>
              <div class="mt-0.5 truncate text-sm font-semibold text-text-primary">
                Requirements {{ wizardCompletion.completed }} / {{ wizardCompletion.total }} • {{ wizardCompletion.percent }}%
              </div>
              <div class="mt-1 text-xs text-text-muted">
                Step {{ wizardStepPosition.current }} / {{ wizardStepPosition.total }} — {{ wizardStepPosition.label }}
              </div>
            </div>
            <span v-if="wizardCompletion.percent === 100" class="zaqa-badge zaqa-badge-success">Ready to submit</span>
          </div>

          <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full border border-border bg-surface-muted">
            <div
              class="h-full rounded-full bg-brand bg-gradient-to-r from-brand to-brand/60 shadow-sm transition-[width] duration-500 ease-out"
              :style="{ width: `${wizardCompletion.percent}%` }"
              role="progressbar"
              :aria-valuenow="wizardCompletion.percent"
              aria-valuemin="0"
              aria-valuemax="100"
              aria-label="Application completion"
            />
          </div>
        </div>
      </div>
    </template>

    <div
      class="w-full max-w-none mx-auto -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-8 2xl:-mx-10 2xl:px-10"
    >
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
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Full name (as on NRC/Passport)</label>
                <input v-model="submittingForForm.subject_full_name" class="zaqa-input" />
                <InputError :message="(submittingForForm.errors as any).subject_full_name" />
              </div>
              <div>
                <label class="text-sm font-medium">Email (optional)</label>
                <input v-model="submittingForForm.subject_email" type="email" class="zaqa-input" />
                <InputError :message="(submittingForForm.errors as any).subject_email" />
              </div>
              <div>
                <label class="text-sm font-medium">Phone (optional)</label>
                <input v-model="submittingForForm.subject_phone" class="zaqa-input" />
                <InputError :message="(submittingForForm.errors as any).subject_phone" />
              </div>
              <div>
                <label class="text-sm font-medium">NRC number</label>
                <input v-model="submittingForForm.subject_nrc_number" class="zaqa-input" />
                <InputError :message="(submittingForForm.errors as any).subject_nrc_number" />
              </div>
              <div>
                <label class="text-sm font-medium">Passport number</label>
                <input v-model="submittingForForm.subject_passport_number" class="zaqa-input" />
                <InputError :message="(submittingForForm.errors as any).subject_passport_number" />
              </div>
              <div class="sm:col-span-2 text-xs text-text-muted">
                You must provide <span class="font-semibold text-text-primary">either NRC or Passport</span> (or both).
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
              <DocumentManager
                :upload-url="`/applicant/applications/${application.id}/documents`"
                :documents="application.documents"
                :transcript-required="false"
                documents-scope="identity_only"
              />
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
              <label class="text-sm font-medium">Email</label>
              <input v-model="applicantForm.email" type="email" class="zaqa-input" />
              <InputError :message="applicantForm.errors.email" />
            </div>

            <div>
              <label class="text-sm font-medium">Primary phone</label>
              <input v-model="applicantForm.phone_primary" class="zaqa-input" />
              <InputError :message="applicantForm.errors.phone_primary" />
            </div>
            <div>
              <label class="text-sm font-medium">Secondary phone (optional)</label>
              <input v-model="applicantForm.phone_secondary" class="zaqa-input" />
              <InputError :message="applicantForm.errors.phone_secondary" />
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
                <label class="text-sm font-medium">NRC number</label>
                <input v-model="applicantForm.nrc_number" class="zaqa-input" />
                <InputError :message="applicantForm.errors.nrc_number" />
              </div>
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Passport number</label>
                <input v-model="applicantForm.passport_number" class="zaqa-input" />
                <InputError :message="applicantForm.errors.passport_number" />
              </div>
              <div class="sm:col-span-2 text-xs text-text-muted">
                You must provide <span class="font-semibold text-text-primary">either NRC or Passport</span> (or both).
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
              <h2 class="mt-3 text-2xl font-semibold tracking-tight text-text-primary">Verification portfolio</h2>
              <p class="mt-2 text-sm leading-relaxed text-text-muted">
                Each qualification opens in a dedicated workspace: awarding country and institution, programme details, certificate uploads, and — when the award is outside Zambia — the institution consent template.
              </p>
            </div>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary inline-flex h-11 shrink-0 items-center gap-2 px-5 text-sm font-semibold shadow-md shadow-brand/15"
              :disabled="applicationLocked"
              @click="openQualificationWorkspace('add')"
            >
              <PlusCircle class="h-4 w-4" aria-hidden="true" />
              Add qualification
            </button>
          </div>

          <div
            v-if="qualificationsReturnedForAmendment.length > 0"
            class="mt-6 rounded-2xl border border-brand/25 bg-brand/[0.06] px-5 py-4 sm:px-6"
          >
            <div class="text-sm font-semibold text-text-primary">Submit corrections back to ZAQA</div>
            <p class="mt-1 text-sm leading-relaxed text-text-muted">
              Updating details in the workspace only saves your draft. When you are satisfied with this qualification, submit it here so verification staff can continue your case.
            </p>
            <ul class="mt-4 space-y-3">
              <li
                v-for="q in qualificationsReturnedForAmendment"
                :key="'ret-' + q.id"
                class="flex flex-col gap-3 rounded-xl border border-border bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0">
                  <div class="font-medium text-text-primary">{{ q.title_of_qualification || 'Qualification' }}</div>
                  <p v-if="q.amendment_comment" class="mt-1 whitespace-pre-wrap text-xs text-text-muted">{{ q.amendment_comment }}</p>
                </div>
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-primary shrink-0 px-5"
                  :disabled="finalizeAmendmentForm.processing || !applicationPaymentSatisfied"
                  @click="submitCorrectionsToZaqa(q.id)"
                >
                  Submit corrections to ZAQA
                </button>
              </li>
            </ul>
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
            Payment is confirmed. This application is read-only.
          </div>

          <div class="mt-8 grid gap-8">
            <div class="space-y-4">
              <div v-if="qualificationRows.length === 0" class="rounded-2xl border border-dashed border-border bg-surface-muted/30 px-6 py-14 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                  <GraduationCap class="h-7 w-7" aria-hidden="true" />
                </div>
                <p class="mt-4 text-base font-semibold text-text-primary">No qualifications yet</p>
                <p class="mt-2 max-w-md mx-auto text-sm text-text-muted">
                  Add each programme or certificate you want verified. You can manage documents and institution consent inside the workspace.
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
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                      <button
                        type="button"
                        class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
                        @click="openQualificationWorkspace('edit', q)"
                      >
                        <PenLine class="h-4 w-4" aria-hidden="true" />
                        Open workspace
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

        <section v-else-if="activeStep === 'consent'" class="rounded-xl border border-border bg-surface p-5">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h2 class="text-sm font-semibold text-text-primary">Declarations</h2>
              <p class="mt-1 text-xs text-text-muted">
                {{
                  declarationsCopy.page_intro ??
                  'Confirm the declarations below. Qualification-specific institution consent is completed inside each qualification workspace (previous step), not here.'
                }}
              </p>
            </div>
          </div>

          <div v-if="applicationLocked" class="mt-4 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning">
            Payment is confirmed. This application is now read-only.
          </div>

          <div class="mx-auto mt-6 w-full max-w-5xl space-y-6 lg:max-w-6xl">
            <div class="rounded-xl border border-border bg-surface-muted p-6 sm:p-8">
              <div class="text-center text-sm font-semibold text-text-primary">
                {{ declarationsCopy.terms_title ?? 'Terms and use of the service' }}
              </div>
              <div class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap text-sm leading-relaxed text-text-primary">
                {{ declarationsCopy.terms_body ?? '' }}
              </div>
            </div>

            <div class="space-y-4">
              <label class="flex cursor-pointer items-start gap-3">
                <input
                  v-model="declarationsForm.accept_terms"
                  type="checkbox"
                  class="mt-1 h-4 w-4 rounded border-border text-brand focus:ring-brand/30"
                  :disabled="applicationLocked"
                />
                <span class="text-sm text-text-primary">{{ declarationsCopy.accept_terms_label ?? 'I accept the terms.' }}</span>
              </label>
              <InputError :message="declarationsForm.errors.accept_terms" />

              <label class="flex cursor-pointer items-start gap-3">
                <input
                  v-model="declarationsForm.confirm_information_correct"
                  type="checkbox"
                  class="mt-1 h-4 w-4 rounded border-border text-brand focus:ring-brand/30"
                  :disabled="applicationLocked"
                />
                <span class="text-sm text-text-primary">{{
                  declarationsCopy.confirm_accuracy_label ?? 'I confirm that the information I provided is correct.'
                }}</span>
              </label>
              <InputError :message="declarationsForm.errors.confirm_information_correct" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
              <button
                type="button"
                class="zaqa-btn zaqa-btn-primary"
                :disabled="
                  applicationLocked ||
                  declarationsForm.processing ||
                  !declarationsForm.accept_terms ||
                  !declarationsForm.confirm_information_correct
                "
                @click="saveDeclarations"
              >
                Save declarations
              </button>
              <span
                v-if="application?.wizard_declarations?.terms_accepted_at && application?.wizard_declarations?.information_confirmed_at"
                class="text-xs font-medium text-emerald-700"
              >
                Declarations saved
              </span>
            </div>
          </div>

          <div class="mt-10">
            <WizardFooterBar
              :show-prev="!!stepNav.prev"
              :show-next="!!stepNav.next"
              prev-label="Previous"
              next-label="Next"
              :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
              :on-next="() => goNext('consent')"
            />
          </div>
        </section>

        <section v-else-if="activeStep === 'payment'" class="rounded-xl border border-border bg-surface p-5">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-sm font-semibold text-text-primary">Payment</h2>
              <p class="mt-1 text-xs text-text-muted">Choose a payment method and complete payment before submission.</p>
            </div>
            <span
              class="zaqa-badge"
              :class="(payment?.status ?? '') === 'confirmed' ? 'zaqa-badge-success' : 'zaqa-badge-warning'"
            >
              <component :is="(payment?.status ?? '') === 'confirmed' ? CheckCircle2 : AlertCircle" class="h-4 w-4" aria-hidden="true" />
              {{ (payment?.status ?? '') === 'confirmed' ? 'Confirmed' : 'Not confirmed' }}
            </span>
          </div>

          <!-- Invoice summary -->
          <div class="mt-4 rounded-xl border border-border bg-surface-muted p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0">
                <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Invoice</div>
                <div class="mt-1 truncate text-sm font-semibold text-text-primary">{{ invoice?.invoice_number ?? 'Generating…' }}</div>
                <div v-if="invoice?.fee_label_snapshot" class="mt-1 text-xs text-text-muted">{{ invoice.fee_label_snapshot }}</div>
              </div>

              <div class="flex items-start gap-3">
                <div class="text-right">
                  <div class="text-xs font-semibold text-text-muted uppercase tracking-wider">Amount</div>
                  <div class="mt-1 text-2xl font-semibold tracking-tight text-text-primary">
                    {{ ((invoice?.amount_cents ?? (invoice ? 0 : invoiceTotalPreview.amountCents)) / 100).toFixed(2) }}
                    <span class="text-sm font-semibold text-text-muted">{{ invoice?.currency ?? invoiceTotalPreview.currency }}</span>
                  </div>
                </div>
                <span class="zaqa-badge" :class="invoice?.status === 'paid' ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ invoice?.status ?? 'issued' }}
                </span>
              </div>
            </div>

            <div
              v-if="application?.supplementary_invoice"
              class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950"
            >
              <div class="font-semibold text-text-primary">Supplementary invoice (top-up)</div>
              <div class="mt-1 font-mono text-xs">{{ application.supplementary_invoice.invoice_number }}</div>
              <div class="mt-1 text-sm">
                Balance due:
                <span class="font-semibold">{{ formatMoneyCents(Number(application.supplementary_invoice.amount_cents ?? 0)) }}</span>
              </div>
              <p v-if="application.supplementary_invoice.amendment_reason" class="mt-2 text-xs leading-relaxed opacity-90">
                {{ application.supplementary_invoice.amendment_reason }}
              </p>
            </div>
            <div v-else class="mt-3 rounded-lg border border-border bg-surface-muted px-3 py-2 text-xs text-text-muted">
              You can add or edit qualifications until payment is confirmed. If you already paid and an amendment increases the fee, a separate supplementary invoice is
              created for the difference — your original invoice is not changed.
            </div>

            <div v-if="!invoice && !applicationLocked" class="mt-4 flex flex-col gap-3 border-t border-border/60 pt-4 sm:flex-row sm:items-end sm:justify-between">
              <div class="text-sm text-text-muted">
                Estimated total
                <span class="font-semibold text-text-primary">{{ (invoiceTotalPreview.amountCents / 100).toFixed(2) }} {{ invoiceTotalPreview.currency }}</span>
                — generate the invoice to enable payment.
              </div>
              <button
                type="button"
                class="zaqa-btn zaqa-btn-primary shrink-0"
                :disabled="prepareInvoiceForm.processing || qualificationRows.length === 0"
                @click="
                  prepareInvoiceForm.post(`/applicant/applications/${application.id}/payment/prepare`, {
                    preserveScroll: true,
                    onSuccess: () => router.reload({ only: ['application'] }),
                  })
                "
              >
                Generate invoice
              </button>
            </div>
          </div>

          <!-- Payment tabs -->
          <div class="mt-5">
            <!-- Confirmed state (read-only) -->
            <div v-if="invoiceSettled" class="rounded-2xl border border-success/20 bg-success/10 p-5">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <div class="text-sm font-semibold text-success">Payment confirmed</div>
                  <div class="mt-1 text-xs text-text-muted">
                    This invoice is settled. Payment method options are no longer available.
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
            <div class="flex gap-2 overflow-x-auto rounded-xl border border-border bg-surface p-2">
	              <button
	                type="button"
	                class="flex min-w-[10.5rem] flex-1 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold transition"
	                :class="activePaymentTab === 'card' ? 'bg-brand/10 text-brand ring-1 ring-brand/20' : 'text-text-muted hover:bg-surface-muted'"
	                @click="setPaymentTab('card')"
	              >
	                <CreditCard class="h-4 w-4" aria-hidden="true" />
	                <span>Card payment</span>
	              </button>
	              <button
	                type="button"
	                class="flex min-w-[10.5rem] flex-1 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold transition"
	                :class="activePaymentTab === 'bank_transfer' ? 'bg-brand/10 text-brand ring-1 ring-brand/20' : 'text-text-muted hover:bg-surface-muted'"
	                @click="setPaymentTab('bank_transfer')"
	              >
	                <Landmark class="h-4 w-4" aria-hidden="true" />
	                <span>Bank transfer</span>
	              </button>
	              <button
	                type="button"
	                class="flex min-w-[10.5rem] flex-1 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold transition"
	                :class="activePaymentTab === 'mobile_money' ? 'bg-brand/10 text-brand ring-1 ring-brand/20' : 'text-text-muted hover:bg-surface-muted'"
	                @click="setPaymentTab('mobile_money')"
	              >
	                <Smartphone class="h-4 w-4" aria-hidden="true" />
	                <span>Mobile Money</span>
	              </button>
	            </div>

            <div class="mt-4 rounded-xl border border-border bg-surface p-4">
              <!-- Card -->
              <div v-if="activePaymentTab === 'card'">
                <div class="text-sm font-semibold text-text-primary">Pay by card</div>
                <div class="mt-1 text-xs text-text-muted">You’ll be redirected to the payment gateway and returned here after the attempt.</div>

                <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <button
                    type="button"
                    class="zaqa-btn zaqa-btn-primary w-full sm:w-auto"
                    :disabled="(payment?.status ?? '') === 'confirmed' || cardInitiateForm.processing"
                    @click="initiateCardPayment"
                  >
                    Pay by card
                  </button>
	                  <div class="text-xs text-text-muted">
	                    Status: <span class="font-semibold text-text-primary">{{ payment?.status ?? 'not started' }}</span>
	                  </div>
	                </div>
	              </div>

              <!-- Bank transfer -->
              <div v-else-if="activePaymentTab === 'bank_transfer'">
                <div class="text-sm font-semibold text-text-primary">Upload proof of payment</div>
                <div class="mt-1 text-xs text-text-muted">
                  Upload your bank transfer proof. Finance will review and approve before you can submit.
                </div>

                <div class="mt-3 flex items-center justify-between gap-3">
	                  <span class="zaqa-badge" :class="(payment?.status ?? '') === 'confirmed' ? 'zaqa-badge-success' : (payment?.status ?? '') === 'rejected' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'">
	                    {{ payment?.status ?? 'not started' }}
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
                <div class="mt-1 text-xs text-text-muted">Enter your number and approve the payment prompt on your phone.</div>

                <div class="mt-4">
                  <label class="text-sm font-medium">Mobile number</label>
                  <input v-model="mobileMoneyForm.mobile_number" class="zaqa-input" placeholder="e.g. 097XXXXXXX" />
                  <InputError :message="mobileMoneyForm.errors.mobile_number" />
                </div>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <button type="button" class="zaqa-btn zaqa-btn-primary w-full sm:w-auto" :disabled="mobileMoneyForm.processing || (payment?.status ?? '') === 'confirmed'" @click="initiateMobileMoney">
                    Initiate Mobile Money
                  </button>
	                  <div class="text-xs text-text-muted">
	                    Status: <span class="font-semibold text-text-primary">{{ payment?.status ?? 'not started' }}</span>
	                  </div>
	                </div>
	              </div>
            </div>
            </div>

            <div v-else class="rounded-xl border border-border bg-surface-muted px-4 py-4 text-sm text-text-muted">
              Generate an invoice using the button in the summary card above. Payment methods unlock once an invoice exists.
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
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Email</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant.email ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Primary phone</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant.phone_primary ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Secondary phone</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicant.phone_secondary ?? '—' }}</div>
                </div>
                <template v-if="applicantType !== 'institution'">
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">First name</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.first_name || '—' }}</div>
                  </div>
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Middle name</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.middle_name || '—' }}</div>
                  </div>
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Surname</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.surname || '—' }}</div>
                  </div>
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">NRC number</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.nrc_number || '—' }}</div>
                  </div>
                  <div class="rounded-xl border border-border bg-surface-muted px-4 py-3 sm:col-span-2">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Passport number</div>
                    <div class="mt-1 text-sm font-semibold text-text-primary">{{ applicantForm.passport_number || '—' }}</div>
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
                    <div class="rounded-xl border border-border bg-surface-muted px-4 py-3 sm:col-span-2">
                      <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Notes</div>
                      <div class="mt-1 whitespace-pre-wrap text-sm font-semibold text-text-primary">{{ q.notes || '—' }}</div>
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
                      <span class="zaqa-badge" :class="d.is_current_version ? 'zaqa-badge-success' : 'zaqa-badge'">
                        {{ d.is_current_version ? 'Uploaded' : 'Old version' }}
                      </span>
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
                  <div class="text-sm font-semibold text-text-primary">5. Declarations</div>
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

                <div class="mt-3 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning">
                  <div class="font-semibold">Submission locks your application</div>
                  <div class="mt-1 text-xs">
                    Once you submit this application, you will not be able to change it unless it is reopened or returned for amendment by ZAQA.
                  </div>
                </div>

                <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <input v-model="declarationAccepted" type="checkbox" class="mt-1 h-4 w-4 rounded border-border text-brand focus:ring-brand/30" />
                  <span class="text-sm text-text-primary">
                    I confirm that the information provided is accurate, and I understand that final submission locks this application unless ZAQA reopens it.
                  </span>
                </label>

                <div v-if="submissionBlockReasons.length > 0" class="mt-4 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">
                  <div class="font-semibold">Submission is currently blocked</div>
                  <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    <li v-for="(r, idx) in submissionBlockReasons" :key="idx">{{ r }}</li>
                  </ul>
                </div>
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

    <QualificationWorkspaceModal
      v-model="qualificationWorkspaceOpen"
      :mode="qualificationWorkspaceMode"
      :application-id="application.id"
      :application="application"
      :countries="countries"
      :qualification-types="qualificationTypes"
      :certificate-subjects="certificateSubjects"
      :editing-qualification="qualificationWorkspaceMode === 'edit' ? qualificationWorkspaceQual : null"
      :locked="applicationLocked"
      :zambia-country-id="zambiaCountryId"
      @saved="onQualificationWorkspaceSaved"
    />
    </div>
  </ApplicantLayout>
</template>
