<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import InputError from '@/Components/InputError.vue'
import SubjectGradeSelect from '@/Components/SubjectGradeSelect.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { selectGradeValue } from '@/lib/certificateSubjectGrades'
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
  application: { id: number; application_number: string | null }
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

const institutionMeta = ref<{ name: string } | null>(null)
if (props.qualification.awarding_institution_id && props.qualification.awarding_institution_id !== 'other') {
  institutionMeta.value = { name: (props.qualification.awarding_institution_name ?? '').toString() }
}

function onInstitutionSelected(opt: { id: number | 'other'; name: string }) {
  institutionMeta.value = opt.id === 'other' ? { name: '' } : { name: opt.name }
}

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
    certificate_subject_id: r.certificate_subject_id != null && r.certificate_subject_id !== '' ? Number(r.certificate_subject_id) : ('' as number | string | ''),
    grade: selectGradeValue(r.grade),
    saved_grade: r.grade ?? '',
  })),
})

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
    form.subject_results.push({ certificate_subject_id: '', grade: '', saved_grade: '' })
  }
})

function addSubjectRow() {
  form.subject_results.push({ certificate_subject_id: '', grade: '', saved_grade: '' })
}

function removeSubjectRow(idx: number) {
  form.subject_results.splice(idx, 1)
}

function submit() {
  if (form.processing) return
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
    .put(`/admin/verification/qualifications/${props.qualification.id}`, { preserveScroll: true })
}

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
          <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
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
          </div>
        </div>

        <form class="space-y-8" @submit.prevent="submit">
          <div class="grid gap-8 xl:grid-cols-2 xl:items-start">
            <div class="space-y-8">
              <section class="rounded-2xl border border-border bg-surface-muted/40 p-6 shadow-sm sm:p-7">
                <div class="flex items-center gap-2 text-text-primary">
                  <MapPin class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h2 class="text-base font-semibold">Award location & institution</h2>
                </div>
                <p class="mt-1 text-sm text-text-muted">Country of award and the institution that issued this qualification.</p>
                <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                  <div class="lg:col-span-2">
                    <label class="text-sm font-medium text-text-primary">Country of award</label>
                    <select v-model="form.country_id" class="zaqa-input">
                      <option value="">Select country</option>
                      <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <InputError :message="form.errors.country_id" />
                  </div>
                  <div class="lg:col-span-2">
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
                  <div>
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
                  <div>
                    <label class="text-sm font-medium">Full name</label>
                    <input v-model="form.qualification_holder_name" class="zaqa-input" />
                    <InputError :message="form.errors.qualification_holder_name" />
                  </div>
                  <div>
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
                  <div class="sm:col-span-2">
                    <label class="text-sm font-medium">Qualification type (ZQF)</label>
                    <select v-model="form.qualification_type_id" class="zaqa-input">
                      <option value="" disabled>Select type…</option>
                      <option v-for="t in qualificationTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <InputError :message="form.errors.qualification_type_id" />
                  </div>
                  <div class="sm:col-span-2">
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
                  <div>
                    <label class="text-sm font-medium">Identifier value</label>
                    <input v-model="identifierValue" class="zaqa-input" />
                    <InputError :message="form.errors.certificate_number" />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="text-sm font-medium">Award date</label>
                    <input v-model="form.award_date" type="date" class="zaqa-input" />
                    <InputError :message="form.errors.award_date" />
                  </div>
                </div>

                <div v-if="needsSubjects" class="mt-8 border-t border-border pt-8">
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
                        <select v-model="row.certificate_subject_id" class="zaqa-input mt-1">
                          <option value="" disabled>Select subject…</option>
                          <option v-for="s in certificateSubjects" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
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

              <section class="rounded-2xl border border-border bg-surface-muted/40 p-6 shadow-sm sm:p-7">
                <div class="flex items-center gap-2 text-text-primary">
                  <FileText class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h2 class="text-base font-semibold">Supporting documents</h2>
                </div>
                <p class="mt-1 text-sm text-text-muted">Preview or replace files attached to this qualification.</p>

                <div class="mt-5 space-y-2">
                  <div
                    v-for="slot in documentSlots"
                    :key="slot.document_type"
                    class="flex flex-col gap-2 rounded-xl border border-border/80 bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div class="min-w-0">
                      <div class="text-sm font-medium text-text-primary">{{ slot.label }}</div>
                      <div v-if="slot.document" class="truncate text-xs text-text-muted">
                        {{ slot.document.original_name }} · v{{ slot.document.version_number }}
                      </div>
                      <div v-else class="text-xs text-text-muted">Not uploaded</div>
                    </div>
                    <div class="flex flex-wrap gap-2">
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
                  v-if="documentSlots.length === 0"
                  class="mt-4 rounded-xl border border-dashed border-border bg-surface-muted/40 px-4 py-5 text-sm text-text-muted"
                >
                  No document types configured for this qualification.
                </div>
              </section>

              <section class="rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-7">
                <div class="flex items-center gap-2 text-text-primary">
                  <History class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h2 class="text-base font-semibold">Correction history</h2>
                </div>
                <p class="mt-1 text-sm text-text-muted">Who changed what on this qualification during verification.</p>
                <div v-if="correction_history.length" class="mt-5 space-y-4">
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
                <div v-else class="mt-5 rounded-xl border border-dashed border-border bg-surface-muted/40 px-4 py-5 text-sm text-text-muted">
                  No corrections recorded yet.
                </div>
              </section>
            </div>
          </div>

          <div class="space-y-4 border-t border-border pt-6">
            <div class="max-w-xl">
              <label class="text-sm font-medium text-text-primary">Correction note (optional)</label>
              <p class="mt-0.5 text-xs text-text-muted">Recorded once when you save field changes below.</p>
              <input
                v-model="form.correction_note"
                class="zaqa-input mt-2"
                placeholder="e.g. Fixed institution name to match certificate scan."
              />
              <InputError :message="form.errors.correction_note" />
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
              <Link :href="`/admin/verification/qualifications/${qualification.id}`" class="zaqa-btn zaqa-btn-secondary px-5 py-2.5 text-sm">Cancel</Link>
              <button type="submit" class="zaqa-btn zaqa-btn-primary px-5 py-2.5 text-sm" :disabled="form.processing">Save changes</button>
            </div>
          </div>
        </form>
      </div>
    </div>

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
  </AdminLayout>
</template>
