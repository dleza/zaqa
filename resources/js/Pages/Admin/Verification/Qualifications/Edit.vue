<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import Level2DecisionLevel1Fields from '@/Components/Admin/Verification/Level2DecisionLevel1Fields.vue'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import InputError from '@/Components/InputError.vue'
import SubjectGradeSelect from '@/Components/SubjectGradeSelect.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { selectGradeValue } from '@/lib/certificateSubjectGrades'
import { resolveCertificateSubjectId } from '@/lib/resolveCertificateSubjectId'
import { ArrowLeft, Download, Eye, FileEdit, FileText, GraduationCap, History, MapPin, RefreshCw, Trash2 } from 'lucide-vue-next'
import Swal from 'sweetalert2'

type DocumentRow = {
  id: number
  document_type: string
  original_name: string
  version_number: number
  qualification_id: number | null
  preview_url: string
  download_url: string
  can_delete: boolean
  uploaded_by?: string | null
}

type DocumentSlot = {
  document_type: string
  label: string
  document: DocumentRow | null
}

type CorrectionEntry = {
  id: number
  event_type: string
  at: string | null
  actor_name: string | null
  note: string | null
  summary: string
  field_changes: Array<{ field: string; label: string; from: string; to: string }>
  document_before?: string | null
  document_after?: string | null
}

const props = defineProps<{
  qualification: Record<string, any>
  application: { id: number; application_number: string | null; payment_satisfied?: boolean }
  viewerUserId?: number | null
  can?: {
    level1_process?: boolean
    level2_review?: boolean
    approve?: boolean
    reject?: boolean
    issue_certificate?: boolean
    is_super_admin?: boolean
  }
  countries: Array<{ id: number; name: string; iso_code?: string | null }>
  qualificationTypes: Array<{
    id: number
    name: string
    zqf_level_code: string
    level_label: string
    requires_subject_results: boolean
  }>
  certificateSubjects: Array<{ id: number; name: string }>
  subjectGradeOptions?: string[]
  documents: DocumentRow[]
  expected_document_types: string[]
  identity_document: {
    source: 'application' | 'profile'
    document_type: string
    original_name: string | null
    preview_url: string
    download_url: string
    document_id: number | null
    can_delete: boolean
    delete_url?: string | null
  } | null
  correction_history: CorrectionEntry[]
}>()

const approveOpen = ref(false)
const rejectOpen = ref(false)
const correctionHistoryOpen = ref(false)
const formRoot = ref<HTMLElement | null>(null)
const approveForm = useForm<{
  comment: string
  issue_certificate: boolean
  findings: string
  accreditation_statement: string
}>({
  comment: '',
  issue_certificate: true,
  findings: '',
  accreditation_statement: '',
})
const rejectForm = useForm<{
  reason: string
  generate_rejection_notice: boolean
  findings: string
  accreditation_statement: string
}>({
  reason: '',
  generate_rejection_notice: true,
  findings: '',
  accreditation_statement: '',
})

const state = computed(() => (props.qualification.verification_state ?? '').toString())
const level2Lock = computed(() => props.qualification.level2_review_lock ?? {})
const level1Review = computed(() => props.qualification.level1_review ?? null)
const isAutoVerifiedPendingL2 = computed(() => state.value === 'auto_verified_pending_level2')
const isLevel2Viewer = computed(() => props.can?.level2_review === true)
const isSuperAdmin = computed(() => props.can?.is_super_admin === true)
const lockIsActive = computed(() => !!level2Lock.value?.is_locked)
const viewerHasLock = computed(() => {
  if (!props.viewerUserId) return false
  return lockIsActive.value && Number(level2Lock.value?.locked_by_user_id ?? 0) === Number(props.viewerUserId)
})
const lockMissingForActions = computed(() => isAutoVerifiedPendingL2.value && !viewerHasLock.value && !isSuperAdmin.value)
const canShowApprove = computed(
  () => props.can?.approve === true && ['under_level2_review', 'auto_verified_pending_level2'].includes(state.value),
)
const canShowReject = computed(
  () => props.can?.reject === true && ['under_level2_review', 'auto_verified_pending_level2'].includes(state.value),
)
const showLevel2DecisionActions = computed(() => canShowApprove.value || canShowReject.value)
const level2DecisionBlockedByDirtyForm = computed(() => form.isDirty)
const level1Findings = computed(() => (level1Review.value?.findings ?? props.qualification.reviewer_notes ?? '').toString().trim())

function prefillLevel2DecisionLevel1Fields(target: { findings: string; accreditation_statement: string }) {
  const review = level1Review.value
  target.findings = (review?.findings ?? props.qualification.reviewer_notes ?? '').toString()
  const qualAccreditation = (review?.accreditation_statement ?? '').toString().trim()
  target.accreditation_statement = qualAccreditation !== ''
    ? qualAccreditation
    : (props.qualification.awarding_institution_accreditation_statement ?? '').toString()
}

function level2AccreditationInstitutionDefaulted(form: { accreditation_statement: string }) {
  const qualAccreditation = (level1Review.value?.accreditation_statement ?? '').toString().trim()
  return qualAccreditation === '' && (props.qualification.awarding_institution_accreditation_statement ?? '').toString().trim() !== '' && form.accreditation_statement.trim() !== ''
}

function level2AccreditationInstitutionMissing() {
  const qualAccreditation = (level1Review.value?.accreditation_statement ?? '').toString().trim()
  if (qualAccreditation !== '') return false
  if ((props.qualification.awarding_institution_accreditation_statement ?? '').toString().trim() !== '') return false
  return !!props.qualification.awarding_institution_id && props.qualification.awarding_institution_id !== 'other'
}

function openApproveModal() {
  approveForm.clearErrors()
  prefillLevel2DecisionLevel1Fields(approveForm)
  approveOpen.value = true
}

function openRejectModal() {
  rejectForm.clearErrors()
  prefillLevel2DecisionLevel1Fields(rejectForm)
  if (level1Review.value?.recommended_for_award === false && level1Findings.value !== '') {
    rejectForm.reason = level1Findings.value
  } else {
    rejectForm.reason = ''
  }
  rejectOpen.value = true
}

function resetApproveForm() {
  approveForm.clearErrors()
  approveForm.reset()
  approveForm.issue_certificate = true
}

function resetRejectForm() {
  rejectForm.clearErrors()
  rejectForm.reset()
  rejectForm.generate_rejection_notice = true
}

const institutionMeta = ref<{ name: string } | null>(null)
if (props.qualification.awarding_institution_id && props.qualification.awarding_institution_id !== 'other') {
  institutionMeta.value = { name: (props.qualification.awarding_institution_name ?? '').toString() }
}

function onInstitutionSelected(opt: { id: number | 'other'; name: string }) {
  institutionMeta.value = opt.id === 'other' ? { name: '' } : { name: opt.name }
}

const CORRECTION_NOTE_MAX_LENGTH = 2000

type IdentifierType = 'certificate_number' | 'student_number' | 'examination_number'
const identifierType = ref<IdentifierType>('certificate_number')
const identifierValue = ref('')

const form = useForm({
  qualification_holder_name: props.qualification.qualification_holder_name ?? '',
  names_as_on_qualification_document: props.qualification.names_as_on_qualification_document ?? '',
  nrc_passport_number: props.qualification.nrc_passport_number ?? '',
  country_id: props.qualification.country_id ?? ('' as number | string | ''),
  country_name_other: props.qualification.country_name_other ?? '',
  awarding_institution_id: props.qualification.awarding_institution_id ?? ('' as number | string | ''),
  awarding_institution_name_other: props.qualification.awarding_institution_name_other ?? '',
  awarding_institution_name: props.qualification.awarding_institution_name ?? '',
  certificate_number: props.qualification.certificate_number ?? '',
  student_number: props.qualification.student_number ?? '',
  examination_number: props.qualification.examination_number ?? '',
  title_of_qualification: props.qualification.title_of_qualification ?? '',
  award_date: props.qualification.award_date ?? '',
  qualification_type_id: props.qualification.qualification_type_id ?? ('' as number | string | ''),
  correction_note: '',
  subject_results: (props.qualification.subject_results ?? []).map((r: any) => ({
    certificate_subject_id: resolveCertificateSubjectId(r, props.certificateSubjects ?? []),
    subject_name: r.subject_name ?? '',
    grade: selectGradeValue(r.grade),
    saved_grade: r.grade ?? '',
  })),
})

const correctionNoteLength = computed(() => form.correction_note.length)

const uploadModalOpen = ref(false)
const uploadTargetType = ref('')
const uploadTargetLabel = ref('')
const uploadModalFileInput = ref<HTMLInputElement | null>(null)

const documentUploadForm = useForm<{
  document_type: string
  file: File | null
  correction_note: string
}>({
  document_type: '',
  file: null,
  correction_note: '',
})

const deletingDocumentId = ref<number | null>(null)

const documentTypeLabels: Record<string, string> = {
  nrc_copy: 'NRC copy',
  passport_copy: 'Passport copy',
  certificate_copy: 'Certificate',
  transcript: 'Transcript',
  consent_form_signed: 'Institution consent',
  zaqa_consent_form_signed: 'ZAQA consent',
  other_supporting_document: 'Other supporting document',
}

function documentTypeLabel(raw: string) {
  return documentTypeLabels[raw] ?? raw.replace(/_/g, ' ')
}

const documentsByType = computed(() => {
  const map = new Map<string, DocumentRow>()
  for (const doc of props.documents ?? []) {
    map.set(doc.document_type, doc)
  }
  return map
})

const documentSlots = computed<DocumentSlot[]>(() =>
  (props.expected_document_types ?? []).map((documentType) => ({
    document_type: documentType,
    label: documentTypeLabel(documentType),
    document: documentsByType.value.get(documentType) ?? null,
  })),
)

const identityDocumentLabel = computed(() => {
  if (!props.identity_document) return 'Identity document'
  return documentTypeLabel(props.identity_document.document_type)
})

function formatAt(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString()
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

syncIdentifierFromForm()

function awardingDisplayName(): string {
  if (form.awarding_institution_id === 'other') {
    return (form.awarding_institution_name_other ?? '').toString().trim()
  }
  return (
    (institutionMeta.value?.name ?? '').toString().trim() || (form.awarding_institution_name ?? '').toString().trim()
  )
}

const selectedQualificationType = computed(() => {
  const id = Number(form.qualification_type_id || 0)
  return props.qualificationTypes.find((t) => Number(t.id) === id) ?? null
})

const needsSubjects = computed(() => !!selectedQualificationType.value?.requires_subject_results)
const subjectGradeOptions = computed(() => props.subjectGradeOptions ?? [])

watch(needsSubjects, (need) => {
  if (need && form.subject_results.length === 0) {
    form.subject_results.push({ certificate_subject_id: '', subject_name: '', grade: '', saved_grade: '' })
  }
})

function addSubjectRow() {
  form.subject_results.push({ certificate_subject_id: '', subject_name: '', grade: '', saved_grade: '' })
}

function removeSubjectRow(idx: number) {
  form.subject_results.splice(idx, 1)
}

function submit() {
  if (form.processing) return
  if (!form.isDirty) return
  applyIdentifierToForm()
  form.awarding_institution_name = awardingDisplayName() || '—'

  form
    .transform((data) => {
      const o = { ...data } as Record<string, unknown>
      if (!needsSubjects.value) {
        o.subject_results = []
      }
      return o
    })
    .put(`/admin/verification/qualifications/${props.qualification.id}`, {
      preserveScroll: true,
      onError: () => focusFirstFormError(),
    })
}

const formErrorFieldOrder = [
  'correction_note',
  'names_as_on_qualification_document',
  'qualification_holder_name',
  'nrc_passport_number',
  'country_id',
  'awarding_institution_id',
  'awarding_institution_name_other',
  'qualification_type_id',
  'title_of_qualification',
  'certificate_number',
  'student_number',
  'examination_number',
  'award_date',
] as const

function resolveFirstErrorFieldKey(errors: Record<string, string>): string | null {
  const keys = Object.keys(errors).filter((key) => errors[key])
  if (keys.length === 0) return null

  for (const field of formErrorFieldOrder) {
    if (keys.includes(field)) return field
  }
  if (keys.some((key) => key.startsWith('subject_results'))) return 'subject_results'

  return keys[0]
}

function focusFirstFormError() {
  nextTick(() => {
    const errors = form.errors as Record<string, string>
    let fieldKey = resolveFirstErrorFieldKey(errors)
    if (!fieldKey) return

    if (fieldKey === 'certificate_number' || fieldKey === 'student_number' || fieldKey === 'examination_number') {
      fieldKey = 'identifier'
    }

    const el = formRoot.value?.querySelector(`[data-field="${fieldKey}"]`) as HTMLElement | null
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' })
      const focusable = (
        el.matches('input,select,textarea')
          ? el
          : el.querySelector('input,select,textarea')
      ) as HTMLElement | null
      focusable?.focus({ preventScroll: true })
      return
    }

    formRoot.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

onMounted(() => {
  if (resolveFirstErrorFieldKey(form.errors as Record<string, string>)) {
    focusFirstFormError()
  }
})

function openUploadModal(documentType: string, label?: string) {
  uploadTargetType.value = documentType
  uploadTargetLabel.value = label ?? documentTypeLabel(documentType)
  documentUploadForm.document_type = documentType
  documentUploadForm.file = null
  documentUploadForm.correction_note = ''
  if (uploadModalFileInput.value) uploadModalFileInput.value.value = ''
  uploadModalOpen.value = true
}

function openIdentityUploadModal() {
  const type = props.identity_document?.document_type === 'passport_copy' ? 'passport_copy' : 'nrc_copy'
  openUploadModal(type, identityDocumentLabel.value)
}

function onUploadModalFileChange(e: Event) {
  const t = e.target as HTMLInputElement
  documentUploadForm.file = t.files?.[0] ?? null
}

function closeUploadModal() {
  uploadModalOpen.value = false
}

function submitDocumentUpload() {
  if (documentUploadForm.processing || !documentUploadForm.file) return
  documentUploadForm.post(`/admin/verification/qualifications/${props.qualification.id}/documents`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      closeUploadModal()
      documentUploadForm.reset('file', 'correction_note')
    },
  })
}

async function confirmDeleteDocument(doc: DocumentRow) {
  const result = await Swal.fire({
    icon: 'warning',
    title: 'Remove document?',
    text: 'This file will be removed from the application record. The action is logged.',
    input: 'textarea',
    inputLabel: 'Note (optional)',
    inputPlaceholder: 'Reason for removing this document…',
    showCancelButton: true,
    confirmButtonText: 'Remove',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#B42318',
  })
  if (!result.isConfirmed) return

  deletingDocumentId.value = doc.id
  router.delete(`/admin/verification/qualifications/${props.qualification.id}/documents/${doc.id}`, {
    preserveScroll: true,
    data: { correction_note: (result.value as string) || null },
    onFinish: () => {
      deletingDocumentId.value = null
    },
  })
}

function identityDocumentRow(): DocumentRow | null {
  if (!props.identity_document?.document_id) return null
  return {
    id: props.identity_document.document_id,
    document_type: props.identity_document.document_type,
    original_name: props.identity_document.original_name ?? '',
    version_number: 1,
    qualification_id: null,
    preview_url: props.identity_document.preview_url,
    download_url: props.identity_document.download_url,
    can_delete: props.identity_document.can_delete,
  }
}
</script>

<template>
  <AdminLayout>
    <div class="w-full min-w-0 -mx-4 py-8 sm:-mx-6 lg:-mx-8">
      <div class="w-full max-w-none space-y-8 px-3 sm:px-4 lg:px-5">
        <div>
          <Link
            :href="`/admin/verification/qualifications/${qualification.id}`"
            class="inline-flex items-center gap-2 text-sm font-semibold text-text-muted transition hover:text-text-primary"
          >
            <ArrowLeft class="h-4 w-4" aria-hidden="true" />
            Back to qualification task
          </Link>
          <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
              <span class="mt-0.5 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
                <FileEdit class="h-5 w-5" aria-hidden="true" />
              </span>
              <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-text-primary">Edit qualification details</h1>
                <p class="mt-1 max-w-3xl text-sm text-text-muted">
                  Application {{ application.application_number ?? '—' }} — correct factual details and supporting files.
                  Changes are audited and do not alter verification workflow state.
                </p>
              </div>
            </div>

            <div class="flex w-full shrink-0 flex-col gap-3 lg:w-auto lg:items-end">
              <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <button
                  type="button"
                  class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
                  @click="correctionHistoryOpen = true"
                >
                  <History class="h-4 w-4" aria-hidden="true" />
                  View history
                  <span
                    v-if="correction_history.length"
                    class="rounded-full bg-brand/15 px-2 py-0.5 text-xs font-semibold text-brand"
                  >
                    {{ correction_history.length }}
                  </span>
                </button>
                <template v-if="showLevel2DecisionActions">
                  <button
                    v-if="canShowApprove"
                    type="button"
                    class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
                    :disabled="level2DecisionBlockedByDirtyForm || (isAutoVerifiedPendingL2 && lockMissingForActions)"
                    :title="level2DecisionBlockedByDirtyForm ? 'Save qualification changes before approving.' : isAutoVerifiedPendingL2 && lockMissingForActions ? 'Lock for review before approving.' : ''"
                    @click="openApproveModal"
                  >
                    Approve Verification Certificate
                  </button>
                  <button
                    v-if="canShowReject"
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary border-danger/30 px-4 py-2 text-sm text-danger"
                    :disabled="level2DecisionBlockedByDirtyForm || (isAutoVerifiedPendingL2 && lockMissingForActions)"
                    :title="level2DecisionBlockedByDirtyForm ? 'Save qualification changes before rejecting.' : isAutoVerifiedPendingL2 && lockMissingForActions ? 'Lock for review before rejecting.' : ''"
                    @click="openRejectModal"
                  >
                    Issue Notice of Rejection
                  </button>
                </template>
              </div>
              <p
                v-if="showLevel2DecisionActions && level2DecisionBlockedByDirtyForm"
                class="text-xs text-text-muted lg:text-right"
              >
                Save changes before taking a Level 2 decision.
              </p>
            </div>
          </div>
        </div>

        <form ref="formRoot" class="space-y-8" @submit.prevent="submit">
          <div class="grid gap-8 xl:grid-cols-2 xl:items-start">
            <div class="space-y-8">
              <section class="rounded-2xl border border-border bg-surface-muted/40 p-6 shadow-sm sm:p-7">
                <div class="flex items-center gap-2 text-text-primary">
                  <MapPin class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h2 class="text-base font-semibold">Award location & institution</h2>
                </div>
                <p class="mt-1 text-sm text-text-muted">Country of award and the institution that issued this qualification.</p>
                <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <div class="lg:col-span-2" data-field="country_id">
                    <label class="text-sm font-medium text-text-primary">Country of award</label>
                    <select v-model="form.country_id" class="zaqa-input">
                      <option value="">Select country</option>
                      <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <InputError :message="form.errors.country_id" />
                  </div>
                  <div class="lg:col-span-2" data-field="awarding_institution_id">
                    <InstitutionCombobox
                      :country-id="form.country_id"
                      v-model="form.awarding_institution_id"
                      label="Awarding institution"
                      query-endpoint="/applicant/reference/awarding-institutions"
                      :error="form.errors.awarding_institution_id"
                      @selected="onInstitutionSelected"
                    />
                  </div>
                  <div v-if="form.awarding_institution_id === 'other'" class="lg:col-span-2">
                    <label class="text-sm font-medium">Institution name (other)</label>
                    <input v-model="form.awarding_institution_name_other" class="zaqa-input" placeholder="Official institution name" />
                    <InputError :message="form.errors.awarding_institution_name_other" />
                  </div>
                </div>
              </section>

              <section class="rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-7">
                <div class="flex items-center gap-2 text-text-primary">
                  <GraduationCap class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h2 class="text-base font-semibold">Qualification holder</h2>
                </div>
                <p class="mt-1 text-sm text-text-muted">Name and primary ID as recorded on this qualification item.</p>
                <div class="mt-5 grid grid-cols-1 gap-4">
                  <div data-field="names_as_on_qualification_document">
                    <label class="text-sm font-medium">Name as on qualification document</label>
                    <input
                      v-model="form.names_as_on_qualification_document"
                      class="zaqa-input"
                      placeholder="Enter the names exactly as printed on the certificate or transcript"
                      autocomplete="off"
                    />
                    <p class="mt-1.5 text-xs text-text-muted">Use the spelling, initials, and order shown on the document.</p>
                    <InputError :message="form.errors.names_as_on_qualification_document" />
                  </div>
                  <div data-field="qualification_holder_name">
                    <label class="text-sm font-medium">Full name</label>
                    <input v-model="form.qualification_holder_name" class="zaqa-input" />
                    <InputError :message="form.errors.qualification_holder_name" />
                  </div>
                  <div data-field="nrc_passport_number">
                    <label class="text-sm font-medium">NRC / Passport number</label>
                    <div class="mt-1 flex flex-col gap-2 sm:flex-row sm:items-center">
                      <input v-model="form.nrc_passport_number" class="zaqa-input font-mono text-sm sm:min-w-0 sm:flex-1" />
                      <div v-if="identity_document" class="flex shrink-0 flex-wrap gap-2">
                        <a
                          :href="identity_document.preview_url"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"
                          :title="`Preview ${identityDocumentLabel}`"
                        >
                          <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                          Preview
                        </a>
                        <a
                          :href="identity_document.download_url"
                          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"
                          :title="`Download ${identityDocumentLabel}`"
                        >
                          <Download class="h-3.5 w-3.5" aria-hidden="true" />
                          Download
                        </a>
                        <button
                          v-if="identity_document.source === 'application'"
                          type="button"
                          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"
                          @click="openIdentityUploadModal"
                        >
                          <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
                          Replace
                        </button>
                        <button
                          v-if="identity_document.can_delete && identity_document.document_id"
                          type="button"
                          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs text-danger"
                          :disabled="deletingDocumentId === identity_document.document_id"
                          @click="identityDocumentRow() && confirmDeleteDocument(identityDocumentRow()!)"
                        >
                          <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                          Remove
                        </button>
                      </div>
                      <button
                        v-else
                        type="button"
                        class="zaqa-btn zaqa-btn-secondary inline-flex shrink-0 items-center gap-1.5 px-3 py-2 text-xs"
                        @click="openUploadModal('nrc_copy', 'NRC copy')"
                      >
                        <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
                        Upload ID
                      </button>
                    </div>
                    <p v-if="identity_document?.source === 'profile'" class="mt-1.5 text-xs text-text-muted">
                      Identity file is stored on the applicant profile (preview/download only).
                    </p>
                    <InputError :message="form.errors.nrc_passport_number" />
                  </div>
                </div>
              </section>
            </div>

            <div class="space-y-8">
              <section class="rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-7">
                <h2 class="text-base font-semibold text-text-primary">Qualification details</h2>
                <p class="mt-1 text-sm text-text-muted">Must align with supporting documents.</p>
                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div class="sm:col-span-2" data-field="qualification_type_id">
                    <label class="text-sm font-medium">Qualification type (ZQF)</label>
                    <select v-model="form.qualification_type_id" class="zaqa-input">
                      <option value="" disabled>Select type…</option>
                      <option v-for="t in qualificationTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <InputError :message="form.errors.qualification_type_id" />
                  </div>
                  <div class="sm:col-span-2" data-field="title_of_qualification">
                    <label class="text-sm font-medium">Title of qualification</label>
                    <input v-model="form.title_of_qualification" class="zaqa-input" />
                    <InputError :message="form.errors.title_of_qualification" />
                  </div>
                  <div>
                    <label class="text-sm font-medium">Identifier type</label>
                    <select v-model="identifierType" class="zaqa-input">
                      <option value="certificate_number">Certificate number</option>
                      <option value="student_number">Student number</option>
                      <option value="examination_number">Examination number</option>
                    </select>
                  </div>
                  <div data-field="identifier">
                    <label class="text-sm font-medium">Identifier value</label>
                    <input v-model="identifierValue" class="zaqa-input" />
                    <InputError :message="form.errors.certificate_number" />
                  </div>
                  <div class="sm:col-span-2" data-field="award_date">
                    <label class="text-sm font-medium">Award date</label>
                    <input v-model="form.award_date" type="date" class="zaqa-input" />
                    <InputError :message="form.errors.award_date" />
                  </div>
                </div>

                <div v-if="needsSubjects" class="mt-8 border-t border-border pt-8" data-field="subject_results">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-sm font-semibold text-text-primary">Subject results</div>
                    <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="addSubjectRow">Add subject</button>
                  </div>
                  <p class="mt-2 text-xs text-text-muted">Required for this qualification type.</p>
                  <div class="mt-4 space-y-3">
                    <div
                      v-for="(row, idx) in form.subject_results"
                      :key="idx"
                      class="flex flex-col gap-3 rounded-xl border border-border bg-surface-muted/40 p-4 sm:flex-row sm:items-end"
                    >
                      <div class="min-w-0 flex-1">
                        <label class="text-xs font-medium text-text-muted">Subject</label>
                        <select v-model.number="row.certificate_subject_id" class="zaqa-input mt-1">
                          <option :value="''" disabled>Select subject…</option>
                          <option v-for="s in certificateSubjects" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <p
                          v-if="row.subject_name && !row.certificate_subject_id"
                          class="mt-1 text-xs text-amber-800"
                        >
                          Applicant saved subject "{{ row.subject_name }}" — please select the matching subject from the list.
                        </p>
                      </div>
                      <div class="w-full sm:w-40">
                        <label class="text-xs font-medium text-text-muted">Grade</label>
                        <SubjectGradeSelect
                          v-model="row.grade"
                          :saved-grade="row.saved_grade"
                          :grade-options="subjectGradeOptions"
                          input-class="zaqa-input mt-1"
                        />
                      </div>
                      <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs sm:mb-0" @click="removeSubjectRow(idx)">Remove</button>
                    </div>
                  </div>
                  <InputError class="mt-2" :message="form.errors.subject_results" />
                </div>
              </section>
            </div>
          </div>

          <section class="rounded-2xl border border-border bg-surface-muted/40 p-6 shadow-sm sm:p-7">
            <div class="flex items-center gap-2 text-text-primary">
              <FileText class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
              <h2 class="text-base font-semibold">Supporting documents</h2>
            </div>
            <p class="mt-1 text-sm text-text-muted">Preview or replace files attached to this qualification.</p>

            <div v-if="documentSlots.length" class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
              <div
                v-for="slot in documentSlots"
                :key="slot.document_type"
                class="flex h-full flex-col gap-3 rounded-xl border border-border/80 bg-surface p-4 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0 flex-1">
                  <div class="text-sm font-medium text-text-primary">{{ slot.label }}</div>
                  <div v-if="slot.document" class="mt-0.5 truncate text-xs text-text-muted">
                    {{ slot.document.original_name }} · v{{ slot.document.version_number }}
                  </div>
                  <div v-else class="mt-0.5 text-xs text-text-muted">Not uploaded</div>
                </div>
                <div class="flex flex-wrap gap-2 sm:shrink-0 sm:justify-end">
                  <template v-if="slot.document">
                    <a
                      :href="slot.document.preview_url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1 px-3 py-1.5 text-xs"
                    >
                      <Eye class="h-3.5 w-3.5" /> Preview
                    </a>
                    <a :href="slot.document.download_url" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1 px-3 py-1.5 text-xs">
                      <Download class="h-3.5 w-3.5" /> Download
                    </a>
                    <button
                      type="button"
                      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1 px-3 py-1.5 text-xs"
                      @click="openUploadModal(slot.document_type, slot.label)"
                    >
                      <RefreshCw class="h-3.5 w-3.5" /> Replace
                    </button>
                    <button
                      v-if="slot.document.can_delete"
                      type="button"
                      class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1 px-3 py-1.5 text-xs text-danger"
                      :disabled="deletingDocumentId === slot.document.id"
                      @click="confirmDeleteDocument(slot.document)"
                    >
                      <Trash2 class="h-3.5 w-3.5" /> Remove
                    </button>
                  </template>
                  <button
                    v-else
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-1 px-3 py-1.5 text-xs"
                    @click="openUploadModal(slot.document_type, slot.label)"
                  >
                    <RefreshCw class="h-3.5 w-3.5" /> Upload
                  </button>
                </div>
              </div>
            </div>

            <div
              v-else
              class="mt-4 rounded-xl border border-dashed border-border bg-surface-muted/40 px-4 py-5 text-sm text-text-muted"
            >
              No document types configured for this qualification.
            </div>
          </section>

          <div class="space-y-4 border-t border-border pt-6">
            <div class="max-w-3xl" data-field="correction_note">
              <label class="text-sm font-medium text-text-primary">Correction note <span class="text-danger">*</span></label>
              <p class="mt-0.5 text-xs text-text-muted">Required when saving field changes. Explain why these qualification details were corrected.</p>
              <textarea
                v-model="form.correction_note"
                class="zaqa-input mt-2 min-h-[7rem] resize-y"
                rows="4"
                :maxlength="CORRECTION_NOTE_MAX_LENGTH"
                placeholder="e.g. Fixed institution name to match certificate scan."
                required
              />
              <div class="mt-1.5 flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <InputError :message="form.errors.correction_note" />
                <p
                  class="ml-auto shrink-0 text-xs tabular-nums"
                  :class="correctionNoteLength >= CORRECTION_NOTE_MAX_LENGTH ? 'text-danger' : 'text-text-muted'"
                >
                  {{ correctionNoteLength }} / {{ CORRECTION_NOTE_MAX_LENGTH }}
                </p>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <Link :href="`/admin/verification/qualifications/${qualification.id}`" class="zaqa-btn zaqa-btn-secondary px-5 py-2.5 text-sm">Cancel</Link>
              <button
                type="submit"
                class="zaqa-btn zaqa-btn-primary px-5 py-2.5 text-sm"
                :disabled="form.processing || !form.isDirty"
              >
                Save changes
              </button>
              <p v-if="!form.isDirty" class="text-xs text-text-muted">No changes to save.</p>
            </div>
          </div>
        </form>
      </div>
    </div>

    <AdminActionModal
      v-model="correctionHistoryOpen"
      title="Correction history"
      description="Who changed what on this qualification during verification."
      max-width-class="max-w-4xl"
      scrollable
    >
      <div v-if="correction_history.length" class="space-y-4">
        <div
          v-for="entry in correction_history"
          :key="entry.id"
          class="rounded-xl border border-border/80 bg-surface-muted/30 px-4 py-3"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="text-sm font-semibold text-text-primary">{{ entry.summary }}</div>
            <div class="text-xs text-text-muted">{{ formatAt(entry.at) }}</div>
          </div>
          <div class="mt-1 text-xs text-text-muted">{{ entry.actor_name ?? 'System' }}</div>
          <p v-if="entry.note" class="mt-2 text-sm text-text-primary">{{ entry.note }}</p>
          <ul v-if="entry.field_changes?.length" class="mt-3 space-y-2 text-sm">
            <li v-for="(change, cIdx) in entry.field_changes" :key="cIdx" class="rounded-lg bg-surface px-3 py-2">
              <div class="font-medium text-text-primary">{{ change.label }}</div>
              <div class="mt-1 text-xs text-text-muted">
                <span class="line-through">{{ change.from }}</span>
                <span class="mx-1">→</span>
                <span class="font-medium text-text-primary">{{ change.to }}</span>
              </div>
            </li>
          </ul>
          <div v-else-if="entry.event_type.includes('document')" class="mt-2 text-xs text-text-muted">
            <span v-if="entry.document_before">{{ entry.document_before }}</span>
            <span v-if="entry.document_before && entry.document_after"> → </span>
            <span v-if="entry.document_after">{{ entry.document_after }}</span>
          </div>
        </div>
      </div>
      <div v-else class="rounded-xl border border-dashed border-border bg-surface-muted/40 px-4 py-5 text-sm text-text-muted">
        No corrections recorded yet.
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="correctionHistoryOpen = false">
          Close
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="uploadModalOpen"
      :title="documentUploadForm.document_type && documentsByType.get(documentUploadForm.document_type) ? `Replace ${uploadTargetLabel}` : `Upload ${uploadTargetLabel}`"
      description="The new file replaces the current version for this document type. This action is logged."
      max-width-class="max-w-lg"
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-medium">File</label>
          <input
            ref="uploadModalFileInput"
            type="file"
            accept=".pdf,image/jpeg,image/png,image/webp"
            class="zaqa-input mt-1 block w-full text-sm"
            @change="onUploadModalFileChange"
          />
          <InputError :message="documentUploadForm.errors.file" />
        </div>
        <div>
          <label class="text-sm font-medium">Note (optional)</label>
          <input v-model="documentUploadForm.correction_note" class="zaqa-input mt-1" placeholder="Reason for this upload…" />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="closeUploadModal">Cancel</button>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
          :disabled="documentUploadForm.processing || !documentUploadForm.file"
          @click="submitDocumentUpload"
        >
          {{ documentsByType.get(documentUploadForm.document_type) ? 'Replace file' : 'Upload file' }}
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="approveOpen"
      title="Approve qualification"
      description="Review Level 1 findings and accreditation statement before approving."
      max-width-class="max-w-4xl"
      scrollable
    >
      <div class="space-y-4">
        <Level2DecisionLevel1Fields
          :findings="approveForm.findings"
          :accreditation-statement="approveForm.accreditation_statement"
          :findings-error="approveForm.errors.findings"
          :accreditation-statement-error="approveForm.errors.accreditation_statement"
          :accreditation-required="can?.issue_certificate === true"
          :institution-defaulted="level2AccreditationInstitutionDefaulted(approveForm)"
          :institution-missing-statement="level2AccreditationInstitutionMissing()"
          @update:findings="approveForm.findings = $event"
          @update:accreditation-statement="approveForm.accreditation_statement = $event"
        />

        <div>
          <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
          <textarea v-model="approveForm.comment" class="zaqa-input mt-2 h-auto min-h-[6rem] py-3" placeholder="Optional internal note." />
          <div v-if="approveForm.errors.comment" class="mt-1 text-xs text-danger">{{ approveForm.errors.comment }}</div>
        </div>
        <div v-if="can?.issue_certificate" class="rounded-xl border border-border/70 bg-surface-muted/40 px-4 py-3 text-sm text-text-primary">
          <p class="font-semibold">Certificate of Recognition</p>
          <p class="mt-1 text-xs text-text-muted">
            A certificate of recognition will be generated automatically when you approve. Payment must be satisfied.
          </p>
          <p v-if="application.payment_satisfied === false" class="mt-2 text-xs font-medium text-amber-900">
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
              resetApproveForm()
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
            () => {
              approveForm.issue_certificate = true
              approveForm.post(`/admin/verification/qualifications/${qualification.id}/approve`, {
                preserveScroll: true,
                onSuccess: () => {
                  approveOpen = false
                  resetApproveForm()
                },
              })
            }
          "
        >
          Approve
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="rejectOpen"
      title="Reject qualification"
      description="Reason is required and will be visible to the applicant."
      max-width-class="max-w-4xl"
      scrollable
    >
      <div class="space-y-4">
        <Level2DecisionLevel1Fields
          :findings="rejectForm.findings"
          :accreditation-statement="rejectForm.accreditation_statement"
          :findings-error="rejectForm.errors.findings"
          :accreditation-statement-error="rejectForm.errors.accreditation_statement"
          :institution-defaulted="level2AccreditationInstitutionDefaulted(rejectForm)"
          :institution-missing-statement="level2AccreditationInstitutionMissing()"
          @update:findings="rejectForm.findings = $event"
          @update:accreditation-statement="rejectForm.accreditation_statement = $event"
        />

        <div>
          <label class="text-sm font-semibold text-text-primary">Reason</label>
          <textarea v-model="rejectForm.reason" class="zaqa-input mt-2 h-auto min-h-[10rem] py-3" placeholder="Provide a clear rejection reason." />
          <p
            v-if="level1Review?.recommended_for_award === false && level1Findings.length > 0"
            class="mt-2 text-xs text-text-muted"
          >
            Pre-filled from Level 1 findings when they did not recommend recognition. You may edit before submitting.
          </p>
          <div v-if="rejectForm.errors.reason" class="mt-1 text-xs text-danger">{{ rejectForm.errors.reason }}</div>
          <div v-if="(rejectForm.errors as any).qualification" class="mt-1 text-xs text-danger">{{ (rejectForm.errors as any).qualification }}</div>
          <div v-if="(rejectForm.errors as any).lock" class="mt-1 text-xs text-danger">{{ (rejectForm.errors as any).lock }}</div>
          <p v-if="can?.issue_certificate" class="mt-4 text-xs text-text-muted">
            A rejection notice will be generated automatically when you reject this qualification.
          </p>
        </div>
      </div>
      <template #footer>
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          @click="
            () => {
              rejectOpen = false
              resetRejectForm()
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
            () => {
              rejectForm.generate_rejection_notice = true
              rejectForm.post(`/admin/verification/qualifications/${qualification.id}/reject`, {
                preserveScroll: true,
                onSuccess: () => {
                  rejectOpen = false
                  resetRejectForm()
                },
              })
            }
          "
        >
          Reject
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
