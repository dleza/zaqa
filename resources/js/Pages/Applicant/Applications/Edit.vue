<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import WizardStepper from '@/Components/WizardStepper.vue'
import WizardShell from '@/Components/WizardShell.vue'
import WizardFooterBar from '@/Components/WizardFooterBar.vue'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import DocumentManager from '@/Components/DocumentManager.vue'
import Swal from 'sweetalert2'
import { AlertCircle, CheckCircle2, CreditCard, Landmark, Smartphone, Upload } from 'lucide-vue-next'

type ApplicantPayload = {
  applicant_type?: string
  email?: string
  phone_primary?: string
  phone_secondary?: string | null
  applicant_profile?: any | null
  institution_profile?: any | null
}

const props = defineProps<{
  application: any
  applicant: ApplicantPayload
  serviceTypes: Array<{ value: string; label: string }>
  qualificationTypes: Array<any>
  countries: Array<{ id: number; name: string; iso_code?: string | null }>
  awardingInstitutions: Array<{ id: number; name: string }>
  localConsent: { title: string; text: string; version: string }
  foreignFeePreview?: any | null
}>()

type StepKey = 'applicant' | 'qualification' | 'subjects' | 'documents' | 'consent' | 'payment' | 'review'

const qualificationTypeForSteps = computed(() => selectedQualificationType.value?.zqf_level_code || props.application.qualification_category)
const steps = computed(() => {
  const needsSubjectsStep = !!selectedQualificationType.value?.requires_subject_results

  return [
    { key: 'applicant' as const, label: 'Applicant' },
    { key: 'qualification' as const, label: 'Qualification' },
    ...(needsSubjectsStep ? [{ key: 'subjects' as const, label: 'Subjects' }] : []),
    { key: 'documents' as const, label: 'Documents' },
    { key: 'consent' as const, label: 'Consent' },
    { key: 'payment' as const, label: 'Payment' },
    { key: 'review' as const, label: 'Review & submit' },
  ]
})

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

function goToStep(key: StepKey) {
  activeStep.value = key
  try {
    localStorage.setItem(`zaqa:wizard:${props.application.id}:step`, key)
  } catch {
    // ignore
  }
}

const prepareInvoiceForm = useForm({})
watch(
  () => activeStep.value,
  (step) => {
    if (step !== 'payment') return
    if (props.application?.invoice) return
    // Entering payment step generates the invoice (locks fee context).
    prepareInvoiceForm.post(`/applicant/applications/${props.application.id}/payment/prepare`, {
      preserveScroll: true,
      onSuccess: () => router.reload({ only: ['application'] }),
    })
  },
)

function isStepDirty(step: StepKey): boolean {
  if (step === 'applicant') return (applicantForm.isDirty ?? false) === true
  if (step === 'qualification') return (qualificationDetailsForm.isDirty ?? false) === true
  if (step === 'subjects') return (subjectResultsForm.isDirty ?? false) === true
  if (step === 'consent') return (localConsentForm.isDirty ?? false) === true || (foreignConsentForm.isDirty ?? false) === true
  return false
}

const hasUnsavedChanges = computed(() => {
  // page-level guard: any step dirty
  return (
    isStepDirty('applicant') ||
    isStepDirty('qualification') ||
    isStepDirty('subjects') ||
    isStepDirty('consent')
  )
})

function discardChangesForActiveStep() {
  if (activeStep.value === 'applicant') {
    applicantForm.reset()
    applicantForm.clearErrors()
    return
  }
  if (activeStep.value === 'qualification') {
    qualificationDetailsForm.reset()
    qualificationDetailsForm.clearErrors()
    syncIdentifierFromForm()
    return
  }
  if (activeStep.value === 'subjects') {
    subjectResultsForm.reset()
    subjectResultsForm.clearErrors()
    return
  }
  if (activeStep.value === 'consent') {
    localConsentForm.reset()
    localConsentForm.clearErrors()
    foreignConsentForm.reset()
    foreignConsentForm.clearErrors()
    return
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
      return
    }

    const stored = localStorage.getItem(`zaqa:wizard:${props.application.id}:step`) as StepKey | null
    if (stored && (steps.value as any[]).some((s) => s.key === stored)) activeStep.value = stored
  } catch {
    // ignore
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
const submittingForForm = useForm<{
  submitting_for: SubmittingFor
  subject_full_name: string
  subject_email: string
  subject_phone: string
  subject_nrc_number: string
  subject_passport_number: string
}>({
  submitting_for: (props.application?.metadata?.submitting_for ?? 'self') as SubmittingFor,
  subject_full_name: (props.application?.metadata?.verification_subject?.full_name ?? '').toString(),
  subject_email: (props.application?.metadata?.verification_subject?.email ?? '').toString(),
  subject_phone: (props.application?.metadata?.verification_subject?.phone ?? '').toString(),
  subject_nrc_number: (props.application?.metadata?.verification_subject?.nrc_number ?? '').toString(),
  subject_passport_number: (props.application?.metadata?.verification_subject?.passport_number ?? '').toString(),
})

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

const qualificationDetailsForm = useForm({
  country_id: props.application.qualification?.country_id ?? '',
  country_name_other: props.application.qualification?.country_name_other ?? '',
  awarding_institution_id: props.application.qualification?.awarding_institution_id ?? '',
  awarding_institution_name_other: props.application.qualification?.awarding_institution_name_other ?? '',
  // legacy name retained on record; UI uses institution selector + "Other"
  awarding_institution_name: props.application.qualification?.awarding_institution_name ?? '',
  certificate_number: props.application.qualification?.certificate_number ?? '',
  student_number: props.application.qualification?.student_number ?? '',
  examination_number: props.application.qualification?.examination_number ?? '',
  title_of_qualification: props.application.qualification?.title_of_qualification ?? '',
  award_date: props.application.qualification?.award_date ?? '',
  qualification_type_id: props.application.qualification?.qualification_type_id ?? '',
  transcript_reason: props.application.qualification?.transcript_reason ?? '',
  notes: props.application.qualification?.notes ?? '',
})

// Transcript is mandatory for foreign qualifications.
const transcriptRequired = computed(() => (props.application?.is_foreign ?? false) === true)
const selectedQualificationType = computed(() => {
  const id = Number(qualificationDetailsForm.qualification_type_id || 0)
  return (props.qualificationTypes ?? []).find((t: any) => Number(t.id) === id) ?? null
})

const selectedCountry = computed(() => {
  const id = Number(qualificationDetailsForm.country_id || 0)
  return (props.countries ?? []).find((c: any) => Number(c.id) === id) ?? null
})

const isForeignBySelection = computed(() => {
  const iso = (selectedCountry.value?.iso_code ?? '').toString().trim().toUpperCase()
  if (!iso) return (props.application?.is_foreign ?? false) === true
  return iso !== 'ZMB'
})

const effectiveBillingCategory = computed(() => {
  if (isForeignBySelection.value) return props.foreignFeePreview?.billing_category ?? null
  return selectedQualificationType.value?.billing_category ?? null
})

const effectiveFeePreview = computed(() => {
  if (isForeignBySelection.value) return props.foreignFeePreview?.fee_preview ?? null
  return selectedQualificationType.value?.fee_preview ?? null
})
const needsSubjects = computed(() => !!selectedQualificationType.value?.requires_subject_results)
const qualificationSaved = computed(() => !!props.application.qualification?.id)

const institutionIsOther = computed(() => qualificationDetailsForm.awarding_institution_id === 'other')

const zambiaCountryId = computed(() => {
  const byIso = props.countries?.find((c: any) => (c.iso_code ?? '').toString().toUpperCase() === 'ZMB')
  if (byIso?.id) return byIso.id
  const byName = props.countries?.find((c: any) => (c.name ?? '').toString().toLowerCase() === 'zambia')
  return byName?.id ?? null
})

type IdentifierType = 'certificate_number' | 'student_number' | 'examination_number'
const identifierType = ref<IdentifierType>('certificate_number')
const identifierValue = ref('')

function syncIdentifierFromForm() {
  const cert = (qualificationDetailsForm.certificate_number ?? '').toString().trim()
  const stud = (qualificationDetailsForm.student_number ?? '').toString().trim()
  const exam = (qualificationDetailsForm.examination_number ?? '').toString().trim()

  if (cert) {
    identifierType.value = 'certificate_number'
    identifierValue.value = cert
    return
  }
  if (stud) {
    identifierType.value = 'student_number'
    identifierValue.value = stud
    return
  }
  if (exam) {
    identifierType.value = 'examination_number'
    identifierValue.value = exam
    return
  }

  identifierType.value = 'certificate_number'
  identifierValue.value = ''
}

function applyIdentifierToForm() {
  const value = identifierValue.value.toString()
  const nextCert = identifierType.value === 'certificate_number' ? value : ''
  const nextStud = identifierType.value === 'student_number' ? value : ''
  const nextExam = identifierType.value === 'examination_number' ? value : ''

  if (qualificationDetailsForm.certificate_number !== nextCert) qualificationDetailsForm.certificate_number = nextCert
  if (qualificationDetailsForm.student_number !== nextStud) qualificationDetailsForm.student_number = nextStud
  if (qualificationDetailsForm.examination_number !== nextExam) qualificationDetailsForm.examination_number = nextExam
}

watch(identifierType, () => {
  applyIdentifierToForm()
})

watch(identifierValue, () => {
  applyIdentifierToForm()
})

onMounted(() => {
  if (!qualificationDetailsForm.country_id && zambiaCountryId.value) {
    qualificationDetailsForm.country_id = zambiaCountryId.value
  }
  syncIdentifierFromForm()
})

function saveQualificationDetails() {
  setSaving('Saving qualification details…')
  qualificationDetailsForm.put(`/applicant/applications/${props.application.id}/qualification/details`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Qualification details saved.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Qualification details could not be saved.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const subjectResultsForm = useForm<{ subject_results: Array<{ subject_name: string; grade: string }> }>({
  subject_results: (props.application.qualification?.subject_results ?? []).map((r: any) => ({
    subject_name: r.subject_name ?? '',
    grade: r.grade ?? '',
  })),
})

function addSubject() {
  subjectResultsForm.subject_results.push({ subject_name: '', grade: '' })
}

function removeSubject(index: number) {
  subjectResultsForm.subject_results.splice(index, 1)
}

function saveSubjectResults() {
  setSaving('Saving subject results…')
  subjectResultsForm.put(`/applicant/applications/${props.application.id}/qualification/subject-results`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Subject results saved.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Subject results could not be saved.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const consentName = ref('')
const localConsentForm = useForm({
  agreed_by_name: '',
})

function acceptLocalConsent() {
  localConsentForm.agreed_by_name = consentName.value
  setSaving('Saving consent…')
  localConsentForm.post(`/applicant/applications/${props.application.id}/consent/accept`, {
    preserveScroll: true,
    onSuccess: () => {
      setSaved('Consent saved.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Consent could not be saved.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const foreignConsentForm = useForm<{
  file: File | null
  zaqa_file: File | null
  source_awarding_institution_name: string
}>({
  file: null,
  zaqa_file: null,
  source_awarding_institution_name: props.application.consent_form?.source_awarding_institution_name ?? '',
})

const awardingInstitutionLabel = computed(() => {
  const q = props.application?.qualification ?? null
  const fromRelation = (q?.awarding_institution?.name ?? '').toString().trim()
  if (fromRelation) return fromRelation
  const other = (q?.awarding_institution_name_other ?? '').toString().trim()
  if (other) return other
  const legacy = (q?.awarding_institution_name ?? '').toString().trim()
  return legacy || '—'
})

function onForeignFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  foreignConsentForm.file = target.files && target.files.length > 0 ? target.files[0] : null
}

function onZaqaFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  foreignConsentForm.zaqa_file = target.files && target.files.length > 0 ? target.files[0] : null
}

function uploadForeignConsent() {
  setSaving('Uploading consent…')
  // Source awarding institution is already captured on Step 2.
  foreignConsentForm.source_awarding_institution_name = awardingInstitutionLabel.value !== '—' ? awardingInstitutionLabel.value : ''
  foreignConsentForm.post(`/applicant/applications/${props.application.id}/consent/foreign-upload`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      foreignConsentForm.reset('file', 'zaqa_file')
      setSaved('Consent uploaded.')
      router.reload({ only: ['application'] })
    },
    onError: () => setError('Consent upload failed.'),
    onFinish: () => {
      if (saveState.value.state === 'saving') saveState.value = { state: 'idle' }
    },
  })
}

const submitForm = useForm({})
const declarationAccepted = ref(false)

const submissionBlockReasons = computed(() => {
  const reasons: string[] = []
  if (!stepCompletion.value.review) reasons.push('Complete all required steps before submission.')
  if (!invoiceSettled.value) reasons.push('Payment must be confirmed before submission.')
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
const invoiceSettled = computed(() => (invoice.value?.status ?? '') === 'paid' || (payment.value?.status ?? '') === 'confirmed')

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

watch(needsSubjects, (val) => {
  if (!val && activeStep.value === 'subjects') goToStep('documents')
})

function hasCurrentDocumentType(type: string) {
  return (props.application?.documents ?? []).some((d: any) => d.document_type === type && d.is_current_version)
}

const stepCompletion = computed(() => {
  const applicantType = (props.applicant?.applicant_type ?? '').toString()
  const selfNrc = (props.applicant?.applicant_profile?.nrc_number ?? '').toString().trim()
  const selfPassport = (props.applicant?.applicant_profile?.passport_number ?? '').toString().trim()
  const submittingFor = ((props.application?.metadata?.submitting_for ?? 'self') as string).toString()
  const subject = props.application?.metadata?.verification_subject ?? null
  const otherFullName = (subject?.full_name ?? '').toString().trim()
  const otherNrc = (subject?.nrc_number ?? '').toString().trim()
  const otherPassport = (subject?.passport_number ?? '').toString().trim()

  const identityOk =
    submittingFor === 'other'
      ? otherFullName.length > 0 && (otherNrc.length > 0 || otherPassport.length > 0)
      : applicantType === 'individual'
        ? selfNrc.length > 0 || selfPassport.length > 0
        : true
  const applicantOk =
    (props.applicant?.email ?? '').toString().trim().length > 0 &&
    (props.applicant?.phone_primary ?? '').toString().trim().length > 0 &&
    identityOk

  const q = props.application?.qualification
  const qualificationOk =
    !!q?.id &&
    (q?.qualification_holder_name ?? '').toString().trim().length > 0 &&
    (q?.nrc_passport_number ?? '').toString().trim().length > 0 &&
    !!q?.country_id &&
    (!!q?.awarding_institution_id || (q?.awarding_institution_name_other ?? '').toString().trim().length > 0) &&
    (q?.title_of_qualification ?? '').toString().trim().length > 0 &&
    (q?.award_date ?? '').toString().trim().length > 0 &&
    Number(q?.qualification_type_id ?? 0) > 0 &&
    [
      (q?.certificate_number ?? '').toString().trim(),
      (q?.student_number ?? '').toString().trim(),
      (q?.examination_number ?? '').toString().trim(),
    ].some((v) => v.length > 0)

  const subjectsOk = !needsSubjects.value ? true : (q?.subject_results ?? []).length > 0

  const documentsOk =
    hasCurrentDocumentType('nrc_copy') &&
    hasCurrentDocumentType('certificate_copy') &&
    (props.application?.is_foreign ? hasCurrentDocumentType('transcript') : true)

  const consentOk = props.application?.is_foreign
    ? !!props.application?.consent_form?.uploaded_document_id && !!props.application?.consent_form?.zaqa_uploaded_document_id
    : !!props.application?.consent_form?.agreed_at

  const paymentOk = invoiceSettled.value

  return {
    applicant: applicantOk,
    qualification: qualificationOk,
    subjects: subjectsOk,
    documents: documentsOk,
    consent: consentOk,
    payment: paymentOk,
    review: applicantOk && qualificationOk && subjectsOk && documentsOk && consentOk && paymentOk,
  } as Record<StepKey, boolean>
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

function goNext(from: StepKey) {
  const keys = steps.value.map((s) => s.key) as StepKey[]
  const idx = keys.indexOf(from)
  const next = keys[idx + 1] ?? null
  if (!next) return

  if (!stepCompletion.value[from]) {
    void Swal.fire({
      icon: 'warning',
      title: 'Incomplete step',
      text: 'Please complete and save this step before continuing.',
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
      text: 'Please save your changes before continuing.',
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
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnload)
})
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div class="zaqa-wizard-shell">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Application wizard</h1>
            <p class="mt-1 text-sm text-text-muted">
              {{ application.application_number }} • {{ application.status_label }}
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
              <label class="zaqa-radio-card" :class="submittingForForm.submitting_for === 'self' ? 'zaqa-radio-card-active' : ''">
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

            <div v-if="submittingForForm.submitting_for === 'self'" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div class="rounded-xl border border-border bg-surface px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Name</div>
                <div class="mt-1 text-sm font-semibold text-text-primary">
                  {{ props.applicant?.name ?? '—' }}
                </div>
              </div>
              <div class="rounded-xl border border-border bg-surface px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">NRC / Passport</div>
                <div class="mt-1 text-sm font-semibold text-text-primary">
                  {{ props.applicant?.applicant_profile?.nrc_number || props.applicant?.applicant_profile?.passport_number || '—' }}
                </div>
              </div>
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
                <button type="button" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto" @click="saveApplicantDetails('qualification')" :disabled="applicantForm.processing || !applicantForm.isDirty">
                  Save & continue
                </button>
              </WizardFooterBar>
            </div>
          </form>
        </section>

        <section v-else-if="activeStep === 'qualification'" class="rounded-xl border border-border bg-surface p-5">
          <h2 class="text-sm font-semibold text-text-primary">Qualification details</h2>
          <p class="mt-1 text-xs text-text-muted">Enter details exactly as they appear on your documents.</p>

          <form class="mt-4 space-y-4" @submit.prevent="saveQualificationDetails">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="text-sm font-medium">Country of award</label>
                <select v-model="qualificationDetailsForm.country_id" class="zaqa-input">
                  <option value="">Select country</option>
                  <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <InputError :message="qualificationDetailsForm.errors.country_id" />
              </div>

              <div>
                <InstitutionCombobox
                  :country-id="qualificationDetailsForm.country_id"
                  v-model="qualificationDetailsForm.awarding_institution_id"
                  query-endpoint="/applicant/reference/awarding-institutions"
                  :error="qualificationDetailsForm.errors.awarding_institution_id"
                />
              </div>

              <div v-if="institutionIsOther" class="sm:col-span-2">
                <label class="text-sm font-medium">Other institution name</label>
                <input v-model="qualificationDetailsForm.awarding_institution_name_other" class="zaqa-input" placeholder="Type the awarding institution name" />
                <div class="mt-1 text-xs text-text-muted">
                  This name is used only for your application and is not added to the master list automatically.
                </div>
                <InputError :message="qualificationDetailsForm.errors.awarding_institution_name_other" />
              </div>
            </div>

            <div>
              <label class="text-sm font-medium">Qualification type (ZQF level)</label>
              <select v-model="qualificationDetailsForm.qualification_type_id" class="zaqa-input" :disabled="!!application.invoice">
                <option value="" disabled>Select a qualification type…</option>
                <option v-for="t in qualificationTypes" :key="t.id" :value="t.id">
                   {{ t.name }}
                </option>
              </select>
              <InputError :message="(qualificationDetailsForm.errors as any).qualification_type_id" />
              <div v-if="application.invoice" class="mt-2 rounded-lg border border-warning/20 bg-warning/10 px-3 py-2 text-xs text-warning">
                An invoice has been generated. Qualification type is now locked and cannot be changed.
              </div>

              <div v-if="effectiveBillingCategory" class="mt-2 rounded-lg border border-border bg-surface-muted px-3 py-2 text-xs text-text-muted">
                  <div class="font-semibold text-text-primary">
                  {{ effectiveBillingCategory.name }}
                    <span v-if="isForeignBySelection" class="ml-2 rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand">Foreign Qualification</span>
                  <span v-else class="ml-2 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Local Qualification</span>
                </div>
                <div class="mt-1">
                  Estimated processing time:
                  <span class="font-semibold text-text-primary">
                      {{ isForeignBySelection ? effectiveBillingCategory.foreign_processing_days : effectiveBillingCategory.local_processing_days }}
                    working days
                  </span>
                </div>
                <div v-if="effectiveFeePreview" class="mt-1">
                  Fee:
                  <span class="font-semibold text-text-primary">
                    {{
                      new Intl.NumberFormat(undefined, {
                        style: 'currency',
                        currency: effectiveFeePreview.currency || 'ZMW',
                        }).format(((isForeignBySelection ? effectiveFeePreview.foreign_fee_cents : effectiveFeePreview.local_fee_cents) || 0) / 100)
                    }}
                  </span>
                    <span v-if="isForeignBySelection" class="ml-2 text-[11px] font-semibold text-text-muted">(Foreign Qualifications fee applied)</span>
                </div>
              </div>
            </div>

            <div>
              <label class="text-sm font-medium">Title of qualification</label>
              <input v-model="qualificationDetailsForm.title_of_qualification" class="zaqa-input" />
              <InputError :message="qualificationDetailsForm.errors.title_of_qualification" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="text-sm font-medium">Identifier type</label>
                <select v-model="identifierType" class="zaqa-input">
                  <option value="certificate_number">Certificate number</option>
                  <option value="student_number">Student number</option>
                  <option value="examination_number">Examination number</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-medium">
                  Identifier value
                  <span class="text-text-muted font-normal">({{ identifierType.replace('_', ' ').replace('_', ' ') }})</span>
                </label>
                <input v-model="identifierValue" class="zaqa-input" />
                <InputError :message="qualificationDetailsForm.errors.certificate_number" />
              </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="text-sm font-medium">Award date</label>
                <input v-model="qualificationDetailsForm.award_date" type="date" class="zaqa-input" />
                <InputError :message="qualificationDetailsForm.errors.award_date" />
              </div>
            </div>

            <div>
              <label class="text-sm font-medium">Notes (optional)</label>
              <textarea v-model="qualificationDetailsForm.notes" class="zaqa-input min-h-[90px]" />
              <InputError :message="qualificationDetailsForm.errors.notes" />
            </div>

            <WizardFooterBar
              :show-prev="!!stepNav.prev"
              :show-next="!!stepNav.next"
              :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
              :on-next="() => goNext('qualification')"
            >
              <button
                type="button"
                class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto"
                :disabled="qualificationDetailsForm.processing || !qualificationDetailsForm.isDirty"
                @click="saveQualificationDetails()"
              >
                Save changes
              </button>
            </WizardFooterBar>
          </form>
        </section>

        <section v-else-if="activeStep === 'subjects'" class="rounded-xl border border-border bg-surface p-5">
          <h2 class="text-sm font-semibold text-text-primary">Subject results</h2>
          <p class="mt-1 text-xs text-text-muted">Add subjects and grades as they appear on the certificate.</p>

          <div v-if="!qualificationSaved" class="mt-4 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning">
            Save qualification details first, then add subject results.
          </div>

          <form v-else class="mt-4 space-y-4" @submit.prevent="saveSubjectResults">
            <div class="flex items-center justify-between">
              <div class="text-sm font-semibold text-text-primary">Subjects</div>
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-sm" @click="addSubject">Add subject</button>
            </div>

            <div class="space-y-3">
              <div v-for="(row, idx) in subjectResultsForm.subject_results" :key="idx" class="grid grid-cols-1 gap-3 sm:grid-cols-7">
                <div class="sm:col-span-4">
                  <label class="text-xs font-medium">Subject</label>
                  <input v-model="row.subject_name" class="zaqa-input" />
                  <InputError :message="(subjectResultsForm.errors as any)[`subject_results.${idx}.subject_name`]" />
                </div>
                <div class="sm:col-span-2">
                  <label class="text-xs font-medium">Grade</label>
                  <input v-model="row.grade" class="zaqa-input" />
                  <InputError :message="(subjectResultsForm.errors as any)[`subject_results.${idx}.grade`]" />
                </div>
                <div class="sm:col-span-1 sm:flex sm:items-end">
                  <button type="button" class="zaqa-btn zaqa-btn-ghost w-full px-3 py-2 text-sm" @click="removeSubject(idx)">
                    Remove
                  </button>
                </div>
              </div>
            </div>

            <InputError :message="(subjectResultsForm.errors as any).subject_results" />

            <WizardFooterBar
              :show-prev="!!stepNav.prev"
              :show-next="!!stepNav.next"
              :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
              :on-next="() => goNext('subjects')"
            />
          </form>
        </section>

        <section v-else-if="activeStep === 'documents'" class="rounded-xl border border-border bg-surface p-5">
          <h2 class="text-sm font-semibold text-text-primary">Documents</h2>
          <p class="mt-1 text-xs text-text-muted">Upload clear, readable copies. PDF is preferred.</p>

          <div class="mt-4">
            <DocumentManager
              :upload-url="`/applicant/applications/${application.id}/documents`"
              :documents="application.documents"
              :transcript-required="transcriptRequired"
            />
          </div>

          <WizardFooterBar
            :show-prev="!!stepNav.prev"
            :show-next="!!stepNav.next"
            :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
            :on-next="() => goNext('documents')"
          />
        </section>

        <section v-else-if="activeStep === 'consent'" class="rounded-xl border border-border bg-surface p-5">
          <h2 class="text-sm font-semibold text-text-primary">Consent</h2>

          <div v-if="application.is_foreign" class="mt-3">
            <p class="text-sm text-text-muted">
              Foreign applications require two consent uploads:
              <span class="font-semibold text-text-primary">Awarding Institution consent</span> and
              <span class="font-semibold text-text-primary">ZAQA consent</span>.
            </p>

            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div
                class="rounded-lg border px-4 py-3 text-sm"
                :class="application.consent_form?.uploaded_document_id ? 'border-success/20 bg-success/10 text-success' : 'border-warning/20 bg-warning/10 text-warning'"
              >
                <div class="font-semibold">Awarding Institution consent</div>
                <div class="mt-1 text-xs opacity-90">
                  {{ application.consent_form?.uploaded_document_id ? 'Uploaded' : 'Missing' }}
                </div>
              </div>
              <div
                class="rounded-lg border px-4 py-3 text-sm"
                :class="application.consent_form?.zaqa_uploaded_document_id ? 'border-success/20 bg-success/10 text-success' : 'border-warning/20 bg-warning/10 text-warning'"
              >
                <div class="font-semibold">ZAQA consent</div>
                <div class="mt-1 text-xs opacity-90">
                  {{ application.consent_form?.zaqa_uploaded_document_id ? 'Uploaded' : 'Missing' }}
                </div>
              </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Awarding institution</div>
                <div class="mt-2 rounded-xl border border-border bg-surface-muted px-4 py-3 text-sm font-semibold text-text-primary">
                  {{ awardingInstitutionLabel }}
                </div>
                <div class="mt-1 text-xs text-text-muted">This is pulled from Step 2 and saved with your application.</div>
              </div>

              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Awarding Institution signed consent form</label>
                <input type="file" accept="application/pdf,image/*" class="zaqa-input" @change="onForeignFileChange" />
                <InputError :message="foreignConsentForm.errors.file" />
              </div>

              <div class="sm:col-span-2">
                <label class="text-sm font-medium">ZAQA signed consent form</label>
                <input type="file" accept="application/pdf,image/*" class="zaqa-input" @change="onZaqaFileChange" />
                <InputError :message="(foreignConsentForm.errors as any).zaqa_file" />
              </div>

              <button
                type="button"
                class="zaqa-btn zaqa-btn-primary sm:col-span-2"
                :disabled="foreignConsentForm.processing || !foreignConsentForm.file || !foreignConsentForm.zaqa_file"
                @click="uploadForeignConsent"
              >
                Upload signed consent
              </button>
            </div>
          </div>

          <div v-else class="mt-3">
            <p class="text-sm text-text-muted">Read the consent statement below and accept it before submitting.</p>

            <div class="mt-4 rounded-lg border border-border bg-surface-muted p-4">
              <div class="text-sm font-semibold">{{ localConsent.title }}</div>
              <div class="mt-2 max-h-56 overflow-auto whitespace-pre-wrap text-sm text-text-primary">{{ localConsent.text }}</div>
              <div class="mt-2 text-xs text-text-muted">Version: {{ localConsent.version }}</div>
            </div>

            <div v-if="application.consent_form?.agreed_at" class="mt-3 rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">
              Accepted on {{ application.consent_form.agreed_at }} by {{ application.consent_form.agreed_by_name }}.
            </div>

            <div v-if="!application.consent_form?.agreed_at" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="sm:col-span-2">
                <label class="text-sm font-medium">Type your full name to confirm</label>
                <input v-model="consentName" class="zaqa-input" />
                <InputError :message="localConsentForm.errors.agreed_by_name" />
              </div>

              <button type="button" class="zaqa-btn zaqa-btn-primary mt-6 px-4 py-2 text-sm sm:mt-7" :disabled="localConsentForm.processing || consentName.trim().length === 0" @click="acceptLocalConsent">
                Accept consent
              </button>
            </div>

            <div class="mt-4 rounded-xl border border-border bg-surface p-4">
              <div class="text-sm font-semibold text-text-primary">Upload signed consent form (optional)</div>
              <div class="mt-1 text-xs text-text-muted">
                If you already have a signed consent form, you may upload it here for reference. This is optional for now.
              </div>

              <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <label class="text-sm font-medium">Signed consent form file</label>
                  <input type="file" accept="application/pdf,image/*" class="zaqa-input" @change="onForeignFileChange" />
                  <InputError :message="foreignConsentForm.errors.file" />
                </div>
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-secondary sm:col-span-2"
                  :disabled="foreignConsentForm.processing || !foreignConsentForm.file"
                  @click="uploadForeignConsent"
                >
                  Upload signed consent (optional)
                </button>
              </div>
            </div>
          </div>

          <WizardFooterBar
            :show-prev="!!stepNav.prev"
            :show-next="!!stepNav.next"
            :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
            :on-next="() => goNext('consent')"
          />
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
                    {{ ((invoice?.amount_cents ?? 0) / 100).toFixed(2) }}
                    <span class="text-sm font-semibold text-text-muted">{{ invoice?.currency ?? 'ZMW' }}</span>
                  </div>
                </div>
                <span class="zaqa-badge" :class="invoice?.status === 'paid' ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ invoice?.status ?? 'issued' }}
                </span>
              </div>
            </div>

            <div class="mt-3 rounded-lg border border-warning/20 bg-warning/10 px-3 py-2 text-xs text-warning">
              Reaching this step generates an invoice and locks the qualification type. Change it before Payment if needed.
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

            <div v-else>
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
          </div>

          <WizardFooterBar
            :show-prev="!!stepNav.prev"
            :show-next="!!stepNav.next"
            :on-prev="() => stepNav.prev && requestStepChange(stepNav.prev)"
            :on-next="() => goNext('payment')"
          />
        </section>

        <section v-else class="rounded-xl border border-border bg-surface p-5">
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

              <div v-if="!application.qualification" class="mt-3 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">
                Qualification details are missing.
                <InputError :message="(submitForm.errors as any).qualification" class="mt-2" />
              </div>
              <div v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification type</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{ application.qualification.qualification_type_master?.level_label }} — {{ application.qualification.qualification_type_master?.name }}
                  </div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Local / foreign</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.is_foreign ? 'Foreign' : 'Local' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Country of award</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{ countries.find((c) => c.id === application.qualification.country_id)?.name ?? '—' }}
                  </div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Awarding institution</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{
                      application.qualification.awarding_institution_name_other ||
                      application.qualification.awarding_institution?.name ||
                      application.qualification.awarding_institution_name ||
                      '—'
                    }}
                  </div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Title of qualification</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.title_of_qualification || '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Award date</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.award_date || '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Certificate number</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.certificate_number || '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Student number</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.student_number || '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Examination number</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.qualification.examination_number || '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3 sm:col-span-2">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Notes</div>
                  <div class="mt-1 whitespace-pre-wrap text-sm font-semibold text-text-primary">{{ application.qualification.notes || '—' }}</div>
                </div>
              </div>

              <div class="my-6 h-px bg-border/70" />

              <!-- Documents -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">3. Supporting documents</div>
                  <div class="mt-1 text-xs text-text-muted">Uploaded documents required for processing.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('documents')">Edit</button>
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

              <!-- Consent -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">4. Consent</div>
                  <div class="mt-1 text-xs text-text-muted">Consent status for this application.</div>
                </div>
                <button v-if="application.can_edit" type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="goToStep('consent')">Edit</button>
              </div>
              <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Consent type</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">{{ application.consent_form?.consent_type ?? '—' }}</div>
                </div>
                <div class="rounded-xl border border-border bg-surface-muted px-4 py-3">
                  <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Status</div>
                  <div class="mt-1 text-sm font-semibold text-text-primary">
                    {{
                      application.is_foreign
                        ? application.consent_form?.uploaded_document_id && application.consent_form?.zaqa_uploaded_document_id
                          ? 'Uploaded'
                          : 'Pending'
                        : application.consent_form?.agreed_at
                          ? 'Accepted'
                          : 'Pending'
                    }}
                  </div>
                </div>
              </div>
              <InputError :message="(submitForm.errors as any).consent" class="mt-2" />

              <div class="my-6 h-px bg-border/70" />

              <!-- Payment -->
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-text-primary">5. Payment</div>
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
                <div class="text-sm font-semibold text-text-primary">6. Final declaration</div>
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
            <button type="button" class="zaqa-btn zaqa-btn-secondary w-full sm:w-auto" @click="goToStep('context')">Back to start</button>
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
  </ApplicantLayout>
</template>
