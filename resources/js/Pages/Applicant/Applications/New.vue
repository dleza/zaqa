<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import WizardStepper from '@/Components/WizardStepper.vue'
import { BookOpen, CheckCircle2, Shield } from 'lucide-vue-next'

const props = defineProps<{
  qualificationTypes: Array<{ value: string; label: string }>
  applicant: any
}>()

const isInstitutionApplicant = computed(() => (props.applicant?.applicant_type ?? '').toString() === 'institution')

const selfNrc = computed(() => (props.applicant?.applicant_profile?.nrc_number ?? '').toString().trim())
const selfPassport = computed(() => (props.applicant?.applicant_profile?.passport_number ?? '').toString().trim())

const profileNrcInitial = (props.applicant?.applicant_profile?.nrc_number ?? '').toString()
const profilePassportInitial = (props.applicant?.applicant_profile?.passport_number ?? '').toString()

const profileHasIdentityUpload = computed(
  () => !!(props.applicant?.applicant_profile?.identity_document_uploaded_at ?? false),
)

const form = useForm({
  service_type: 'verification',
  qualification_category: props.qualificationTypes[0]?.value ?? 'certificate',
  is_foreign: false,
  submitting_for: isInstitutionApplicant.value ? 'other' : 'self',
  subject_full_name: '',
  subject_email: '',
  subject_phone: '',
  subject_nrc_number: '',
  subject_passport_number: '',
  profile_nrc_number: profileNrcInitial,
  profile_passport_number: profilePassportInitial,
  identity_document_type: 'nrc_copy' as 'nrc_copy' | 'passport_copy',
  identity_file: null as File | null,
})

const effectiveSelfNrc = computed(
  () => (form.profile_nrc_number ?? '').toString().trim() || selfNrc.value,
)
const effectiveSelfPassport = computed(
  () => (form.profile_passport_number ?? '').toString().trim() || selfPassport.value,
)
const selfMissingIdEffective = computed(
  () => effectiveSelfNrc.value.length === 0 && effectiveSelfPassport.value.length === 0,
)

type StepKey = 'applicant' | 'qualification' | 'consent' | 'payment'
const activeStep = ref<StepKey>('applicant')
const steps = computed(() => [
  { key: 'applicant' as const, label: 'Applicant' },
  { key: 'qualification' as const, label: 'Qualification' },
  { key: 'consent' as const, label: 'Declarations' },
  { key: 'payment' as const, label: 'Payment' },
])

function submit() {
  form.post('/applicant/applications', { forceFormData: true })
}

watch(
  () => form.submitting_for,
  (val) => {
    if (val === 'self') {
      form.subject_full_name = ''
      form.subject_email = ''
      form.subject_phone = ''
      form.subject_nrc_number = ''
      form.subject_passport_number = ''
      form.clearErrors()
    }
  },
)

watch(
  isInstitutionApplicant,
  (isInst) => {
    if (isInst) form.submitting_for = 'other'
  },
  { immediate: true },
)
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div
        class="w-full max-w-none mx-auto -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-8 2xl:-mx-10 2xl:px-10"
      >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div class="max-w-3xl">
            <h1 class="text-3xl font-semibold tracking-tight text-text-primary sm:text-4xl">New application</h1>
            <p class="mt-2 text-base leading-relaxed text-text-muted">
              Guided verification request—your progress is saved as you move through each step.
            </p>
          </div>

          <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary h-11 shrink-0 px-5 text-sm">
            Back
          </Link>
        </div>

        <div class="mt-6">
          <WizardStepper :steps="steps" :active-key="activeStep" />
        </div>
      </div>
    </template>

    <div
      class="w-full max-w-none mx-auto -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-6 lg:px-8 2xl:-mx-10 2xl:px-10 xl:grid xl:grid-cols-12 xl:items-start xl:gap-10"
    >
      <section
        class="rounded-2xl border border-border bg-surface p-6 shadow-sm ring-1 ring-black/5 sm:p-8 xl:col-span-8 xl:p-10"
      >
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-text-primary sm:text-xl">Applicant information</h2>
            <p class="mt-2 text-sm leading-relaxed text-text-muted sm:text-base">
              Tell us who this verification is for. You can use your profile or enter details for someone else.
            </p>
          </div>
          <div class="hidden rounded-full border border-brand/20 bg-brand/10 px-3 py-1.5 text-xs font-semibold text-brand sm:block">
            Step 1
          </div>
        </div>

        <form class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 sm:gap-6" @submit.prevent="submit">
          <div class="sm:col-span-2">
            <div class="text-sm font-medium text-text-primary">Submitting as</div>
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
              <label
                v-if="!isInstitutionApplicant"
                class="zaqa-radio-card"
                :class="form.submitting_for === 'self' ? 'zaqa-radio-card-active' : ''"
              >
                <input v-model="form.submitting_for" type="radio" value="self" class="mt-1 rounded border-border text-brand focus:ring-brand/25" />
                <div>
                  <div class="text-sm font-semibold text-text-primary">Myself</div>
                  <div class="mt-1 text-xs text-text-muted">We’ll use your authenticated profile details as the application holder.</div>
                </div>
              </label>
              <label class="zaqa-radio-card" :class="form.submitting_for === 'other' ? 'zaqa-radio-card-active' : ''">
                <input v-model="form.submitting_for" type="radio" value="other" class="mt-1 rounded border-border text-brand focus:ring-brand/25" />
                <div>
                  <div class="text-sm font-semibold text-text-primary">On behalf of someone</div>
                  <div class="mt-1 text-xs text-text-muted">Enter the biodata for the holder of the qualification to be verified below.</div>
                </div>
              </label>
            </div>
            <InputError :message="(form.errors as any).submitting_for" />
          </div>

          <div
            v-if="form.submitting_for === 'self' && !isInstitutionApplicant"
            class="sm:col-span-2 rounded-2xl border border-border bg-surface-muted p-5 sm:p-6"
          >
            <div class="text-base font-semibold text-text-primary">Your profile</div>
            <p class="mt-1 text-sm text-text-muted">
              Provide at least one identification number. We save it to your profile so you do not need to enter it again next time.
            </p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <div class="text-sm">
                <div class="text-xs font-semibold text-text-muted">Name</div>
                <div class="text-text-primary">{{ props.applicant?.name ?? '—' }}</div>
              </div>
              <div class="text-sm">
                <div class="text-xs font-semibold text-text-muted">Email</div>
                <div class="text-text-primary">{{ props.applicant?.email ?? '—' }}</div>
              </div>
              <div class="text-sm">
                <div class="text-xs font-semibold text-text-muted">Primary phone</div>
                <div class="text-text-primary">{{ props.applicant?.phone_primary ?? '—' }}</div>
              </div>
              <div class="text-sm">
                <div class="text-xs font-semibold text-text-muted">Applicant type</div>
                <div class="text-text-primary">{{ props.applicant?.applicant_type ?? '—' }}</div>
              </div>
              <div
                v-if="(props.applicant?.applicant_type ?? '') === 'individual'"
                class="sm:col-span-2 lg:col-span-3 grid grid-cols-1 gap-4 sm:grid-cols-2"
              >
                <div class="min-w-0 max-w-md">
                  <label class="text-sm font-medium text-text-primary">NRC number</label>
                  <input v-model="form.profile_nrc_number" class="zaqa-input" autocomplete="off" />
                  <InputError :message="(form.errors as any).profile_nrc_number" />
                </div>
                <div class="min-w-0 max-w-md">
                  <label class="text-sm font-medium text-text-primary">Passport number</label>
                  <input v-model="form.profile_passport_number" class="zaqa-input" autocomplete="off" />
                  <InputError :message="(form.errors as any).profile_passport_number" />
                </div>
              </div>
              <div
                v-if="(props.applicant?.applicant_type ?? '') === 'individual'"
                class="sm:col-span-2 rounded-xl border border-border bg-surface px-4 py-4 lg:col-span-3"
              >
                <div class="text-sm font-semibold text-text-primary">Upload NRC or passport copy (optional here)</div>
                <p class="mt-1 text-xs text-text-muted">
                  You can upload now or on the Applicant step later. If you already uploaded an identity document on your profile, you usually do not need to upload again.
                </p>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:items-end">
                  <div>
                    <label class="text-sm font-medium text-text-primary">Document type</label>
                    <select v-model="form.identity_document_type" class="zaqa-input">
                      <option value="nrc_copy">NRC copy</option>
                      <option value="passport_copy">Passport copy</option>
                    </select>
                  </div>
                  <div>
                    <label class="text-sm font-medium text-text-primary">File</label>
                    <input
                      type="file"
                      class="zaqa-input"
                      accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                      @change="(e) => {
                        const t = e.target as HTMLInputElement
                        form.identity_file = t.files?.[0] ?? null
                      }"
                    />
                    <InputError :message="(form.errors as any).identity_file" />
                  </div>
                </div>
                <p v-if="profileHasIdentityUpload" class="mt-2 text-xs text-success">
                  Your profile already has an identity document on file—you can skip this upload.
                </p>
              </div>
              <div v-if="(props.applicant?.applicant_type ?? '') === 'individual'" class="sm:col-span-2 text-xs text-text-muted lg:col-span-3">
                Provide <span class="font-semibold text-text-primary">either NRC or Passport</span> (or both). Values are stored on your applicant profile when you continue.
              </div>
            </div>
          </div>

          <template v-else>
            <div class="sm:col-span-2">
              <label class="text-sm font-medium">Full name (as on NRC/Passport)</label>
              <input v-model="form.subject_full_name" class="zaqa-input" />
              <InputError :message="(form.errors as any).subject_full_name" />
            </div>

            <div>
              <label class="text-sm font-medium">Email (optional)</label>
              <input v-model="form.subject_email" type="email" class="zaqa-input" />
              <InputError :message="(form.errors as any).subject_email" />
            </div>
            <div>
              <label class="text-sm font-medium">Phone (optional)</label>
              <input v-model="form.subject_phone" class="zaqa-input" />
              <InputError :message="(form.errors as any).subject_phone" />
            </div>

            <div>
              <label class="text-sm font-medium">NRC number</label>
              <input v-model="form.subject_nrc_number" class="zaqa-input" />
              <InputError :message="(form.errors as any).subject_nrc_number" />
            </div>
            <div>
              <label class="text-sm font-medium">Passport number</label>
              <input v-model="form.subject_passport_number" class="zaqa-input" />
              <InputError :message="(form.errors as any).subject_passport_number" />
            </div>

            <div class="sm:col-span-2 rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
              <div class="text-sm font-semibold text-text-primary">Upload holder’s NRC or passport copy</div>
              <p class="mt-1 text-xs text-text-muted">Required when applying on behalf of someone else.</p>
              <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:items-end">
                <div>
                  <label class="text-sm font-medium text-text-primary">Document type</label>
                  <select v-model="form.identity_document_type" class="zaqa-input">
                    <option value="nrc_copy">NRC copy</option>
                    <option value="passport_copy">Passport copy</option>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-medium text-text-primary">File</label>
                  <input
                    type="file"
                    class="zaqa-input"
                    accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                    @change="(e) => {
                      const t = e.target as HTMLInputElement
                      form.identity_file = t.files?.[0] ?? null
                    }"
                  />
                  <InputError :message="(form.errors as any).identity_file" />
                </div>
              </div>
            </div>

            <div class="sm:col-span-2 text-xs text-text-muted">
              You must provide <span class="font-semibold text-text-primary">either NRC or Passport</span> (or both).
            </div>
          </template>

          <input type="hidden" v-model="form.qualification_category" />

          <div class="flex flex-wrap gap-3 sm:col-span-2">
            <button
              type="submit"
              class="zaqa-btn zaqa-btn-primary h-12 px-8 text-base"
              :disabled="
                form.processing ||
                (form.submitting_for === 'self' && !isInstitutionApplicant && selfMissingIdEffective)
              "
            >
              Continue to step 2
            </button>
          </div>
        </form>
      </section>

      <aside class="mt-8 space-y-6 xl:col-span-4 xl:mt-0">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm ring-1 ring-black/5 sm:p-7">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-surface-muted">
              <BookOpen class="h-5 w-5 text-brand" aria-hidden="true" />
            </div>
            <div>
              <h3 class="text-base font-semibold text-text-primary">What you’ll complete</h3>
              <p class="mt-0.5 text-sm text-text-muted">Five steps; most take under fifteen minutes.</p>
            </div>
          </div>
          <ul class="mt-6 space-y-4">
            <li class="flex gap-3">
              <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-success" aria-hidden="true" />
              <div>
                <div class="text-sm font-semibold text-text-primary">Applicant</div>
                <p class="mt-1 text-sm leading-relaxed text-text-muted">Who is being verified and their identification details.</p>
              </div>
            </li>
            <li class="flex gap-3">
              <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-text-muted" aria-hidden="true" />
              <div>
                <div class="text-sm font-semibold text-text-primary">Qualification (incl. documents)</div>
                <p class="mt-1 text-sm leading-relaxed text-text-muted">
                  Add each qualification in the workspace; your NRC or passport copy is handled on the Applicant step.
                </p>
              </div>
            </li>
            <li class="flex gap-3">
              <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-text-muted" aria-hidden="true" />
              <div>
                <div class="text-sm font-semibold text-text-primary">Declarations</div>
                <p class="mt-1 text-sm leading-relaxed text-text-muted">Declarations and any required consent uploads.</p>
              </div>
            </li>
            <li class="flex gap-3">
              <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-text-muted" aria-hidden="true" />
              <div>
                <div class="text-sm font-semibold text-text-primary">Payment</div>
                <p class="mt-1 text-sm leading-relaxed text-text-muted">
                  Invoice and fee payment before your application is automatically submitted for processing.
                </p>
              </div>
            </li>
          </ul>
        </div>

        <div class="rounded-2xl border border-brand/20 bg-brand/5 p-6 sm:p-7">
          <div class="flex items-start gap-3">
            <Shield class="mt-0.5 h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
            <div>
              <div class="text-sm font-semibold text-text-primary">Secure submission</div>
              <p class="mt-2 text-sm leading-relaxed text-text-muted">
                Information you provide is used only for qualification verification in line with ZAQA processes.
              </p>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </ApplicantLayout>
</template>
