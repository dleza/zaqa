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

const profileHasIdentityUpload = computed(
  () => !!(props.applicant?.applicant_profile?.identity_document_uploaded_at ?? false),
)

function normalizeGender(value: any): 'male' | 'female' | '' {
  const v = (value ?? '').toString().trim().toLowerCase()
  if (!v) return ''
  if (v === 'm' || v === 'male') return 'male'
  if (v === 'f' || v === 'female') return 'female'
  return ''
}

function normalizeIdentityType(value: any): 'nrc' | 'passport' | '' {
  const v = (value ?? '').toString().trim().toLowerCase()
  if (!v) return ''
  if (v === 'nrc') return 'nrc'
  if (v === 'passport') return 'passport'
  return ''
}

const profileNrc = (props.applicant?.applicant_profile?.nrc_number ?? '').toString().trim()
const profilePassport = (props.applicant?.applicant_profile?.passport_number ?? '').toString().trim()
const profileGender = normalizeGender(props.applicant?.applicant_profile?.gender)
const profileIdentityType = normalizeIdentityType(props.applicant?.applicant_profile?.identity_type)

const defaultIdentityType: 'nrc' | 'passport' =
  (profileIdentityType === 'nrc' || profileIdentityType === 'passport')
    ? profileIdentityType
    : profileNrc
      ? 'nrc'
      : profilePassport
        ? 'passport'
        : 'nrc'

const identityNumberCache = ref<{ nrc: string; passport: string }>({
  nrc: profileNrc,
  passport: profilePassport,
})

const applicantAccountEmail = computed(() => (props.applicant?.email ?? '').toString().trim())

const form = useForm({
  service_type: 'verification',
  qualification_category: props.qualificationTypes[0]?.value ?? 'certificate',
  is_foreign: false,
  submitting_for: isInstitutionApplicant.value ? 'other' : 'self',
  subject_first_name: '',
  subject_other_names: '',
  subject_last_name: '',
  notification_contact_mode: 'applicant_account' as 'applicant_account' | 'additional_email',
  additional_notification_email: '',
  additional_notification_name: '',
  additional_notification_relationship: '',
  gender: profileGender,
  identity_type: defaultIdentityType as 'nrc' | 'passport',
  identity_number: identityNumberCache.value[defaultIdentityType] ?? '',
  identity_file: null as File | null,
})

watch(
  () => form.identity_type,
  (next, prev) => {
    if ((prev === 'nrc' || prev === 'passport') && prev !== next) {
      identityNumberCache.value[prev] = (form.identity_number ?? '').toString()
    }
    if (next === 'nrc' || next === 'passport') {
      form.identity_number = (identityNumberCache.value[next] ?? '').toString()
    }
    form.clearErrors()
  },
)

const identityNumberLabel = computed(() =>
  form.identity_type === 'passport' ? 'Passport number' : 'NRC number',
)
const identityUploadLabel = computed(() =>
  form.identity_type === 'passport' ? 'Upload passport copy' : 'Upload NRC copy',
)
const identityReplacementUploadLabel = computed(() =>
  form.identity_type === 'passport' ? 'Upload replacement passport copy' : 'Upload replacement NRC copy',
)
const identityDocumentKindLabel = computed(() =>
  form.identity_type === 'passport' ? 'passport' : 'NRC',
)

const showReplaceIdentityUpload = ref(false)

const canSubmit = computed(() => {
  if (form.processing) return false

  const genderOk = (form.gender ?? '').toString().trim() !== ''
  const idTypeOk = (form.identity_type ?? '').toString().trim() !== ''
  const idOk = (form.identity_number ?? '').toString().trim() !== ''

  if (form.submitting_for === 'other' || isInstitutionApplicant.value) {
    const firstOk = (form.subject_first_name ?? '').toString().trim() !== ''
    const lastOk = (form.subject_last_name ?? '').toString().trim() !== ''
    const fileOk = !!form.identity_file
    const notificationOk =
      form.notification_contact_mode !== 'additional_email'
      || (form.additional_notification_email ?? '').toString().trim() !== ''
    return firstOk && lastOk && genderOk && idTypeOk && idOk && fileOk && notificationOk
  }

  const fileOk = profileHasIdentityUpload.value || !!form.identity_file
  return genderOk && idTypeOk && idOk && fileOk
})

type StepKey = 'applicant' | 'qualification' | 'consent' | 'payment'
const activeStep = ref<StepKey>('applicant')
const steps = computed(() => [
  { key: 'applicant' as const, label: 'Applicant' },
  { key: 'qualification' as const, label: 'Qualification' },
  { key: 'consent' as const, label: 'Confirm' },
  { key: 'payment' as const, label: 'Payment' },
])

function submit() {
  form.post('/applicant/applications', { forceFormData: true })
}

const additionalEmailMatchesAccount = computed(() => {
  const additional = (form.additional_notification_email ?? '').toString().trim().toLowerCase()
  const account = applicantAccountEmail.value.toLowerCase()
  return additional !== '' && account !== '' && additional === account
})

watch(
  () => form.submitting_for,
  (val) => {
    showReplaceIdentityUpload.value = false
    if (val === 'self') {
      form.subject_first_name = ''
      form.subject_other_names = ''
      form.subject_last_name = ''
      form.notification_contact_mode = 'applicant_account'
      form.additional_notification_email = ''
      form.additional_notification_name = ''
      form.additional_notification_relationship = ''
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
              Your progress is saved as you move through each step.
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
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <div class="text-base font-semibold text-text-primary">Your details</div>
                <p class="mt-1 text-sm text-text-muted">Used for matching learner records and issuing certificates.</p>
              </div>
              <span v-if="profileHasIdentityUpload" class="zaqa-badge zaqa-badge-success shrink-0">Identity document on file</span>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
              <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">First name</div>
                <div class="mt-1 font-semibold text-text-primary">
                  {{ props.applicant?.applicant_profile?.first_name ?? '—' }}
                </div>
              </div>
              <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Other names</div>
                <div class="mt-1 font-semibold text-text-primary">
                  {{ props.applicant?.applicant_profile?.middle_name ?? '—' }}
                </div>
              </div>
              <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-text-muted">Last name</div>
                <div class="mt-1 font-semibold text-text-primary">
                  {{ props.applicant?.applicant_profile?.surname ?? '—' }}
                </div>
              </div>
            </div>

            <div v-if="(props.applicant?.applicant_type ?? '') === 'individual'" class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="text-sm font-medium text-text-primary">Gender</label>
                <select v-model="form.gender" class="zaqa-input">
                  <option value="" disabled>Select gender</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
                <InputError :message="(form.errors as any).gender" />
              </div>

              <div>
                <label class="text-sm font-medium text-text-primary">Identity type</label>
                <select v-model="form.identity_type" class="zaqa-input">
                  <option value="nrc">NRC</option>
                  <option value="passport">Passport</option>
                </select>
                <InputError :message="(form.errors as any).identity_type" />
              </div>

              <div class="sm:col-span-2">
                <label class="text-sm font-medium text-text-primary">{{ identityNumberLabel }}</label>
                <input v-model="form.identity_number" class="zaqa-input" autocomplete="off" />
                <InputError :message="(form.errors as any).identity_number" />
              </div>

              <div class="sm:col-span-2">
                <template v-if="profileHasIdentityUpload">
                  <div class="rounded-xl border border-success/25 bg-success/10 px-4 py-4 sm:px-5 sm:py-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                      <div class="flex items-start gap-3">
                        <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-success" aria-hidden="true" />
                        <div>
                          <div class="text-sm font-semibold text-text-primary">Identity document already on file</div>
                          <p class="mt-1 text-sm leading-relaxed text-text-muted">
                            We already have a copy of your {{ identityDocumentKindLabel }}/Passport document associated with your profile and it will be used for this application.
                          </p>
                          <p class="mt-2 text-xs text-text-muted">No further action is required unless you need to upload a replacement.</p>
                        </div>
                      </div>
                      <button
                        v-if="!showReplaceIdentityUpload"
                        type="button"
                        class="zaqa-btn zaqa-btn-secondary shrink-0 self-start px-4 py-2 text-sm"
                        @click="showReplaceIdentityUpload = true"
                      >
                        Replace document
                      </button>
                    </div>
                  </div>

                  <Transition
                    enter-active-class="transition-all duration-300 ease-out overflow-hidden"
                    enter-from-class="max-h-0 opacity-0"
                    enter-to-class="max-h-48 opacity-100"
                    leave-active-class="transition-all duration-200 ease-in overflow-hidden"
                    leave-from-class="max-h-48 opacity-100"
                    leave-to-class="max-h-0 opacity-0"
                  >
                    <div
                      v-if="showReplaceIdentityUpload"
                      class="mt-4 rounded-xl border border-border bg-surface px-4 py-4"
                    >
                      <div class="text-sm font-semibold text-text-primary">{{ identityReplacementUploadLabel }}</div>
                      <p class="mt-1 text-xs text-text-muted">Optional. Leave blank to keep using the document already on your profile.</p>
                      <div class="mt-3">
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
                  </Transition>
                </template>

                <div v-else class="rounded-xl border border-border bg-surface px-4 py-4">
                  <div class="text-sm font-semibold text-text-primary">
                    {{ identityUploadLabel }} <span class="text-danger">*</span>
                  </div>
                  <p class="mt-1 text-xs text-text-muted">Required for your first application.</p>
                  <div class="mt-3">
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
            </div>
          </div>

          <template v-else>
            <div class="sm:col-span-2">
              <div class="text-sm font-medium text-text-primary">Holder details</div>
              <p class="mt-1 text-xs text-text-muted">These details should match the holder’s NRC/Passport.</p>
            </div>

            <div>
              <label class="text-sm font-medium text-text-primary">First name</label>
              <input v-model="form.subject_first_name" class="zaqa-input" autocomplete="off" />
              <InputError :message="(form.errors as any).subject_first_name" />
            </div>
            <div>
              <label class="text-sm font-medium text-text-primary">Other names (optional)</label>
              <input v-model="form.subject_other_names" class="zaqa-input" autocomplete="off" />
              <InputError :message="(form.errors as any).subject_other_names" />
            </div>
            <div>
              <label class="text-sm font-medium text-text-primary">Last name</label>
              <input v-model="form.subject_last_name" class="zaqa-input" autocomplete="off" />
              <InputError :message="(form.errors as any).subject_last_name" />
            </div>
            <div>
              <label class="text-sm font-medium text-text-primary">Gender</label>
              <select v-model="form.gender" class="zaqa-input">
                <option value="" disabled>Select gender</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
              <InputError :message="(form.errors as any).gender" />
            </div>

            <div>
              <label class="text-sm font-medium text-text-primary">Identity type</label>
              <select v-model="form.identity_type" class="zaqa-input">
                <option value="nrc">NRC</option>
                <option value="passport">Passport</option>
              </select>
              <InputError :message="(form.errors as any).identity_type" />
            </div>
            <div>
              <label class="text-sm font-medium text-text-primary">{{ identityNumberLabel }}</label>
              <input v-model="form.identity_number" class="zaqa-input" autocomplete="off" />
              <InputError :message="(form.errors as any).identity_number" />
            </div>

            <div class="sm:col-span-2 rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
              <div class="text-sm font-semibold text-text-primary">Notification contact</div>
              <p class="mt-1 text-xs text-text-muted">
                Choose where important updates for this application should be sent. The authenticated applicant will always be able to view the application from their account.
              </p>

              <div class="mt-4 grid gap-3">
                <label
                  class="zaqa-radio-card"
                  :class="form.notification_contact_mode === 'applicant_account' ? 'zaqa-radio-card-active' : ''"
                >
                  <input
                    v-model="form.notification_contact_mode"
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
                  :class="form.notification_contact_mode === 'additional_email' ? 'zaqa-radio-card-active' : ''"
                >
                  <input
                    v-model="form.notification_contact_mode"
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
              <InputError :message="(form.errors as any).notification_contact_mode" class="mt-2" />

              <div v-if="form.notification_contact_mode === 'additional_email'" class="mt-4 space-y-3">
                <div>
                  <label class="text-sm font-medium text-text-primary">Additional recipient email</label>
                  <input v-model="form.additional_notification_email" type="email" class="zaqa-input" autocomplete="off" />
                  <p class="mt-1 text-xs text-text-muted">This does not create a portal account. The application will still remain under your account.</p>
                  <p v-if="additionalEmailMatchesAccount" class="mt-1 text-xs text-warning">
                    This matches your account email. Updates will only be sent once to your account address.
                  </p>
                  <InputError :message="(form.errors as any).additional_notification_email" />
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <label class="text-sm font-medium text-text-primary">Recipient name (optional)</label>
                    <input v-model="form.additional_notification_name" class="zaqa-input" autocomplete="off" />
                    <InputError :message="(form.errors as any).additional_notification_name" />
                  </div>
                  <div>
                    <label class="text-sm font-medium text-text-primary">Relationship (optional)</label>
                    <input v-model="form.additional_notification_relationship" class="zaqa-input" autocomplete="off" placeholder="e.g. parent, employer" />
                    <InputError :message="(form.errors as any).additional_notification_relationship" />
                  </div>
                </div>
              </div>
            </div>

            <div class="sm:col-span-2 rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
              <div class="text-sm font-semibold text-text-primary">{{ identityUploadLabel }} <span class="text-danger">*</span></div>
              <p class="mt-1 text-xs text-text-muted">Required when applying on behalf of someone else.</p>
              <div class="mt-3">
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
          </template>

          <input type="hidden" v-model="form.qualification_category" />

          <div class="flex flex-wrap gap-3 sm:col-span-2">
            <button
              type="submit"
              class="zaqa-btn zaqa-btn-primary h-12 px-8 text-base"
              :disabled="!canSubmit"
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
              <p class="mt-0.5 text-sm text-text-muted">Four steps; most take under fifteen minutes.</p>
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
                <div class="text-sm font-semibold text-text-primary">Confirm</div>
                <p class="mt-1 text-sm leading-relaxed text-text-muted">Confirm your details before payment.</p>
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
