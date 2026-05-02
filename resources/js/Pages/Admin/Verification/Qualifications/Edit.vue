<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import InstitutionCombobox from '@/Components/InstitutionCombobox.vue'
import InputError from '@/Components/InputError.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { ArrowLeft, FileEdit, GraduationCap, MapPin } from 'lucide-vue-next'

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
  transcript_reason: props.qualification.transcript_reason ?? '',
  notes: props.qualification.notes ?? '',
  correction_note: '',
  subject_results: (props.qualification.subject_results ?? []).map((r: any) => ({
    certificate_subject_id: r.certificate_subject_id != null && r.certificate_subject_id !== '' ? Number(r.certificate_subject_id) : ('' as number | string | ''),
    grade: r.grade ?? '',
  })),
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

watch(needsSubjects, (need) => {
  if (need && form.subject_results.length === 0) {
    form.subject_results.push({ certificate_subject_id: '', grade: '' })
  }
})

function addSubjectRow() {
  form.subject_results.push({ certificate_subject_id: '', grade: '' })
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
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
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
              <p class="mt-1 text-sm text-text-muted">
                Application {{ application.application_number ?? '—' }} — correct errors in the record being verified. Changes are
                written to the audit log.
              </p>
            </div>
          </div>
        </div>
      </div>

      <form class="space-y-8" @submit.prevent="submit">
        <section class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <h2 class="text-sm font-bold tracking-tight text-text-primary">Audit note (optional)</h2>
          <p class="mt-1 text-xs text-text-muted">Shown in the audit trail with this correction.</p>
          <textarea
            v-model="form.correction_note"
            class="zaqa-input mt-3 h-auto min-h-[5rem] py-3"
            placeholder="e.g. Fixed institution name to match certificate scan."
          />
          <InputError :message="form.errors.correction_note" />
        </section>

        <section class="rounded-2xl border border-border bg-surface-muted/40 p-6 shadow-sm">
          <div class="flex items-center gap-2 text-text-primary">
            <MapPin class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
            <h2 class="text-base font-semibold">Award location & institution</h2>
          </div>
          <p class="mt-1 text-sm text-text-muted">Country of award and the institution that issued this qualification.</p>
          <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="text-sm font-medium text-text-primary">Country of award</label>
              <select v-model="form.country_id" class="zaqa-input">
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
                @selected="onInstitutionSelected"
              />
            </div>
            <div v-if="form.awarding_institution_id === 'other'" class="sm:col-span-2">
              <label class="text-sm font-medium">Institution name (other)</label>
              <input
                v-model="form.awarding_institution_name_other"
                class="zaqa-input"
                placeholder="Official institution name"
              />
              <InputError :message="form.errors.awarding_institution_name_other" />
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-center gap-2 text-text-primary">
            <GraduationCap class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
            <h2 class="text-base font-semibold">Qualification holder</h2>
          </div>
          <p class="mt-1 text-sm text-text-muted">Name and primary ID as recorded on this qualification item.</p>
          <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Full name</label>
              <input v-model="form.qualification_holder_name" class="zaqa-input" />
              <InputError :message="form.errors.qualification_holder_name" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">NRC / Passport number</label>
              <input v-model="form.nrc_passport_number" class="zaqa-input font-mono text-sm" />
              <InputError :message="form.errors.nrc_passport_number" />
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
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
            <div>
              <label class="text-sm font-medium">Award date</label>
              <input v-model="form.award_date" type="date" class="zaqa-input" />
              <InputError :message="form.errors.award_date" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Transcript / programme notes</label>
              <textarea v-model="form.transcript_reason" class="zaqa-input h-auto min-h-[4rem] py-3" />
              <InputError :message="form.errors.transcript_reason" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Internal notes</label>
              <textarea v-model="form.notes" class="zaqa-input h-auto min-h-[5rem] py-3" />
              <InputError :message="form.errors.notes" />
            </div>
          </div>

          <div v-if="needsSubjects" class="mt-8 border-t border-border pt-8">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="text-sm font-semibold text-text-primary">Subject results</div>
              <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs" @click="addSubjectRow">
                Add subject
              </button>
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
                  <input v-model="row.grade" class="zaqa-input mt-1" />
                </div>
                <button type="button" class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs sm:mb-0" @click="removeSubjectRow(idx)">
                  Remove
                </button>
              </div>
            </div>
            <InputError class="mt-2" :message="form.errors.subject_results" />
          </div>
        </section>

        <div class="flex flex-wrap items-center justify-end gap-3">
          <Link :href="`/admin/verification/qualifications/${qualification.id}`" class="zaqa-btn zaqa-btn-secondary px-5 py-2.5 text-sm">
            Cancel
          </Link>
          <button type="submit" class="zaqa-btn zaqa-btn-primary px-5 py-2.5 text-sm" :disabled="form.processing">
            Save changes
          </button>
        </div>
      </form>
    </div>
  </AdminLayout>
</template>
