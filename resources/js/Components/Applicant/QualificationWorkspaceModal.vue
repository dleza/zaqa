<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import DocumentManager from '@/Components/DocumentManager.vue'
import InputError from '@/Components/InputError.vue'
import { Building2, FileStack, GraduationCap, MapPin, Shield, Sparkles, X } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: boolean
  mode: 'add' | 'edit'
  applicationId: number
  application: any
  countries: Array<{ id: number; name: string; iso_code?: string | null }>
  qualificationTypes: Array<any>
  editingQualification: any | null
  locked: boolean
  zambiaCountryId: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [boolean]
  saved: [{ qualificationId: number | null }]
}>()

const modalQualId = ref<number | null>(null)
const institutionMeta = ref<{ name: string; consent_form_url?: string | null; has_consent_form?: boolean } | null>(null)

type IdentifierType = 'certificate_number' | 'student_number' | 'examination_number'
const identifierType = ref<IdentifierType>('certificate_number')
const identifierValue = ref('')

const form = useForm({
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
  award_date: '',
  qualification_type_id: '' as number | string | '',
  transcript_reason: '',
  subject_results: [] as Array<{ subject_name: string; grade: string }>,
})

const foreignConsentForm = useForm<{
  qualification_id: number | null
  file: File | null
  source_awarding_institution_name: string
}>({
  qualification_id: null,
  file: null,
  source_awarding_institution_name: '',
})

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

function awardingDisplayName(): string {
  if (form.awarding_institution_id === 'other') {
    return (form.awarding_institution_name_other ?? '').toString().trim()
  }
  return (institutionMeta.value?.name ?? '').toString().trim() || (form.awarding_institution_name ?? '').toString().trim()
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
  const list = (props.application?.qualifications ?? []) as any[]
  return list.find((q) => Number(q.id) === Number(id)) ?? props.editingQualification
})

const institutionConsentUrl = computed(() => {
  const q = freshQualification.value
  if (q?.institution_consent_form_url) return q.institution_consent_form_url as string
  return institutionMeta.value?.consent_form_url ?? null
})

const institutionHasConsentTemplate = computed(() => {
  if (form.awarding_institution_id === 'other') return true
  const q = freshQualification.value
  if (typeof q?.institution_has_consent_form === 'boolean') return q.institution_has_consent_form
  if (institutionMeta.value?.has_consent_form === false) return false
  return !!institutionConsentUrl.value
})

function resetSubjectRows() {
  form.subject_results = []
}

function addSubjectRow() {
  form.subject_results.push({ subject_name: '', grade: '' })
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
  form.award_date = q.award_date ?? ''
  form.qualification_type_id = q.qualification_type_id ?? ''
  form.transcript_reason = q.transcript_reason ?? ''
  form.subject_results = (q.subject_results ?? []).map((r: any) => ({
    subject_name: r.subject_name ?? '',
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
  syncIdentifierFromForm()
}

async function openModalState() {
  form.clearErrors()
  foreignConsentForm.clearErrors()
  foreignConsentForm.reset('file')
  institutionMeta.value = null

  if (props.mode === 'edit' && props.editingQualification) {
    modalQualId.value = props.editingQualification.id
    await loadFromQualification(props.editingQualification)
  } else {
    modalQualId.value = null
    form.reset()
    form.qualification_id = null
    if (props.zambiaCountryId) form.country_id = props.zambiaCountryId
    resetSubjectRows()
    syncIdentifierFromForm()
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) openModalState()
  },
)

watch(
  () => props.editingQualification,
  async () => {
    if (props.modelValue && props.mode === 'edit' && props.editingQualification) {
      modalQualId.value = props.editingQualification.id
      await loadFromQualification(props.editingQualification)
    }
  },
)

watch(needsSubjects, (need) => {
  if (need && form.subject_results.length === 0) addSubjectRow()
})

function close() {
  emit('update:modelValue', false)
}

function submitDetails() {
  applyIdentifierToForm()
  form.awarding_institution_name = awardingDisplayName() || '—'

  const finishSync = () => {
    void nextTick(() => {
      const list = (props.application?.qualifications ?? []) as any[]
      const ids = list.map((q) => Number(q.id)).filter(Boolean)
      if (props.mode === 'add') {
        modalQualId.value = ids.length ? Math.max(...ids) : modalQualId.value
      } else {
        modalQualId.value = form.qualification_id ? Number(form.qualification_id) : null
      }
      emit('saved', { qualificationId: modalQualId.value })
    })
  }

  const reloadAfterSave = () =>
    router.reload({
      only: ['application'],
      preserveScroll: true,
      onFinish: finishSync,
    })

  if (props.mode === 'add') {
    form
      .transform((data) => {
        const o: Record<string, any> = stripHolderFields({ ...data })
        o.awarding_institution_name = awardingDisplayName() || '—'
        o.create_new = true
        if (!needsSubjects.value) delete o.subject_results
        return o
      })
      .post(`/applicant/applications/${props.applicationId}/qualifications`, {
        preserveScroll: true,
        onSuccess: reloadAfterSave,
      })
  } else {
    form
      .transform((data) => {
        const o: Record<string, any> = stripHolderFields({ ...data })
        o.awarding_institution_name = awardingDisplayName() || '—'
        if (!needsSubjects.value) delete o.subject_results
        return o
      })
      .put(`/applicant/applications/${props.applicationId}/qualification`, {
        preserveScroll: true,
        onSuccess: reloadAfterSave,
      })
  }
}

function onForeignFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  foreignConsentForm.file = target.files && target.files.length > 0 ? target.files[0] : null
}

function uploadForeignConsent() {
  const qid = modalQualId.value
  if (!qid) return
  foreignConsentForm.qualification_id = qid
  foreignConsentForm.source_awarding_institution_name = awardingDisplayName() || ''
  foreignConsentForm.post(`/applicant/applications/${props.applicationId}/consent/foreign-upload`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      foreignConsentForm.reset('file')
      router.reload({ only: ['application'] })
    },
  })
}

const docFilterForQual = computed(() => {
  const id = modalQualId.value
  if (!id) return []
  return (props.application?.documents ?? []).filter((d: any) => Number(d.qualification_id ?? 0) === Number(id))
})

const showConsentUpload = computed(() => isForeignAwarding.value && !!modalQualId.value)

const canSaveDetails = computed(() => !props.locked && !form.processing)

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
  if (n && p) return `${n} · ${p}`
  return n || p || '—'
})

function stripHolderFields(data: Record<string, unknown>) {
  const o = { ...data }
  delete o.qualification_holder_name
  delete o.nrc_passport_number
  return o
}
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="modelValue"
        class="fixed inset-0 z-[80] flex items-end justify-center bg-black/55 px-3 py-6 backdrop-blur-[2px] sm:items-center sm:px-6"
        role="dialog"
        aria-modal="true"
        aria-labelledby="qual-modal-title"
        @keydown.esc="close"
      >
        <div
          class="relative flex max-h-[min(94vh,980px)] w-full max-w-5xl xl:max-w-6xl flex-col overflow-hidden rounded-2xl border border-border/80 bg-surface shadow-[0_24px_80px_-12px_rgba(0,0,0,0.35)]"
          @click.stop
        >
          <!-- Header -->
          <div
            class="relative shrink-0 overflow-hidden border-b border-border bg-gradient-to-br from-[#0B3A66] via-[#0d4d8c] to-[#0B3A66] px-6 py-5 text-white sm:px-8 sm:py-6"
          >
            <div class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-white/10 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-12 -left-10 h-36 w-36 rounded-full bg-brand/25 blur-2xl" />
            <div class="relative flex items-start justify-between gap-4">
              <div class="flex min-w-0 items-start gap-3">
                <div class="mt-0.5 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25">
                  <Sparkles class="h-5 w-5 text-white" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                  <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-white/75">Qualification workspace</p>
                  <h2 id="qual-modal-title" class="mt-1 text-xl font-semibold tracking-tight sm:text-2xl">
                    {{ mode === 'add' ? 'Add qualification' : 'Edit qualification' }}
                  </h2>
                  <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/85">
                    Capture where the award was issued, then upload the correct documents. Fields must match your certificate exactly.
                  </p>
                </div>
              </div>
              <button
                type="button"
                class="rounded-xl border border-white/25 bg-white/10 p-2 text-white transition hover:bg-white/20"
                aria-label="Close"
                @click="close"
              >
                <X class="h-5 w-5" />
              </button>
            </div>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="space-y-8 px-6 py-6 sm:px-8 sm:py-8">
              <!-- Location -->
              <section class="rounded-2xl border border-border bg-surface-muted/40 p-5 ring-1 ring-black/[0.03]">
                <div class="flex items-center gap-2 text-text-primary">
                  <MapPin class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h3 class="text-base font-semibold">Award location & institution</h3>
                </div>
                <p class="mt-1 text-sm text-text-muted">Country of award and the institution that issued this qualification.</p>
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
                    <label class="text-sm font-medium">Institution name (other)</label>
                    <input v-model="form.awarding_institution_name_other" class="zaqa-input" :disabled="locked" placeholder="Type the official institution name" />
                    <InputError :message="form.errors.awarding_institution_name_other" />
                  </div>
                </div>
              </section>

              <!-- Qualification -->
              <section class="rounded-2xl border border-border bg-surface p-5 ring-1 ring-black/[0.03]">
                <div class="flex items-center gap-2 text-text-primary">
                  <GraduationCap class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h3 class="text-base font-semibold">Qualification details</h3>
                </div>
                <p class="mt-1 text-sm text-text-muted">Must match the certificate or transcript exactly.</p>

                <div
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
                    <label class="text-sm font-medium">Qualification type (ZQF)</label>
                    <select v-model="form.qualification_type_id" class="zaqa-input" :disabled="locked || !!application?.invoice">
                      <option value="" disabled>Select type…</option>
                      <option v-for="t in qualificationTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <InputError :message="form.errors.qualification_type_id" />
                    <div v-if="application?.invoice" class="mt-2 rounded-lg border border-warning/25 bg-warning/10 px-3 py-2 text-xs text-warning">
                      Qualification type is locked after billing has been prepared (Payment step).
                    </div>
                  </div>
                  <div class="sm:col-span-2">
                    <label class="text-sm font-medium">Title of qualification</label>
                    <input v-model="form.title_of_qualification" class="zaqa-input" :disabled="locked" />
                    <InputError :message="form.errors.title_of_qualification" />
                  </div>
                  <div>
                    <label class="text-sm font-medium">Identifier type</label>
                    <select v-model="identifierType" class="zaqa-input" :disabled="locked">
                      <option value="certificate_number">Certificate number</option>
                      <option value="student_number">Student number</option>
                      <option value="examination_number">Examination number</option>
                    </select>
                  </div>
                  <div>
                    <label class="text-sm font-medium">Identifier value</label>
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
                  <div class="mt-3 space-y-3">
                    <div v-for="(row, idx) in form.subject_results" :key="idx" class="grid grid-cols-1 gap-3 sm:grid-cols-7">
                      <div class="sm:col-span-4">
                        <label class="text-xs font-medium">Subject</label>
                        <input v-model="row.subject_name" class="zaqa-input" :disabled="locked" />
                      </div>
                      <div class="sm:col-span-2">
                        <label class="text-xs font-medium">Grade</label>
                        <input v-model="row.grade" class="zaqa-input" :disabled="locked" />
                      </div>
                      <div class="sm:col-span-1 flex items-end">
                        <button type="button" class="zaqa-btn zaqa-btn-ghost w-full text-xs" :disabled="locked" @click="removeSubjectRow(idx)">Remove</button>
                      </div>
                    </div>
                  </div>
                  <InputError :message="form.errors.subject_results" class="mt-2" />
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                  <button type="button" class="zaqa-btn zaqa-btn-primary px-6" :disabled="!canSaveDetails" @click="submitDetails">
                    {{ modalQualId ? 'Save changes' : 'Save & continue to documents' }}
                  </button>
                  <button type="button" class="zaqa-btn zaqa-btn-secondary" @click="close">Cancel</button>
                </div>
              </section>

              <!-- Documents -->
              <section
                v-if="modalQualId"
                class="rounded-2xl border border-border bg-surface p-5 ring-1 ring-black/[0.03]"
              >
                <div class="flex items-center gap-2 text-text-primary">
                  <FileStack class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
                  <h3 class="text-base font-semibold">Qualification documents</h3>
                </div>
                <p class="mt-1 text-sm text-text-muted">
                  <template v-if="!isForeignAwarding">Upload your certificate (and transcript if applicable) for this qualification.</template>
                  <template v-else>
                    Upload certificate and transcript. You will also confirm the awarding institution consent below.
                  </template>
                </p>

                <div class="mt-5">
                  <DocumentManager
                    :upload-url="`/applicant/applications/${applicationId}/documents`"
                    :documents="docFilterForQual"
                    :transcript-required="transcriptRequiredForDocs"
                    :qualification-id="modalQualId"
                    documents-scope="qualification_only"
                  />
                </div>
              </section>

              <!-- Institution consent (foreign awarding only) -->
              <section
                v-if="showConsentUpload"
                class="rounded-2xl border border-brand/20 bg-brand/[0.04] p-5 ring-1 ring-brand/15"
              >
                <div class="flex items-start gap-3">
                  <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand/15 text-brand">
                    <Shield class="h-5 w-5" aria-hidden="true" />
                  </div>
                  <div class="min-w-0">
                    <h3 class="text-base font-semibold text-text-primary">Awarding institution consent</h3>
                    <p class="mt-1 text-sm text-text-muted">
                      This institution sits outside Zambia. Download its consent template, sign it, then upload the signed file here.
                    </p>
                  </div>
                </div>

                <div
                  v-if="institutionHasConsentTemplate === false"
                  class="mt-4 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning"
                >
                  No consent template is on file for this institution. Choose another institution or contact support.
                </div>

                <div v-else class="mt-4 flex flex-wrap gap-2">
                  <a
                    v-if="institutionConsentUrl"
                    :href="institutionConsentUrl"
                    target="_blank"
                    rel="noopener"
                    class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 text-sm"
                  >
                    <Building2 class="h-4 w-4" aria-hidden="true" />
                    Download consent template
                  </a>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-3 sm:max-w-lg">
                  <div>
                    <label class="text-sm font-medium">Signed consent file</label>
                    <input
                      type="file"
                      accept=".pdf,.doc,.docx,application/pdf"
                      class="zaqa-input"
                      :disabled="locked || institutionHasConsentTemplate === false"
                      @change="onForeignFileChange"
                    />
                    <InputError :message="foreignConsentForm.errors.file" />
                  </div>
                  <button
                    type="button"
                    class="zaqa-btn zaqa-btn-primary w-fit"
                    :disabled="locked || foreignConsentForm.processing || !foreignConsentForm.file || institutionHasConsentTemplate === false"
                    @click="uploadForeignConsent"
                  >
                    Upload signed consent
                  </button>
                </div>
              </section>
            </div>
          </div>

          <div class="shrink-0 border-t border-border bg-surface-muted/60 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <p class="text-xs text-text-muted">Saved details unlock uploads. You can return anytime via Edit on the list.</p>
              <button type="button" class="zaqa-btn zaqa-btn-secondary" @click="close">Done</button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
