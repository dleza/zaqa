<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import QualificationAmendmentBanner from '@/Components/QualificationAmendmentBanner.vue'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import QualificationTitleCombobox from '@/Components/QualificationTitleCombobox.vue'
import InputError from '@/Components/InputError.vue'
import SubjectGradeSelect from '@/Components/SubjectGradeSelect.vue'
import Swal from 'sweetalert2'
import { selectGradeValue } from '@/lib/certificateSubjectGrades'
import { resolveCertificateSubjectId } from '@/lib/resolveCertificateSubjectId'
import {
  APPLICANT_DOCUMENT_ACCEPT,
  APPLICANT_DOCUMENT_FILE_ERROR,
  isAllowedApplicantDocumentFile,
} from '@/lib/applicantDocumentUpload'
import { useUploadLimits } from '@/lib/uploadLimits'
import 'sweetalert2/dist/sweetalert2.min.css'
import { Building2, FileStack, GraduationCap, MapPin, Shield, Sparkles, Trash2, UserRound } from 'lucide-vue-next'

const inertiaPage = usePage()
const { pdfOrImageHint } = useUploadLimits()

const props = defineProps<{
  application: any
  qualificationId?: number | null
  countries: Array<{ id: number; name: string; iso_code?: string | null }>
  qualificationTypes: Array<any>
  /** Active rows from `certificate_subjects` (admin-managed). */
  certificateSubjects: Array<{ id: number; name: string }>
  subjectGradeOptions?: string[]
  /** Institution multiple flow: per-qualification holder identity + docs. */
  institutionalMode?: boolean
}>()

const institutionalMode = computed(() => props.institutionalMode === true)

const mode = computed<'add' | 'edit'>(() => (props.qualificationId ? 'edit' : 'add'))

const returnUrl = computed(() => {
  try {
    const url = new URL(window.location.href)
    const v = (url.searchParams.get('return') ?? '').toString().trim()
    if (v) return v
  } catch {
    // ignore
  }
  if (institutionalMode.value) {
    return `/applicant/applications/multiple/${props.application.id}/edit?step=qualification_records`
  }
  return `/applicant/applications/${props.application.id}/edit?step=qualification`
})

const zambiaCountryId = computed(() => {
  const byIso = props.countries?.find((c: any) => (c.iso_code ?? '').toString().toUpperCase() === 'ZMB')
  if (byIso?.id) return byIso.id
  const byName = props.countries?.find((c: any) => (c.name ?? '').toString().toLowerCase() === 'zambia')
  return byName?.id ?? null
})

const qualifications = computed<any[]>(() => {
  const list = props.application?.qualifications
  if (Array.isArray(list)) return list
  if (props.application?.qualification) return [props.application.qualification]
  return []
})

const editingQualification = computed<any | null>(() => {
  const id = Number(props.qualificationId ?? 0)
  if (!id) return null
  return qualifications.value.find((q) => Number(q.id) === id) ?? null
})

const invoiceSettled = computed(() => props.application?.payment_satisfied === true)
const correctionRequiredMode = computed(() => props.application?.correction_required_mode === true)
const anyReturnedForAmendment = computed(() =>
  qualifications.value.some((q: any) => (q.verification_state ?? '') === 'returned_to_applicant'),
)
const locked = computed(() => {
  if (correctionRequiredMode.value || anyReturnedForAmendment.value) {
    const q = editingQualification.value
    return !q || (q.verification_state ?? '') !== 'returned_to_applicant'
  }
  return invoiceSettled.value || !!props.application?.paid_at
})

const modalQualId = ref<number | null>(null)
const institutionMeta = ref<{ name: string; consent_form_url?: string | null; has_consent_form?: boolean } | null>(null)

type IdentifierType = 'certificate_number' | 'student_number' | 'examination_number'
const identifierType = ref<IdentifierType>('certificate_number')
const identifierValue = ref('')

function blankQualificationForm() {
  const base: Record<string, any> = {
    qualification_id: null as number | null,
    country_id: '' as number | string | '',
    country_name_other: '',
    awarding_institution_id: '' as number | string | 'other' | '',
    awarding_institution_name_other: '',
    awarding_institution_name: '' as string,
    certificate_number: '',
    student_number: '',
    examination_number: '',
    title_of_qualification: '',
    names_as_on_qualification_document: '',
    qualification_title_id: null as number | null,
    qualification_title_source: 'catalog' as 'catalog' | 'other' | '',
    applicant_entered_qualification_title: '',
    award_date: '',
    qualification_type_id: '' as number | string | '',
    transcript_reason: '',
    subject_results: [] as Array<{ certificate_subject_id: number | ''; grade: string; saved_grade?: string }>,
  }

  if (institutionalMode.value) {
    Object.assign(base, {
      holder_first_name: '',
      holder_middle_name: '',
      holder_surname: '',
      holder_identity_type: 'nrc',
      holder_date_of_birth: '',
      holder_gender: '',
      holder_phone: '',
      holder_email: '',
      nrc_passport_number: '',
    })
  }

  return base
}

const form = useForm(blankQualificationForm())
const titleChoice = ref<number | 'other' | ''>('')
const isHydratingForm = ref(false)

/** Staged files uploaded in the same action as qualification save */
const pendingCertificateFile = ref<File | null>(null)
const pendingTranscriptFile = ref<File | null>(null)
const pendingConsentFile = ref<File | null>(null)
const pendingIdentityFile = ref<File | null>(null)
const savingAll = ref(false)

const identityFileInputEl = ref<HTMLInputElement | null>(null)

const certificateFileInputEl = ref<HTMLInputElement | null>(null)
const transcriptFileInputEl = ref<HTMLInputElement | null>(null)
const consentFileInputEl = ref<HTMLInputElement | null>(null)

function clearFileInputs() {
  if (certificateFileInputEl.value) certificateFileInputEl.value.value = ''
  if (transcriptFileInputEl.value) transcriptFileInputEl.value.value = ''
  if (consentFileInputEl.value) consentFileInputEl.value.value = ''
  if (identityFileInputEl.value) identityFileInputEl.value.value = ''
}

function syncIdentifierFromForm() {
  const cert = (form.certificate_number ?? '').toString().trim()
  const stud = (form.student_number ?? '').toString().trim()
  const exam = (form.examination_number ?? '').toString().trim()
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
  form.certificate_number = identifierType.value === 'certificate_number' ? value : ''
  form.student_number = identifierType.value === 'student_number' ? value : ''
  form.examination_number = identifierType.value === 'examination_number' ? value : ''
}

watch(identifierType, () => applyIdentifierToForm())
watch(identifierValue, () => applyIdentifierToForm())

watch(
  () => form.awarding_institution_id,
  () => {
    if (isHydratingForm.value) return
    titleChoice.value = ''
    form.title_of_qualification = ''
    form.qualification_title_id = null
    form.applicant_entered_qualification_title = ''
    form.qualification_title_source = 'catalog'
  },
)

watch(titleChoice, (choice) => {
  if (choice === 'other') {
    form.qualification_title_source = 'other'
    form.qualification_title_id = null
    const manual = (form.applicant_entered_qualification_title ?? '').toString().trim()
    form.title_of_qualification = manual
    return
  }

  if (typeof choice === 'number' && choice > 0) {
    form.qualification_title_source = 'catalog'
    form.applicant_entered_qualification_title = ''
    form.qualification_title_id = choice
    return
  }

  form.qualification_title_source = 'catalog'
  form.qualification_title_id = null
  form.title_of_qualification = ''
})

watch(
  () => form.applicant_entered_qualification_title,
  (manual) => {
    if (titleChoice.value !== 'other') return
    form.title_of_qualification = (manual ?? '').toString()
  },
)

function awardingDisplayName(): string {
  if (form.awarding_institution_id === 'other') {
    return (form.awarding_institution_name_other ?? '').toString().trim()
  }
  return (
    (institutionMeta.value?.name ?? '').toString().trim() ||
    (form.awarding_institution_name ?? '').toString().trim()
  )
}

function onInstitutionSelected(opt: { id: number | 'other'; name: string; consent_form_url?: string | null; has_consent_form?: boolean }) {
  institutionMeta.value =
    opt.id === 'other'
      ? { name: '', consent_form_url: null, has_consent_form: false }
      : {
          name: opt.name,
          consent_form_url: opt.consent_form_url ?? null,
          has_consent_form: opt.has_consent_form,
        }
}

const selectedCountry = computed(() => {
  const id = Number(form.country_id || 0)
  return props.countries?.find((c) => Number(c.id) === id) ?? null
})

const countryIso = computed(() => (selectedCountry.value?.iso_code ?? '').toString().trim().toUpperCase())

/** Country of award is outside Zambia → institution consent + foreign doc rules */
const isForeignAwarding = computed(() => {
  const iso = countryIso.value
  if (!iso) return false
  return iso !== 'ZMB' && iso !== 'ZM'
})

const selectedQualificationType = computed(() => {
  const id = Number(form.qualification_type_id || 0)
  return (props.qualificationTypes ?? []).find((t: any) => Number(t.id) === id) ?? null
})

const needsSubjects = computed(() => !!selectedQualificationType.value?.requires_subject_results)

const transcriptRequiredForDocs = computed(() => {
  if (isForeignAwarding.value) return true
  return !!selectedQualificationType.value?.requires_subject_results
})

const freshQualification = computed(() => {
  const id = modalQualId.value
  if (!id) return null
  return qualifications.value.find((q) => Number(q.id) === Number(id)) ?? editingQualification.value
})

const institutionConsentUrl = computed(() => {
  const q = freshQualification.value
  if (q?.institution_consent_form_url) return q.institution_consent_form_url as string
  return institutionMeta.value?.consent_form_url ?? null
})

const subjectGradeOptions = computed(() => props.subjectGradeOptions ?? [])

function addSubjectRow() {
  form.subject_results.push({ certificate_subject_id: '', grade: '', saved_grade: '' })
}

function removeSubjectRow(idx: number) {
  form.subject_results.splice(idx, 1)
}

async function loadFromQualification(q: any) {
  isHydratingForm.value = true
  try {
    form.qualification_id = q.id
    form.country_name_other = q.country_name_other ?? ''
    form.awarding_institution_name_other = q.awarding_institution_name_other ?? ''
    form.awarding_institution_name = q.awarding_institution_name ?? ''
    form.certificate_number = q.certificate_number ?? ''
    form.student_number = q.student_number ?? ''
    form.examination_number = q.examination_number ?? ''
    form.names_as_on_qualification_document = q.names_as_on_qualification_document ?? ''
    form.award_date = q.award_date ?? ''
    form.qualification_type_id = q.qualification_type_id ?? ''
    form.transcript_reason = q.transcript_reason ?? ''
    form.subject_results = (q.subject_results ?? []).map((r: any) => ({
      certificate_subject_id: resolveCertificateSubjectId(r, props.certificateSubjects ?? []),
      subject_name: r.subject_name ?? '',
      grade: selectGradeValue(r.grade),
      saved_grade: r.grade ?? '',
    }))

    const resolvedSource = (q.qualification_title_source ?? '').toString()
    const isOtherTitle =
      resolvedSource === 'other' ||
      (!q.qualification_title_id && !!(q.applicant_entered_qualification_title ?? q.title_of_qualification))

    form.qualification_title_source = isOtherTitle ? 'other' : resolvedSource || 'catalog'
    form.qualification_title_id = q.qualification_title_id != null ? Number(q.qualification_title_id) : null
    form.applicant_entered_qualification_title = isOtherTitle
      ? (q.applicant_entered_qualification_title ?? q.title_of_qualification ?? '')
      : (q.applicant_entered_qualification_title ?? '')
    form.title_of_qualification = isOtherTitle
      ? (q.applicant_entered_qualification_title ?? q.title_of_qualification ?? '')
      : (q.title_of_qualification ?? '')

    if (institutionalMode.value) {
      const hi = q.holder_identity ?? {}
      form.holder_first_name = hi.first_name ?? ''
      form.holder_middle_name = hi.middle_name ?? ''
      form.holder_surname = hi.surname ?? ''
      form.holder_identity_type = hi.identity_type ?? 'nrc'
      form.holder_date_of_birth = hi.date_of_birth ?? ''
      form.holder_gender = hi.gender ?? ''
      form.holder_phone = hi.phone ?? ''
      form.holder_email = hi.email ?? ''
      form.nrc_passport_number = q.nrc_passport_number ?? ''
    }

    institutionMeta.value = {
      name:
        (q.awarding_institution?.name ?? '').trim() ||
        (q.awarding_institution_name_other ?? '').trim() ||
        (q.awarding_institution_name ?? '').trim() ||
        '',
      consent_form_url: q.institution_consent_form_url ?? null,
      has_consent_form: q.institution_has_consent_form ?? !!q.institution_consent_form_url,
    }

    form.country_id = q.country_id ?? ''
    form.awarding_institution_id =
      q.awarding_institution_id != null && q.awarding_institution_id !== ''
        ? q.awarding_institution_id
        : q.awarding_institution_name_other || q.awarding_institution_name
          ? 'other'
          : ''

    if (!form.awarding_institution_name_other && q.awarding_institution_name && !q.awarding_institution_id) {
      form.awarding_institution_name_other = q.awarding_institution_name
    }

    await nextTick()

    titleChoice.value =
      form.qualification_title_source === 'other'
        ? 'other'
        : form.qualification_title_id && form.qualification_title_id > 0
          ? form.qualification_title_id
          : ''

    syncIdentifierFromForm()
    await nextTick()
  } finally {
    isHydratingForm.value = false
  }
}

async function initForm() {
  form.clearErrors()
  pendingCertificateFile.value = null
  pendingTranscriptFile.value = null
  pendingConsentFile.value = null
  clearFileInputs()
  savingAll.value = false
  institutionMeta.value = null

  if (mode.value === 'edit') {
    const q = editingQualification.value
    if (!q) {
      await Swal.fire({
        icon: 'error',
        title: 'Qualification not found',
        text: 'This qualification does not exist or is not part of this application.',
      })
      router.visit(returnUrl.value)
      return
    }
    modalQualId.value = q.id
    await loadFromQualification(q)
    return
  }

  modalQualId.value = null
  const defaults = blankQualificationForm()
  if (zambiaCountryId.value) defaults.country_id = zambiaCountryId.value
  form.defaults(defaults)
  form.reset()
  syncIdentifierFromForm()
}

watch(needsSubjects, (need) => {
  if (need && form.subject_results.length === 0) addSubjectRow()
})

onMounted(() => {
  void initForm()
})

function readCreatedQualificationIdFromFlash(source: any): number {
  const raw =
    source?.flash?.created_qualification_id ??
    source?.props?.flash?.created_qualification_id ??
    null
  const id = Number(raw ?? 0)
  return id > 0 ? id : 0
}

function extractQualificationIdFromPage(page: any): number {
  if (mode.value === 'edit' && form.qualification_id) {
    return Number(form.qualification_id)
  }

  const fromFlash = readCreatedQualificationIdFromFlash(page)
  if (fromFlash > 0) return fromFlash

  const app = page?.props?.application
  const list = (app?.qualifications ?? []) as any[]
  const ids = list.map((q) => Number(q.id)).filter(Boolean)
  return ids.length ? Math.max(...ids) : 0
}

async function resolveSavedQualificationId(page: any): Promise<number> {
  await nextTick()

  let qid = extractQualificationIdFromPage(page)
  if (qid > 0) return qid

  qid = readCreatedQualificationIdFromFlash({
    props: inertiaPage.props,
    flash: page?.flash,
  })
  if (qid > 0) return qid

  return extractQualificationIdFromPage({ props: inertiaPage.props })
}

/** Inertia router.post uses callbacks — wrap for async/await sequencing. */
function routerPostMultipart(url: string, data: Record<string, unknown>): Promise<void> {
  return new Promise((resolve, reject) => {
    router.post(url, data, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => resolve(),
      onError: () => reject(new Error('request_failed')),
    })
  })
}

function hasExistingDoc(docType: string): boolean {
  return !!existingDocument(docType)
}

function existingDocument(docType: string): { id: number; original_name?: string | null } | null {
  const qid = modalQualId.value
  if (!qid) return null
  const doc = (props.application?.documents ?? []).find(
    (d: any) =>
      d.document_type === docType &&
      d.is_current_version &&
      Number(d.qualification_id ?? 0) === Number(qid),
  )
  return doc ?? null
}

const deletingDocumentId = ref<number | null>(null)

async function confirmDeleteDocument(doc: { id: number; original_name?: string | null } | null) {
  if (!doc || locked.value) return

  const result = await Swal.fire({
    icon: 'warning',
    title: 'Remove document?',
    text: 'This will remove the uploaded file. You can attach a new one afterwards.',
    showCancelButton: true,
    confirmButtonText: 'Remove',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#B42318',
  })

  if (!result.isConfirmed) return

  deletingDocumentId.value = doc.id
  router.delete(`/applicant/documents/${doc.id}`, {
    preserveScroll: true,
    only: ['application'],
    onFinish: () => {
      deletingDocumentId.value = null
    },
  })
}

function hasPendingUploads(): boolean {
  return !!(
    pendingCertificateFile.value ||
    pendingTranscriptFile.value ||
    pendingConsentFile.value ||
    pendingIdentityFile.value
  )
}

function assignPendingFile(
  file: File | null,
  input: HTMLInputElement | null,
  setter: (value: File | null) => void,
) {
  if (!file) {
    setter(null)
    return
  }

  if (!isAllowedApplicantDocumentFile(file)) {
    setter(null)
    if (input) input.value = ''
    void Swal.fire({
      icon: 'error',
      title: 'Invalid file type',
      text: APPLICANT_DOCUMENT_FILE_ERROR,
    })
    return
  }

  setter(file)
}

function onPendingCertificateChange(e: Event) {
  const t = e.target as HTMLInputElement
  assignPendingFile(t.files?.[0] ?? null, t, (value) => {
    pendingCertificateFile.value = value
  })
}

function onPendingTranscriptChange(e: Event) {
  const t = e.target as HTMLInputElement
  assignPendingFile(t.files?.[0] ?? null, t, (value) => {
    pendingTranscriptFile.value = value
  })
}

function onPendingConsentChange(e: Event) {
  const t = e.target as HTMLInputElement
  assignPendingFile(t.files?.[0] ?? null, t, (value) => {
    pendingConsentFile.value = value
  })
}

function onPendingIdentityChange(e: Event) {
  const t = e.target as HTMLInputElement
  assignPendingFile(t.files?.[0] ?? null, t, (value) => {
    pendingIdentityFile.value = value
  })
}

function validatePendingUploadFiles(): boolean {
  const pending = [
    pendingCertificateFile.value,
    pendingTranscriptFile.value,
    pendingConsentFile.value,
    pendingIdentityFile.value,
  ].filter((file): file is File => file instanceof File)

  const invalid = pending.find((file) => !isAllowedApplicantDocumentFile(file))
  if (!invalid) {
    return true
  }

  void Swal.fire({
    icon: 'error',
    title: 'Invalid file type',
    text: APPLICANT_DOCUMENT_FILE_ERROR,
  })
  return false
}

async function runPendingUploads(qid: number): Promise<void> {
  const base = `/applicant/applications/${props.application.id}`

  if (pendingCertificateFile.value) {
    await routerPostMultipart(`${base}/documents`, {
      document_type: 'certificate_copy',
      qualification_id: qid,
      file: pendingCertificateFile.value,
    })
  }

  if (pendingTranscriptFile.value) {
    await routerPostMultipart(`${base}/documents`, {
      document_type: 'transcript',
      qualification_id: qid,
      file: pendingTranscriptFile.value,
    })
  }

  if (isForeignAwarding.value && pendingConsentFile.value) {
    await routerPostMultipart(`${base}/consent/foreign-upload`, {
      qualification_id: qid,
      file: pendingConsentFile.value,
      source_awarding_institution_name: awardingDisplayName() || '',
    })
  }

  if (institutionalMode.value && pendingIdentityFile.value) {
    const docType = (form.holder_identity_type ?? 'nrc').toString() === 'passport' ? 'passport_copy' : 'nrc_copy'
    await routerPostMultipart(`${base}/documents`, {
      document_type: docType,
      qualification_id: qid,
      file: pendingIdentityFile.value,
    })
  }
}

function stripHolderFields(data: Record<string, unknown>) {
  if (institutionalMode.value) {
    return { ...data }
  }
  const o = { ...data }
  delete (o as any).qualification_holder_name
  delete (o as any).nrc_passport_number
  return o
}

function isPlaceholderText(value: unknown): boolean {
  const normalized = (value ?? '').toString().trim()
  if (!normalized) return true
  return ['—', '-', '–', 'N/A', 'n/a'].includes(normalized)
}

function sanitizeSubjectResultsForSubmit(
  rows: Array<{ certificate_subject_id: number | ''; grade: string }>,
) {
  return rows.filter(
    (row) => Number(row.certificate_subject_id) > 0 || (row.grade ?? '').toString().trim() !== '',
  )
}

function collectQualificationValidationErrors(): string[] {
  const errors: string[] = []
  applyIdentifierToForm()

  if (!form.country_id && !form.country_name_other?.toString().trim()) {
    errors.push('Select the country of award.')
  }
  if (!form.awarding_institution_id) {
    errors.push('Select an awarding institution or choose “Other”.')
  } else if (form.awarding_institution_id === 'other' && !form.awarding_institution_name_other?.toString().trim()) {
    errors.push('Enter the awarding institution name.')
  }
  if (!form.qualification_type_id) {
    errors.push('Select a qualification type.')
  }

  const title =
    titleChoice.value === 'other'
      ? form.applicant_entered_qualification_title?.toString().trim()
      : form.title_of_qualification?.toString().trim()
  if (!title) {
    errors.push('Select or enter a qualification title.')
  }

  if (isPlaceholderText(form.names_as_on_qualification_document)) {
    errors.push('Enter the names as they appear on the qualification document.')
  }
  if (!form.award_date) {
    errors.push('Enter the award date.')
  }

  const cert = form.certificate_number?.toString().trim()
  const stud = form.student_number?.toString().trim()
  const exam = form.examination_number?.toString().trim()
  if (!cert && !stud && !exam) {
    errors.push('Provide at least one of certificate number, student number, or examination number.')
  }

  if (needsSubjects.value) {
    const completeRows = form.subject_results.filter(
      (row) => Number(row.certificate_subject_id) > 0 && (row.grade ?? '').toString().trim() !== '',
    )
    if (completeRows.length < 1) {
      errors.push('Add at least one subject with a grade.')
    }

    form.subject_results.forEach((row, idx) => {
      const hasSubject = Number(row.certificate_subject_id) > 0
      const hasGrade = (row.grade ?? '').toString().trim() !== ''
      if ((hasSubject || hasGrade) && !(hasSubject && hasGrade)) {
        errors.push(`Complete subject and grade for row ${idx + 1}.`)
      }
    })
  }

  if (institutionalMode.value) {
    if (!form.holder_first_name?.toString().trim()) errors.push('Enter the qualification holder first name.')
    if (!form.holder_surname?.toString().trim()) errors.push('Enter the qualification holder surname.')
    if (!form.nrc_passport_number?.toString().trim()) errors.push('Enter the qualification holder NRC or passport number.')
    if (mode.value === 'add' && !pendingIdentityFile.value) {
      errors.push('Upload the qualification holder NRC or passport copy.')
    }
    if (mode.value === 'add' && !pendingCertificateFile.value) {
      errors.push('Upload the qualification certificate/document.')
    }
  }

  return errors
}

function prepareQualificationPayload(data: Record<string, any>) {
  const o: Record<string, any> = stripHolderFields({ ...data })
  o.awarding_institution_name = awardingDisplayName()
  if (Array.isArray(o.subject_results)) {
    o.subject_results = sanitizeSubjectResultsForSubmit(o.subject_results)
  }
  if (!needsSubjects.value) delete o.subject_results
  return o
}

async function promptAfterQualificationAdded(): Promise<'add_another' | 'back_to_step'> {
  const result = await Swal.fire({
    icon: 'success',
    title: 'Qualification added',
    text: 'What would you like to do next?',
    showDenyButton: true,
    confirmButtonText: 'Review qualifications',
    denyButtonText: 'Add another qualification',
    denyButtonColor: '#16a34a',
    reverseButtons: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
  })

  return result.isDenied ? 'add_another' : 'back_to_step'
}

async function handleAfterQualificationSavedSuccess(isAddFlow: boolean): Promise<void> {
  if (!isAddFlow) {
    router.visit(returnUrl.value)
    return
  }

  const next = await promptAfterQualificationAdded()
  if (next === 'add_another') {
    await initForm()
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return
  }

  router.visit(returnUrl.value)
}

function submitQualificationAndDocuments() {
  if (locked.value || savingAll.value || form.processing) return

  const validationErrors = collectQualificationValidationErrors()
  if (validationErrors.length > 0) {
    void Swal.fire({
      icon: 'warning',
      title: 'Complete required fields',
      html: `<ul class="mt-2 list-disc space-y-1 pl-5 text-left text-sm">${validationErrors.map((e) => `<li>${e}</li>`).join('')}</ul>`,
    })
    return
  }

  if (hasPendingUploads() && !validatePendingUploadFiles()) {
    return
  }

  applyIdentifierToForm()
  form.awarding_institution_name = awardingDisplayName()
  const isAddFlow = mode.value === 'add'

  const afterQualificationSaved = async (page: any) => {
    const qid = await resolveSavedQualificationId(page)

    if (qid > 0) {
      modalQualId.value = qid
    } else if (!isAddFlow) {
      await Swal.fire({
        icon: 'error',
        title: 'Could not resolve qualification',
        text: 'Save succeeded but the qualification id was missing. Please refresh and try again.',
      })
      return
    }

    if (!hasPendingUploads() || qid <= 0) {
      await handleAfterQualificationSavedSuccess(isAddFlow)
      return
    }

    savingAll.value = true
    try {
      await runPendingUploads(qid)
      await handleAfterQualificationSavedSuccess(isAddFlow)
    } catch {
      await Swal.fire({
        icon: 'error',
        title: 'Upload failed',
        text: 'Qualification was saved. Fix the file upload issue or try smaller files, then open this workspace again to add documents.',
      })
      await handleAfterQualificationSavedSuccess(isAddFlow)
    } finally {
      savingAll.value = false
    }
  }

  const visitOpts = {
    preserveScroll: true,
    only: ['application'],
    onSuccess: (page: any) => {
      void afterQualificationSaved(page)
    },
  }

  const basePath = institutionalMode.value
    ? `/applicant/applications/multiple/${props.application.id}`
    : `/applicant/applications/${props.application.id}`

  if (mode.value === 'add') {
    form
      .transform((data) => {
        const o = prepareQualificationPayload({ ...data })
        o.create_new = true
        return o
      })
      .post(`${basePath}/qualifications`, visitOpts)
  } else {
    form
      .transform((data) => prepareQualificationPayload({ ...data }))
      .put(`${basePath}/qualifications/${props.qualificationId}`, visitOpts)
  }
}

/** Foreign awarding: show consent file slot whenever country is foreign (same pass uploads after save for add mode). */
const showConsentUpload = computed(() => isForeignAwarding.value)
const canSubmitAll = computed(() => !locked.value && !form.processing && !savingAll.value)

/** Holder identity is captured on the Applicant step / new application — single source of truth. */
const verificationSubject = computed(() => {
  const vs = props.application?.metadata?.verification_subject
  return vs && typeof vs === 'object' ? (vs as Record<string, string>) : {}
})

const holderSummaryName = computed(() => {
  const n = (verificationSubject.value.full_name ?? '').toString().trim()
  return n || '—'
})

const holderSummaryId = computed(() => {
  const n = (verificationSubject.value.nrc_number ?? '').toString().trim()
  const p = (verificationSubject.value.passport_number ?? '').toString().trim()
  return n || p || '—'
})

const pendingCertificateName = computed(() => pendingCertificateFile.value?.name ?? '')
const pendingIdentityName = computed(() => pendingIdentityFile.value?.name ?? '')
const pendingTranscriptName = computed(() => pendingTranscriptFile.value?.name ?? '')
const pendingConsentName = computed(() => pendingConsentFile.value?.name ?? '')
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-text-muted">
            <Link :href="returnUrl" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-sm">
              Back
            </Link>
            <span class="hidden sm:inline">•</span>
            <span class="hidden sm:inline">{{ application.application_number }}</span>
          </div>
          <h1 class="mt-3 text-2xl font-semibold tracking-tight text-text-primary">
            {{ mode === 'add' ? 'Add qualification' : 'Edit qualification' }}
          </h1>
          <p class="mt-1 text-sm text-text-muted">Enter the details exactly as shown on your certificate.</p>
        </div>

        <Link :href="returnUrl" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          Cancel
        </Link>
      </div>
    </template>

    <div class="mx-auto w-full max-w-6xl">
      <div
        class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]"
      >
        <!-- Compact intro (kept light for mobile) -->
        <div class="border-b border-border bg-surface-muted/40 px-6 py-5 sm:px-8">
          <div class="flex items-start gap-3">
            <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-border bg-surface text-brand">
              <Sparkles class="h-5 w-5" aria-hidden="true" />
            </div>
            <div class="min-w-0">
              <div class="text-sm font-semibold text-text-primary">You are adding one qualification</div>
              <p class="mt-1 text-sm text-text-muted">
                Start with the awarding country and institution, then fill in the certificate details and upload your documents.
              </p>
            </div>
          </div>
        </div>

        <div class="min-h-0">
          <div v-if="editingQualification && (editingQualification.verification_state ?? '') === 'returned_to_applicant'" class="border-b border-amber-300/40 bg-amber-50 px-6 py-4 sm:px-8">
            <QualificationAmendmentBanner :application-id="application.id" :qualification="editingQualification" compact />
          </div>
          <div class="space-y-8 px-6 py-6 sm:px-8 sm:py-8">
          <!-- Qualification holder (institutional multiple) -->
          <section
            v-if="institutionalMode"
            class="rounded-2xl border border-border bg-surface-muted/40 p-5 ring-1 ring-black/[0.03]"
          >
            <div class="flex items-center gap-2 text-text-primary">
              <UserRound class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
              <h3 class="text-base font-semibold">Qualification holder</h3>
            </div>
            <p class="mt-1 text-sm text-text-muted">Enter the identity details for this qualification record.</p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="text-sm font-medium text-text-primary">First name *</label>
                <input v-model="form.holder_first_name" type="text" class="zaqa-input" :disabled="locked" />
                <InputError :message="form.errors.holder_first_name" />
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">Middle name</label>
                <input v-model="form.holder_middle_name" type="text" class="zaqa-input" :disabled="locked" />
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">Surname *</label>
                <input v-model="form.holder_surname" type="text" class="zaqa-input" :disabled="locked" />
                <InputError :message="form.errors.holder_surname" />
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">NRC / Passport number *</label>
                <input v-model="form.nrc_passport_number" type="text" class="zaqa-input" :disabled="locked" />
                <InputError :message="form.errors.nrc_passport_number" />
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">Identity document type</label>
                <select v-model="form.holder_identity_type" class="zaqa-input" :disabled="locked">
                  <option value="nrc">NRC</option>
                  <option value="passport">Passport</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">Date of birth</label>
                <input v-model="form.holder_date_of_birth" type="date" class="zaqa-input" :disabled="locked" />
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">Gender</label>
                <select v-model="form.holder_gender" class="zaqa-input" :disabled="locked">
                  <option value="">Select</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-medium text-text-primary">Phone</label>
                <input v-model="form.holder_phone" type="text" class="zaqa-input" :disabled="locked" />
              </div>
              <div class="sm:col-span-2">
                <label class="text-sm font-medium text-text-primary">Email</label>
                <input v-model="form.holder_email" type="email" class="zaqa-input" :disabled="locked" />
              </div>
            </div>
          </section>
          <!-- Location -->
          <section class="rounded-2xl border border-border bg-surface-muted/40 p-5 ring-1 ring-black/[0.03]">
            <div class="flex items-center gap-2 text-text-primary">
              <MapPin class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
              <h3 class="text-base font-semibold">Where did you study?</h3>
            </div>
            <p class="mt-1 text-sm text-text-muted">
              Select the country and institution that issued this qualification.
            </p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <label class="text-sm font-medium text-text-primary">Country of award</label>
                <select v-model="form.country_id" class="zaqa-input" :disabled="locked">
                  <option value="">Select country</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <InputError :message="form.errors.country_id" />
                </div>
                <div class="sm:col-span-2">
                  <InstitutionCombobox
                    :country-id="form.country_id"
                    v-model="form.awarding_institution_id"
                    label="Awarding institution"
                    query-endpoint="/applicant/reference/awarding-institutions"
                    :error="form.errors.awarding_institution_id"
                    :disabled="locked"
                    :suppress-dependency-reset="isHydratingForm"
                    @selected="onInstitutionSelected"
                  />
                </div>
                <div v-if="form.awarding_institution_id === 'other'" class="sm:col-span-2">
                  <label class="text-sm font-medium">Institution name</label>
                  <input
                    v-model="form.awarding_institution_name_other"
                    class="zaqa-input"
                    :disabled="locked"
                    placeholder="Type the official institution name"
                  />
                  <InputError :message="form.errors.awarding_institution_name_other" />
                </div>
              </div>
            </section>

          <!-- Qualification -->
          <section class="rounded-2xl border border-border bg-surface p-5 ring-1 ring-black/[0.03]">
            <div class="flex items-center gap-2 text-text-primary">
              <GraduationCap class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
              <h3 class="text-base font-semibold">Qualification information</h3>
            </div>
            <p class="mt-1 text-sm text-text-muted">Use the details shown on your certificate.</p>

              <div
                v-if="mode === 'edit'"
                class="sm:col-span-2 rounded-2xl border border-border bg-surface-muted/60 px-4 py-4 text-sm text-text-primary"
              >
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Qualification holder</div>
                <p class="mt-1 text-xs leading-relaxed text-text-muted">
                  Name and NRC / passport come from your Applicant step (or the new-application form). They apply to every qualification in this application.
                </p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                  <div>
                    <div class="text-xs font-medium text-text-muted">Full name</div>
                    <div class="mt-0.5 font-semibold">{{ holderSummaryName }}</div>
                  </div>
                  <div>
                    <div class="text-xs font-medium text-text-muted">NRC / Passport</div>
                    <div class="mt-0.5 font-mono text-xs font-semibold">{{ holderSummaryId }}</div>
                  </div>
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <label class="text-sm font-medium">Qualification type</label>
                  <select v-model="form.qualification_type_id" class="zaqa-input" :disabled="locked">
                    <option value="" disabled>Select type…</option>
                    <option v-for="t in qualificationTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                  </select>
                  <InputError :message="form.errors.qualification_type_id" />
                  <div v-if="locked" class="mt-2 rounded-lg border border-warning/25 bg-warning/10 px-3 py-2 text-xs text-warning">
                    Qualification details cannot be changed after payment has been confirmed for this application.
                  </div>
                  <div
                    v-else-if="application?.invoice"
                    class="mt-2 rounded-lg border border-border bg-surface-muted px-3 py-2 text-xs text-text-muted"
                  >
                    An invoice exists—the total will update automatically when you save changes, until payment is completed.
                  </div>
                </div>
                <div class="sm:col-span-2">
                  <QualificationTitleCombobox
                    v-model="titleChoice"
                    :awarding-institution-id="form.awarding_institution_id && form.awarding_institution_id !== 'other' ? form.awarding_institution_id : null"
                    :qualification-type-id="form.qualification_type_id || null"
                    :selected-title="form.title_of_qualification"
                    query-endpoint="/applicant/reference/qualification-titles"
                    :disabled="locked || !form.awarding_institution_id"
                    :suppress-dependency-reset="isHydratingForm"
                    :error="form.errors.qualification_title_id || form.errors.title_of_qualification"
                    label="Qualification title"
                    @selected="(opt) => {
                      if (opt.id !== 'other') {
                        form.title_of_qualification = opt.title
                        form.qualification_title_id = opt.id
                      }
                    }"
                  />
                  <div v-if="titleChoice === 'other'" class="mt-3">
                    <label class="text-sm font-medium">Qualification title (other)</label>
                    <input v-model="form.applicant_entered_qualification_title" class="zaqa-input" :disabled="locked" />
                    <InputError :message="form.errors.applicant_entered_qualification_title" />
                    <div class="mt-1 text-xs text-text-muted">This title is for your application only and will be verified by ZAQA.</div>
                  </div>
                </div>
                <div class="sm:col-span-2">
                  <label class="text-sm font-medium text-text-primary">
                    Name of Qualification Holder as it appears on qualification document
                    <span class="text-danger" aria-hidden="true">*</span>
                  </label>
                  <input
                    v-model="form.names_as_on_qualification_document"
                    class="zaqa-input ring-1 ring-brand/15 focus:ring-brand/30"
                    :disabled="locked"
                    placeholder="Enter the names exactly as printed on the certificate or transcript"
                    autocomplete="off"
                  />
                  <InputError :message="form.errors.names_as_on_qualification_document" />
                  <p class="mt-2 text-xs leading-relaxed text-text-muted">
                    This may differ from the qualification holder name captured earlier. Use the spelling, initials, and order shown on the document.
                  </p>
                </div>
                <div>
                  <label class="text-sm font-medium">Reference type</label>
                  <select v-model="identifierType" class="zaqa-input" :disabled="locked">
                    <option value="certificate_number">Certificate number</option>
                    <option value="student_number">Student number</option>
                    <option value="examination_number">Examination number</option>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-medium">Reference number</label>
                  <input v-model="identifierValue" class="zaqa-input" :disabled="locked" />
                  <InputError :message="form.errors.certificate_number" />
                </div>
                <div>
                  <label class="text-sm font-medium">Award date</label>
                  <input v-model="form.award_date" type="date" class="zaqa-input" :disabled="locked" />
                  <InputError :message="form.errors.award_date" />
                </div>
              </div>

              <div v-if="needsSubjects" class="mt-6 border-t border-border pt-6">
                <div class="text-sm font-semibold text-text-primary">Subject results</div>
                <p v-if="certificateSubjects.length === 0" class="mt-3 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-text-primary">
                  No subjects are configured yet. An administrator must add subjects under Admin → System settings → Subjects before you can complete this section.
                </p>
                <div class="mt-3 space-y-3">
                  <div v-for="(row, idx) in form.subject_results" :key="idx" class="grid grid-cols-1 gap-3 sm:grid-cols-7">
                    <div class="sm:col-span-4">
                      <label class="text-xs font-medium">Subject</label>
                      <select v-model.number="row.certificate_subject_id" class="zaqa-input" :disabled="locked || certificateSubjects.length === 0">
                        <option :value="''">Select subject</option>
                        <option v-for="s in certificateSubjects" :key="s.id" :value="s.id">{{ s.name }}</option>
                      </select>
                    </div>
                    <div class="sm:col-span-2">
                      <label class="text-xs font-medium">Grade</label>
                      <SubjectGradeSelect
                        v-model="row.grade"
                        :saved-grade="row.saved_grade"
                        :grade-options="subjectGradeOptions"
                        :disabled="locked"
                      />
                    </div>
                    <div class="sm:col-span-1 flex items-end">
                      <button type="button" class="zaqa-btn zaqa-btn-ghost w-full text-xs" :disabled="locked" @click="removeSubjectRow(idx)">
                        Remove
                      </button>
                    </div>
                  </div>
                </div>
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-secondary mt-3 px-3 py-2 text-xs"
                  :disabled="locked"
                  @click="addSubjectRow"
                >
                  Add subject
                </button>
                <InputError :message="form.errors.subject_results" class="mt-2" />
              </div>
            </section>

          <!-- Documents (staged — uploaded together with qualification via footer Save) -->
          <section class="rounded-2xl border border-border bg-surface p-5 ring-1 ring-black/[0.03]">
            <div class="flex items-center gap-2 text-text-primary">
              <FileStack class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
              <h3 class="text-base font-semibold">Upload documents</h3>
            </div>
            <p class="mt-1 text-sm text-text-muted">Upload your certificate or supporting document.</p>

              <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <label class="text-sm font-medium">Certificate or qualification document</label>
                  <div
                    v-if="existingDocument('certificate_copy')"
                    class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-success/25 bg-success/5 px-3 py-2"
                  >
                    <p class="min-w-0 text-xs text-text-primary">
                      On file:
                      <span class="font-semibold">{{ existingDocument('certificate_copy')?.original_name || 'Certificate' }}</span>
                    </p>
                    <button
                      type="button"
                      class="zaqa-btn shrink-0 border border-danger/20 bg-danger/10 px-2.5 py-2 text-danger hover:bg-danger/15"
                      :disabled="locked || deletingDocumentId === existingDocument('certificate_copy')?.id"
                      :title="locked ? 'Documents cannot be changed after payment' : 'Remove uploaded certificate'"
                      @click="confirmDeleteDocument(existingDocument('certificate_copy'))"
                    >
                      <Trash2 class="h-4 w-4" aria-hidden="true" />
                      <span class="sr-only">Remove certificate</span>
                    </button>
                  </div>
                  <input
                    ref="certificateFileInputEl"
                    type="file"
                    class="zaqa-input mt-2"
                    :accept="APPLICANT_DOCUMENT_ACCEPT"
                    :disabled="locked"
                    @change="onPendingCertificateChange"
                  />
                  <p class="mt-1 text-xs text-text-muted">{{ pdfOrImageHint }}</p>
                  <p v-if="pendingCertificateName" class="mt-2 text-xs text-text-muted">
                    Selected: <span class="font-semibold text-text-primary">{{ pendingCertificateName }}</span>
                  </p>
                </div>
                <div v-if="institutionalMode" class="sm:col-span-2">
                  <label class="text-sm font-medium">NRC or passport copy *</label>
                  <div
                    v-if="existingDocument('nrc_copy') || existingDocument('passport_copy')"
                    class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-success/25 bg-success/5 px-3 py-2"
                  >
                    <p class="min-w-0 text-xs text-text-primary">
                      On file:
                      <span class="font-semibold">{{
                        existingDocument('nrc_copy')?.original_name ||
                        existingDocument('passport_copy')?.original_name ||
                        'Identity document'
                      }}</span>
                    </p>
                    <button
                      type="button"
                      class="zaqa-btn shrink-0 border border-danger/20 bg-danger/10 px-2.5 py-2 text-danger hover:bg-danger/15"
                      :disabled="locked"
                      @click="confirmDeleteDocument(existingDocument('nrc_copy') || existingDocument('passport_copy'))"
                    >
                      <Trash2 class="h-4 w-4" aria-hidden="true" />
                    </button>
                  </div>
                  <input
                    ref="identityFileInputEl"
                    type="file"
                    class="zaqa-input mt-2"
                    :accept="APPLICANT_DOCUMENT_ACCEPT"
                    :disabled="locked"
                    @change="onPendingIdentityChange"
                  />
                  <p v-if="pendingIdentityName" class="mt-2 text-xs text-text-muted">
                    Selected: <span class="font-semibold text-text-primary">{{ pendingIdentityName }}</span>
                  </p>
                </div>
                <div v-if="transcriptRequiredForDocs" class="sm:col-span-2">
                  <label class="text-sm font-medium">Transcript (optional)</label>
                  <p class="mt-1 text-xs text-text-muted">Optional. Upload a transcript if you have one.</p>
                  <div
                    v-if="existingDocument('transcript')"
                    class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-success/25 bg-success/5 px-3 py-2"
                  >
                    <p class="min-w-0 text-xs text-text-primary">
                      On file:
                      <span class="font-semibold">{{ existingDocument('transcript')?.original_name || 'Transcript' }}</span>
                    </p>
                    <button
                      type="button"
                      class="zaqa-btn shrink-0 border border-danger/20 bg-danger/10 px-2.5 py-2 text-danger hover:bg-danger/15"
                      :disabled="locked || deletingDocumentId === existingDocument('transcript')?.id"
                      :title="locked ? 'Documents cannot be changed after payment' : 'Remove uploaded transcript'"
                      @click="confirmDeleteDocument(existingDocument('transcript'))"
                    >
                      <Trash2 class="h-4 w-4" aria-hidden="true" />
                      <span class="sr-only">Remove transcript</span>
                    </button>
                  </div>
                  <input
                    ref="transcriptFileInputEl"
                    type="file"
                    class="zaqa-input mt-2"
                    :accept="APPLICANT_DOCUMENT_ACCEPT"
                    :disabled="locked"
                    @change="onPendingTranscriptChange"
                  />
                  <p class="mt-1 text-xs text-text-muted">{{ pdfOrImageHint }}</p>
                  <p v-if="pendingTranscriptName" class="mt-2 text-xs text-text-muted">
                    Selected: <span class="font-semibold text-text-primary">{{ pendingTranscriptName }}</span>
                  </p>
                </div>
              </div>
            </section>

            <!-- Institution consent (foreign awarding only) -->
            <section v-if="showConsentUpload" class="rounded-2xl border border-brand/20 bg-brand/[0.04] p-5 ring-1 ring-brand/15">
              <div class="flex items-start gap-3">
                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand/15 text-brand">
                  <Shield class="h-5 w-5" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                  <h3 class="text-base font-semibold text-text-primary">Institution consent required</h3>
                  <p class="mt-1 text-sm text-text-muted">
                    Download the form, sign it, then upload the signed copy.
                  </p>
                </div>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-3">
                <a
                  v-if="institutionConsentUrl"
                  :href="institutionConsentUrl"
                  target="_blank"
                  rel="noopener"
                  class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 text-sm"
                >
                  <Building2 class="h-4 w-4" aria-hidden="true" />
                  Download consent form
                </a>
                <p v-else class="text-xs leading-relaxed text-text-muted">
                  No template for this institution, you can still upload a signed consent you obtained from them.
                </p>
              </div>

              <div class="mt-5 grid grid-cols-1 gap-3 sm:max-w-lg">
                <div>
                  <label class="text-sm font-medium">Upload signed consent</label>
                  <div
                    v-if="existingDocument('consent_form_signed')"
                    class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-success/25 bg-success/5 px-3 py-2"
                  >
                    <p class="min-w-0 text-xs text-text-primary">
                      On file:
                      <span class="font-semibold">{{ existingDocument('consent_form_signed')?.original_name || 'Signed consent' }}</span>
                    </p>
                    <button
                      type="button"
                      class="zaqa-btn shrink-0 border border-danger/20 bg-danger/10 px-2.5 py-2 text-danger hover:bg-danger/15"
                      :disabled="locked || deletingDocumentId === existingDocument('consent_form_signed')?.id"
                      :title="locked ? 'Documents cannot be changed after payment' : 'Remove uploaded consent'"
                      @click="confirmDeleteDocument(existingDocument('consent_form_signed'))"
                    >
                      <Trash2 class="h-4 w-4" aria-hidden="true" />
                      <span class="sr-only">Remove consent</span>
                    </button>
                  </div>
                  <input
                    ref="consentFileInputEl"
                    type="file"
                    :accept="APPLICANT_DOCUMENT_ACCEPT"
                    class="zaqa-input mt-2"
                    :disabled="locked"
                    @change="onPendingConsentChange"
                  />
                  <p class="mt-1 text-xs text-text-muted">{{ pdfOrImageHint }}</p>
                  <p v-if="pendingConsentName" class="mt-2 text-xs text-text-muted">
                    Selected: <span class="font-semibold text-text-primary">{{ pendingConsentName }}</span>
                  </p>
                </div>
              </div>
            </section>
          </div>
        </div>

        <div class="shrink-0 border-t border-border bg-surface-muted/60 px-6 py-4 sm:px-8">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-xl text-xs text-text-muted">Your qualification is saved when you click Save qualification.</p>
            <div class="flex flex-wrap gap-2">
              <Link :href="returnUrl" class="zaqa-btn zaqa-btn-secondary">Cancel</Link>
              <button
                type="button"
                class="zaqa-btn zaqa-btn-primary px-6"
                :disabled="!canSubmitAll"
                @click="submitQualificationAndDocuments"
              >
                {{ savingAll ? 'Uploading…' : form.processing ? 'Saving…' : 'Save qualification' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
