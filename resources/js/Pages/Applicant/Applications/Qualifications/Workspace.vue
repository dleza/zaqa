<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import QualificationTitleCombobox from '@/Components/QualificationTitleCombobox.vue'
import InputError from '@/Components/InputError.vue'
import Swal from 'sweetalert2'
import { Building2, FileStack, GraduationCap, MapPin, Shield, Sparkles } from 'lucide-vue-next'

const props = defineProps<{
  application: any
  qualificationId?: number | null
  countries: Array<{ id: number; name: string; iso_code?: string | null }>
  qualificationTypes: Array<any>
  /** Active rows from `certificate_subjects` (admin-managed). */
  certificateSubjects: Array<{ id: number; name: string }>
}>()

const mode = computed<'add' | 'edit'>(() => (props.qualificationId ? 'edit' : 'add'))

const returnUrl = computed(() => {
  try {
    const url = new URL(window.location.href)
    const v = (url.searchParams.get('return') ?? '').toString().trim()
    if (v) return v
  } catch {
    // ignore
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
const anyReturnedForAmendment = computed(() =>
  qualifications.value.some((q: any) => (q.verification_state ?? '') === 'returned_to_applicant'),
)
const locked = computed(() => {
  if (anyReturnedForAmendment.value) return false
  return invoiceSettled.value || !!props.application?.paid_at
})

const modalQualId = ref<number | null>(null)
const institutionMeta = ref<{ name: string; consent_form_url?: string | null; has_consent_form?: boolean } | null>(null)

type IdentifierType = 'certificate_number' | 'student_number' | 'examination_number'
const identifierType = ref<IdentifierType>('certificate_number')
const identifierValue = ref('')

function blankQualificationForm() {
  return {
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
    qualification_title_id: null as number | null,
    qualification_title_source: 'catalog' as 'catalog' | 'other' | '',
    applicant_entered_qualification_title: '',
    award_date: '',
    qualification_type_id: '' as number | string | '',
    transcript_reason: '',
    subject_results: [] as Array<{ certificate_subject_id: number | ''; grade: string }>,
  }
}

const form = useForm(blankQualificationForm())
const titleChoice = ref<number | 'other' | ''>('')

/** Staged files uploaded in the same action as qualification save */
const pendingCertificateFile = ref<File | null>(null)
const pendingTranscriptFile = ref<File | null>(null)
const pendingConsentFile = ref<File | null>(null)
const savingAll = ref(false)

const certificateFileInputEl = ref<HTMLInputElement | null>(null)
const transcriptFileInputEl = ref<HTMLInputElement | null>(null)
const consentFileInputEl = ref<HTMLInputElement | null>(null)

function clearFileInputs() {
  if (certificateFileInputEl.value) certificateFileInputEl.value.value = ''
  if (transcriptFileInputEl.value) transcriptFileInputEl.value.value = ''
  if (consentFileInputEl.value) consentFileInputEl.value.value = ''
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

function addSubjectRow() {
  form.subject_results.push({ certificate_subject_id: '', grade: '' })
}

function removeSubjectRow(idx: number) {
  form.subject_results.splice(idx, 1)
}

async function loadFromQualification(q: any) {
  form.qualification_id = q.id
  form.country_name_other = q.country_name_other ?? ''
  form.awarding_institution_name_other = q.awarding_institution_name_other ?? ''
  form.awarding_institution_name = q.awarding_institution_name ?? ''
  form.certificate_number = q.certificate_number ?? ''
  form.student_number = q.student_number ?? ''
  form.examination_number = q.examination_number ?? ''
  form.title_of_qualification = q.title_of_qualification ?? ''
  form.qualification_title_id = q.qualification_title_id != null ? Number(q.qualification_title_id) : null
  form.qualification_title_source = (q.qualification_title_source ?? '') || (q.applicant_entered_qualification_title ? 'other' : 'catalog')
  form.applicant_entered_qualification_title =
    (q.applicant_entered_qualification_title ?? '') || (form.qualification_title_source === 'other' ? (q.title_of_qualification ?? '') : '')
  form.award_date = q.award_date ?? ''
  form.qualification_type_id = q.qualification_type_id ?? ''
  form.transcript_reason = q.transcript_reason ?? ''
  form.subject_results = (q.subject_results ?? []).map((r: any) => ({
    certificate_subject_id: r.certificate_subject_id != null && r.certificate_subject_id !== '' ? Number(r.certificate_subject_id) : '',
    grade: r.grade ?? '',
  }))
  institutionMeta.value = {
    name:
      (q.awarding_institution?.name ?? '').trim() ||
      (q.awarding_institution_name_other ?? '').trim() ||
      (q.awarding_institution_name ?? '').trim() ||
      '',
    consent_form_url: q.institution_consent_form_url ?? null,
    has_consent_form: q.institution_has_consent_form ?? !!q.institution_consent_form_url,
  }
  form.awarding_institution_id = ''
  form.country_id = q.country_id ?? ''
  await nextTick()
  form.awarding_institution_id =
    q.awarding_institution_id != null && q.awarding_institution_id !== ''
      ? q.awarding_institution_id
      : q.awarding_institution_name_other
        ? 'other'
        : ''

  titleChoice.value =
    form.qualification_title_source === 'other'
      ? 'other'
      : form.qualification_title_id && form.qualification_title_id > 0
        ? form.qualification_title_id
        : ''
  syncIdentifierFromForm()
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

function extractQualificationIdFromPage(page: any): number {
  if (mode.value === 'edit' && form.qualification_id) {
    return Number(form.qualification_id)
  }
  const app = page?.props?.application
  const list = (app?.qualifications ?? []) as any[]
  const ids = list.map((q) => Number(q.id)).filter(Boolean)
  return ids.length ? Math.max(...ids) : 0
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
  const qid = modalQualId.value
  if (!qid) return false
  return (props.application?.documents ?? []).some(
    (d: any) =>
      d.document_type === docType &&
      d.is_current_version &&
      Number(d.qualification_id ?? 0) === Number(qid),
  )
}

function hasPendingUploads(): boolean {
  return !!(pendingCertificateFile.value || pendingTranscriptFile.value || pendingConsentFile.value)
}

function onPendingCertificateChange(e: Event) {
  const t = e.target as HTMLInputElement
  pendingCertificateFile.value = t.files?.[0] ?? null
}

function onPendingTranscriptChange(e: Event) {
  const t = e.target as HTMLInputElement
  pendingTranscriptFile.value = t.files?.[0] ?? null
}

function onPendingConsentChange(e: Event) {
  const t = e.target as HTMLInputElement
  pendingConsentFile.value = t.files?.[0] ?? null
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
}

function stripHolderFields(data: Record<string, unknown>) {
  const o = { ...data }
  delete (o as any).qualification_holder_name
  delete (o as any).nrc_passport_number
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

async function handleAfterQualificationSavedSuccess(): Promise<void> {
  if (mode.value !== 'add') {
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
  applyIdentifierToForm()
  form.awarding_institution_name = awardingDisplayName() || '—'

  const afterQualificationSaved = async (page: any) => {
    const qid = extractQualificationIdFromPage(page)
    if (!qid) {
      await Swal.fire({
        icon: 'error',
        title: 'Could not resolve qualification',
        text: 'Save succeeded but the qualification id was missing. Please refresh and try again.',
      })
      return
    }

    modalQualId.value = qid

    if (!hasPendingUploads()) {
      await handleAfterQualificationSavedSuccess()
      return
    }

    savingAll.value = true
    try {
      await runPendingUploads(qid)
      await handleAfterQualificationSavedSuccess()
    } catch {
      await Swal.fire({
        icon: 'error',
        title: 'Upload failed',
        text: 'Qualification was saved. Fix the file upload issue or try smaller files, then open this workspace again to add documents.',
      })
      router.visit(returnUrl.value)
    } finally {
      savingAll.value = false
    }
  }

  const visitOpts = {
    preserveScroll: true,
    onSuccess: (page: any) => {
      void afterQualificationSaved(page)
    },
  }

  if (mode.value === 'add') {
    form
      .transform((data) => {
        const o: Record<string, any> = stripHolderFields({ ...data })
        o.awarding_institution_name = awardingDisplayName() || '—'
        o.create_new = true
        if (!needsSubjects.value) delete o.subject_results
        return o
      })
      .post(`/applicant/applications/${props.application.id}/qualifications`, visitOpts)
  } else {
    form
      .transform((data) => {
        const o: Record<string, any> = stripHolderFields({ ...data })
        o.awarding_institution_name = awardingDisplayName() || '—'
        if (!needsSubjects.value) delete o.subject_results
        return o
      })
      .put(`/applicant/applications/${props.application.id}/qualification`, visitOpts)
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
          <div class="space-y-8 px-6 py-6 sm:px-8 sm:py-8">
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
                    :disabled="locked || !form.awarding_institution_id || form.awarding_institution_id === 'other'"
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
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="text-sm font-semibold text-text-primary">Subject results</div>
                  <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" :disabled="locked" @click="addSubjectRow">
                    Add subject
                  </button>
                </div>
                <p v-if="certificateSubjects.length === 0" class="mt-3 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-text-primary">
                  No subjects are configured yet. An administrator must add subjects under Admin → System settings → Subjects before you can complete this section.
                </p>
                <div class="mt-3 space-y-3">
                  <div v-for="(row, idx) in form.subject_results" :key="idx" class="grid grid-cols-1 gap-3 sm:grid-cols-7">
                    <div class="sm:col-span-4">
                      <label class="text-xs font-medium">Subject</label>
                      <select v-model="row.certificate_subject_id" class="zaqa-input" :disabled="locked || certificateSubjects.length === 0">
                        <option value="">Select subject</option>
                        <option v-for="s in certificateSubjects" :key="s.id" :value="s.id">{{ s.name }}</option>
                      </select>
                    </div>
                    <div class="sm:col-span-2">
                      <label class="text-xs font-medium">Grade</label>
                      <input v-model="row.grade" class="zaqa-input" :disabled="locked" />
                    </div>
                    <div class="sm:col-span-1 flex items-end">
                      <button type="button" class="zaqa-btn zaqa-btn-ghost w-full text-xs" :disabled="locked" @click="removeSubjectRow(idx)">
                        Remove
                      </button>
                    </div>
                  </div>
                </div>
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
            <p v-if="modalQualId && hasExistingDoc('certificate_copy')" class="mt-2 text-xs text-success">
              Certificate already on file — upload again only if you want to replace it.
            </p>
            <p v-if="modalQualId && transcriptRequiredForDocs && hasExistingDoc('transcript')" class="mt-1 text-xs text-success">
                Transcript already on file — upload again only if you want to replace it.
              </p>

              <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <label class="text-sm font-medium">Certificate or qualification document</label>
                  <input
                    ref="certificateFileInputEl"
                    type="file"
                    class="zaqa-input"
                    accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                    :disabled="locked"
                    @change="onPendingCertificateChange"
                  />
                  <p v-if="pendingCertificateName" class="mt-2 text-xs text-text-muted">
                    Selected: <span class="font-semibold text-text-primary">{{ pendingCertificateName }}</span>
                  </p>
                </div>
                <div v-if="transcriptRequiredForDocs" class="sm:col-span-2">
                  <label class="text-sm font-medium">
                    Transcript
                    <span v-if="!isForeignAwarding" class="font-normal text-text-muted">(if applicable)</span>
                  </label>
                  <input
                    ref="transcriptFileInputEl"
                    type="file"
                    class="zaqa-input"
                    accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                    :disabled="locked"
                    @change="onPendingTranscriptChange"
                  />
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
                  No portal-hosted template for this institution—you can still upload a signed consent you obtained from them.
                </p>
              </div>

              <div class="mt-5 grid grid-cols-1 gap-3 sm:max-w-lg">
                <div>
                  <label class="text-sm font-medium">Upload signed consent</label>
                  <input
                    ref="consentFileInputEl"
                    type="file"
                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/webp"
                    class="zaqa-input"
                    :disabled="locked"
                    @change="onPendingConsentChange"
                  />
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
