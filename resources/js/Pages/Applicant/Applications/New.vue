<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import WizardStepper from '@/Components/WizardStepper.vue'

const props = defineProps<{
  qualificationTypes: Array<{ value: string; label: string }>
  applicant: any
}>()

const selfNrc = computed(() => (props.applicant?.applicant_profile?.nrc_number ?? '').toString().trim())
const selfPassport = computed(() => (props.applicant?.applicant_profile?.passport_number ?? '').toString().trim())

const selfMissingId = computed(() => {
  return selfNrc.value.length === 0 && selfPassport.value.length === 0
})

const form = useForm({
  service_type: 'verification',
  qualification_category: props.qualificationTypes[0]?.value ?? 'certificate',
  is_foreign: false,
  submitting_for: 'self',
  subject_full_name: '',
  subject_email: '',
  subject_phone: '',
  subject_nrc_number: '',
  subject_passport_number: '',
})

type StepKey = 'applicant' | 'qualification' | 'documents' | 'consent' | 'review'
const activeStep = ref<StepKey>('applicant')
const steps = computed(() => [
  { key: 'applicant' as const, label: 'Applicant' },
  { key: 'qualification' as const, label: 'Qualification' },
  { key: 'documents' as const, label: 'Documents' },
  { key: 'consent' as const, label: 'Consent' },
  { key: 'review' as const, label: 'Review & submit' },
])

function submit() {
  form.post('/applicant/applications')
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
</script>

<template>
  <ApplicantLayout>
    <template #pageHeader>
      <div class="zaqa-wizard-shell">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 class="text-2xl font-semibold tracking-tight text-text-primary">New application</h1>
            <p class="mt-1 text-sm text-text-muted">Start a verification application.</p>
          </div>

          <Link href="/applicant/applications" class="zaqa-btn zaqa-btn-secondary px-3 text-sm">
            Back
          </Link>
        </div>

        <div class="mt-4">
          <WizardStepper :steps="steps" :active-key="activeStep" />
        </div>
      </div>
    </template>

    <div class="zaqa-wizard-shell">
      <section class="zaqa-card">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-base font-semibold text-text-primary">Applicant information</h2>
            <p class="mt-1 text-sm text-text-muted">
              This verification application can be submitted as you, or on behalf of someone else.
            </p>
          </div>
          <div class="hidden rounded-full border border-brand/20 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand sm:block">
            Step 1
          </div>
        </div>

        <form class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
          <div class="sm:col-span-2">
            <div class="text-sm font-medium text-text-primary">Submitting as</div>
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
              <label class="zaqa-radio-card" :class="form.submitting_for === 'self' ? 'zaqa-radio-card-active' : ''">
                <input v-model="form.submitting_for" type="radio" value="self" class="mt-1 rounded border-border text-brand focus:ring-brand/25" />
                <div>
                  <div class="text-sm font-semibold text-text-primary">Myself (recommended)</div>
                  <div class="mt-1 text-xs text-text-muted">We’ll use your authenticated profile details where available.</div>
                </div>
              </label>
              <label class="zaqa-radio-card" :class="form.submitting_for === 'other' ? 'zaqa-radio-card-active' : ''">
                <input v-model="form.submitting_for" type="radio" value="other" class="mt-1 rounded border-border text-brand focus:ring-brand/25" />
                <div>
                  <div class="text-sm font-semibold text-text-primary">On behalf of someone</div>
                  <div class="mt-1 text-xs text-text-muted">Enter the verification subject’s biodata below.</div>
                </div>
              </label>
            </div>
            <InputError :message="(form.errors as any).submitting_for" />
          </div>

          <div v-if="form.submitting_for === 'self'" class="sm:col-span-2 rounded-xl border border-border bg-surface-muted p-4">
            <div class="text-sm font-semibold text-text-primary">Your profile (auto-populated)</div>
            <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
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
              <div class="text-sm">
                <div class="text-xs font-semibold text-text-muted">NRC number</div>
                <div class="text-text-primary">{{ selfNrc.length ? selfNrc : '—' }}</div>
              </div>
              <div class="text-sm">
                <div class="text-xs font-semibold text-text-muted">Passport number</div>
                <div class="text-text-primary">{{ selfPassport.length ? selfPassport : '—' }}</div>
              </div>
            </div>

            <div
              v-if="selfMissingId && (props.applicant?.applicant_type ?? '') === 'individual'"
              class="mt-4 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
            >
              To submit as yourself, you must first add an <span class="font-semibold">NRC or Passport number</span> to your profile.
              <Link href="/applicant/profile" class="ml-1 underline underline-offset-2">
                Update profile
              </Link>
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

            <div class="sm:col-span-2 text-xs text-text-muted">
              You must provide <span class="font-semibold text-text-primary">either NRC or Passport</span> (or both).
            </div>
          </template>

          <input type="hidden" v-model="form.qualification_category" />

          <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button type="submit" class="zaqa-btn zaqa-btn-primary" :disabled="form.processing">
              Continue to Step 2
            </button>
          </div>
        </form>
      </section>
    </div>
  </ApplicantLayout>
</template>

