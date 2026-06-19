<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import ApplicantApplicationPaymentPanel from '@/Components/Applicant/ApplicantApplicationPaymentPanel.vue'
import InputError from '@/Components/InputError.vue'
import { CheckCircle2, FilePlus, Layers, ListChecks, CreditCard, ScrollText } from 'lucide-vue-next'
import Swal from 'sweetalert2'

const props = defineProps<{
  application: any
  applicant: any
  countries: Array<{ id: number; name: string }>
  review_missing_items: string[]
  initial_step?: string | null
  declarationsCopy?: Record<string, string>
  bankTransfer?: Record<string, any>
  cgrate?: Record<string, any>
}>()

type StepKey = 'application_info' | 'qualification_records' | 'review' | 'consent' | 'payment'

const steps: Array<{ key: StepKey; label: string; icon: any }> = [
  { key: 'application_info', label: 'Application information', icon: ScrollText },
  { key: 'qualification_records', label: 'Qualification records', icon: Layers },
  { key: 'review', label: 'Review & submit', icon: ListChecks },
  { key: 'consent', label: 'Consent / declaration', icon: CheckCircle2 },
  { key: 'payment', label: 'Payment', icon: CreditCard },
]

const activeStep = ref<StepKey>((props.initial_step as StepKey) || 'application_info')

watch(
  () => props.initial_step,
  (v) => {
    if (v && steps.some((s) => s.key === v)) activeStep.value = v as StepKey
  },
)

const infoForm = useForm({
  institution_reference: props.application?.metadata?.institution_reference ?? '',
  notification_contact_mode: props.application?.metadata?.notification_contact_mode ?? 'applicant_account',
  notification_contact_email: props.application?.metadata?.notification_contact_email ?? '',
})

const declarationsForm = useForm({
  accept_terms: !!props.application?.wizard_declarations?.terms_accepted_at,
  confirm_information_correct: !!props.application?.wizard_declarations?.information_confirmed_at,
})

watch(
  () => declarationsForm.accept_terms,
  (accepted) => {
    declarationsForm.confirm_information_correct = accepted
  },
)

const qualifications = computed(() => props.application?.qualifications ?? [])

const consentInstitutionSummary = computed(() => ({
  institutionName:
    (props.applicant?.institution_profile?.institution_name ?? '').toString().trim() ||
    (props.applicant?.name ?? '').toString().trim() ||
    'Your institution',
  applicationReference: (props.application?.application_number ?? '—').toString(),
  qualificationCount: qualifications.value.length,
  institutionReference: (props.application?.metadata?.institution_reference ?? '').toString().trim(),
}))

const reviewSummary = computed(() => {
  const list = qualifications.value
  const foreign = list.filter((q: { is_foreign_qualification?: boolean }) => !!q.is_foreign_qualification).length

  return {
    total: list.length,
    local: list.length - foreign,
    foreign,
  }
})
const applicationLocked = computed(() => props.application?.payment_satisfied === true)
const paymentBlocked = computed(() => props.review_missing_items.length > 0)
const declarationsComplete = computed(
  () =>
    !!props.application?.wizard_declarations?.terms_accepted_at &&
    !!props.application?.wizard_declarations?.information_confirmed_at,
)
const paymentReady = computed(() => declarationsComplete.value && !paymentBlocked.value)

function goToStep(step: StepKey) {
  activeStep.value = step
  router.visit(`/applicant/applications/multiple/${props.application.id}/edit?step=${step}`, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function saveApplicationInfo() {
  infoForm.patch(`/applicant/applications/multiple/${props.application.id}`, {
    preserveScroll: true,
  })
}

function saveDeclarations() {
  declarationsForm.patch(`/applicant/applications/multiple/${props.application.id}/wizard-declarations`, {
    preserveScroll: true,
    onSuccess: () => goToStep('payment'),
  })
}

function escapeHtml(unsafe: string): string {
  return unsafe
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

function openTermsModal() {
  const title = (props.declarationsCopy?.terms_title ?? 'Terms and conditions').toString()
  const body = (props.declarationsCopy?.terms_body ?? '').toString()
  void Swal.fire({
    icon: 'info',
    titleText: title,
    html: `<div class="text-left whitespace-pre-wrap text-sm leading-relaxed text-text-primary max-h-[60vh] overflow-auto">${escapeHtml(body)}</div>`,
    confirmButtonText: 'Close',
    confirmButtonColor: '#0076BD',
    width: 860,
  })
}
</script>

<template>
  <ApplicantLayout container-max-width-class="max-w-6xl">
    <template #pageHeader>
      <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Multiple Applications</p>
          <h1 class="text-2xl font-semibold tracking-tight text-text-primary">{{ application.application_number }}</h1>
        </div>
        <Link :href="`/applicant/applications/${application.id}`" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          View application
        </Link>
      </div>
    </template>

    <nav class="mb-6 flex flex-wrap gap-2">
      <button
        v-for="step in steps"
        :key="step.key"
        type="button"
        class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors"
        :class="
          activeStep === step.key
            ? 'border-brand/20 bg-brand/10 text-brand'
            : 'border-border bg-surface text-text-muted hover:bg-surface-muted'
        "
        @click="goToStep(step.key)"
      >
        <component :is="step.icon" class="h-3.5 w-3.5" aria-hidden="true" />
        {{ step.label }}
      </button>
    </nav>

    <section v-if="activeStep === 'application_info'" class="rounded-2xl border border-border bg-surface p-6">
      <h2 class="text-lg font-semibold text-text-primary">Application information</h2>
      <p class="mt-1 text-sm text-text-muted">Optional reference note and notification contact for this submission.</p>
      <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="text-sm font-medium">Institution reference (optional)</label>
          <input v-model="infoForm.institution_reference" type="text" class="zaqa-input" :disabled="applicationLocked" />
          <InputError :message="infoForm.errors.institution_reference" />
        </div>
        <div class="sm:col-span-2">
          <label class="text-sm font-medium">Notification email (optional)</label>
          <input v-model="infoForm.notification_contact_email" type="email" class="zaqa-input" :disabled="applicationLocked" />
          <InputError :message="infoForm.errors.notification_contact_email" />
        </div>
      </div>
      <div class="mt-6 flex justify-end gap-3">
        <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2" :disabled="infoForm.processing || applicationLocked" @click="saveApplicationInfo">
          Save & continue
        </button>
      </div>
    </section>

    <section v-else-if="activeStep === 'qualification_records'" class="rounded-2xl border border-border bg-surface p-6">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-lg font-semibold text-text-primary">Qualification holders / qualification records</h2>
          <p class="mt-1 text-sm text-text-muted">Add each qualification record with its own holder details and documents.</p>
        </div>
        <Link
          v-if="!applicationLocked"
          :href="`/applicant/applications/multiple/${application.id}/qualifications/create`"
          class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm"
        >
          <FilePlus class="h-4 w-4" aria-hidden="true" />
          Add qualification record
        </Link>
      </div>

      <div v-if="qualifications.length === 0" class="mt-6 rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted">
        No qualification records yet. Add the first qualification holder record to continue.
      </div>

      <div v-else class="mt-6 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-text-muted">
              <th class="px-3 py-2">Holder name</th>
              <th class="px-3 py-2">Qualification</th>
              <th class="px-3 py-2">NRC / Passport</th>
              <th class="px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in qualifications" :key="q.id" class="border-b border-border/70">
              <td class="px-3 py-3 font-medium">{{ q.qualification_holder_name || '—' }}</td>
              <td class="px-3 py-3">{{ q.title_of_qualification }}</td>
              <td class="px-3 py-3 font-mono text-xs">{{ q.nrc_passport_number || '—' }}</td>
              <td class="px-3 py-3">
                <Link
                  :href="`/applicant/applications/multiple/${application.id}/qualifications/${q.id}/edit`"
                  class="zaqa-btn zaqa-btn-secondary px-3 py-1.5 text-xs"
                >
                  Edit
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-6 flex justify-between">
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2" @click="goToStep('application_info')">Back</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2" @click="goToStep('review')">Continue to review</button>
      </div>
    </section>

    <section v-else-if="activeStep === 'review'" class="rounded-2xl border border-border bg-surface p-6">
      <h2 class="text-lg font-semibold text-text-primary">Review & submit</h2>
      <p class="mt-1 text-sm text-text-muted">Confirm all qualification records are complete before declarations and payment.</p>

      <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
          <div class="text-xs font-medium uppercase tracking-wide text-text-muted">Total qualification records</div>
          <div class="mt-1 text-2xl font-semibold text-text-primary">{{ reviewSummary.total }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
          <div class="text-xs font-medium uppercase tracking-wide text-text-muted">Local qualifications</div>
          <div class="mt-1 text-2xl font-semibold text-text-primary">{{ reviewSummary.local }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted/40 px-4 py-4">
          <div class="text-xs font-medium uppercase tracking-wide text-text-muted">Foreign qualifications</div>
          <div class="mt-1 text-2xl font-semibold text-text-primary">{{ reviewSummary.foreign }}</div>
        </div>
      </div>

      <div v-if="qualifications.length > 0" class="mt-5 overflow-x-auto rounded-xl border border-border">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted/50 text-left text-xs uppercase tracking-wide text-text-muted">
            <tr>
              <th class="px-4 py-3">Qualification holder</th>
              <th class="px-4 py-3">Qualification</th>
              <th class="px-4 py-3">Awarding institution</th>
              <th class="px-4 py-3">Type</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in qualifications" :key="q.id" class="border-t border-border/70">
              <td class="px-4 py-3 font-medium">{{ q.qualification_holder_name || '—' }}</td>
              <td class="px-4 py-3">{{ q.title_of_qualification || '—' }}</td>
              <td class="px-4 py-3">{{ q.awarding_institution_name || q.awarding_institution_name_other || '—' }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                  :class="
                    q.is_foreign_qualification
                      ? 'bg-violet-100 text-violet-800'
                      : 'bg-emerald-100 text-emerald-800'
                  "
                >
                  {{ q.is_foreign_qualification ? 'Foreign' : 'Local' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="review_missing_items.length" class="mt-5 rounded-xl border border-amber-300/40 bg-amber-50 px-4 py-4">
        <p class="text-sm font-semibold text-amber-900">Complete the following before payment:</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
          <li v-for="(item, idx) in review_missing_items" :key="idx">{{ item }}</li>
        </ul>
      </div>
      <div v-else class="mt-5 rounded-xl border border-success/25 bg-success/5 px-4 py-4 text-sm text-success">
        All required qualification records and documents appear complete.
      </div>

      <div class="mt-6 flex justify-between">
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2" @click="goToStep('qualification_records')">Back</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2" @click="goToStep('consent')">Continue to declarations</button>
      </div>
    </section>

    <section v-else-if="activeStep === 'consent'" class="px-1 py-2 sm:px-0">
      <div class="mx-auto w-full max-w-3xl">
        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm ring-1 ring-black/[0.04]">
          <div class="border-b border-border px-6 py-5 sm:px-8">
            <h2 class="text-xl font-semibold tracking-tight text-text-primary sm:text-2xl">Confirm your application</h2>
            <p class="mt-2 text-sm leading-relaxed text-text-muted">
              Please review the details below and confirm that ZAQA may process this multiple qualification submission.
            </p>
          </div>

          <div class="border-b border-border bg-surface-muted/25 px-6 py-4 sm:px-8">
            <p class="text-sm text-text-muted">
              You are submitting this application on behalf of the qualification holders listed in this application reference.
            </p>

            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-6">
              <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Institution</dt>
                <dd class="mt-1 truncate text-sm font-semibold text-text-primary">
                  {{ consentInstitutionSummary.institutionName }}
                </dd>
              </div>
              <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Application reference</dt>
                <dd class="mt-1 truncate font-mono text-sm font-semibold text-text-primary">
                  {{ consentInstitutionSummary.applicationReference }}
                </dd>
              </div>
              <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification records</dt>
                <dd class="mt-1 text-sm font-semibold text-text-primary">
                  {{ consentInstitutionSummary.qualificationCount }}
                </dd>
              </div>
            </dl>

            <p v-if="consentInstitutionSummary.institutionReference" class="mt-4 text-sm text-text-muted">
              Institution reference:
              <span class="font-semibold text-text-primary">{{ consentInstitutionSummary.institutionReference }}</span>
            </p>
          </div>

          <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <h3 class="text-sm font-semibold text-brand">Consent and verification authorization</h3>
              <button
                type="button"
                class="shrink-0 text-left text-sm font-semibold text-brand hover:underline sm:text-right"
                @click="openTermsModal"
              >
                View full consent terms
              </button>
            </div>

            <p class="mt-4 text-sm leading-relaxed text-text-primary">
              By continuing, I confirm on behalf of our institution and the qualification holders in this application that:
            </p>

            <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-text-muted">
              <li class="flex gap-2.5">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                <span
                  >Our institution is authorized to submit verification applications on behalf of the qualification holders
                  included in this application reference.</span
                >
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                <span
                  >We authorize ZAQA to verify the qualification information submitted for each qualification holder in this
                  application.</span
                >
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                <span
                  >We confirm that the information and documents provided for each qualification holder are accurate and complete
                  to the best of our knowledge.</span
                >
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                <span
                  >We understand that ZAQA may use the submitted information for verification and evaluation purposes for each
                  qualification record.</span
                >
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                <span
                  >We understand that incomplete, inaccurate, or misleading information may delay processing or lead to rejection
                  of any qualification record.</span
                >
              </li>
              <li class="flex gap-2.5">
                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand/70" aria-hidden="true" />
                <span
                  >We understand that our account, date, and time of confirmation may be recorded as part of the application audit
                  trail.</span
                >
              </li>
            </ul>
          </div>

          <div class="border-t border-border px-6 py-5 sm:px-8">
            <h3 class="text-sm font-semibold text-text-primary">Your confirmation</h3>

            <div class="mt-4 space-y-3">
              <label
                class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-surface-muted/30 px-4 py-3.5 transition-colors hover:bg-surface-muted/50"
              >
                <input
                  v-model="declarationsForm.accept_terms"
                  type="checkbox"
                  class="mt-0.5 h-4 w-4 shrink-0 rounded border-border text-brand focus:ring-brand/30"
                  :disabled="applicationLocked"
                />
                <span class="text-sm leading-relaxed text-text-primary">
                  I consent to the verification terms above on behalf of the qualification holders in this application.
                </span>
              </label>
              <InputError :message="declarationsForm.errors.accept_terms" />
              <InputError :message="declarationsForm.errors.confirm_information_correct" />
            </div>
          </div>

          <div class="border-t border-border bg-surface-muted/30 px-6 py-3.5 sm:px-8">
            <p class="text-xs leading-relaxed text-text-muted">
              Your information is securely processed by ZAQA. You can review and edit your draft until payment is confirmed.
            </p>
          </div>
        </div>
      </div>

      <div class="mx-auto mt-6 flex max-w-3xl items-center justify-between gap-3">
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2" @click="goToStep('review')">Back</button>
        <div class="flex items-center gap-3">
          <span
            v-if="declarationsComplete"
            class="inline-flex items-center gap-2 rounded-full border border-success/20 bg-success/10 px-3 py-1 text-xs font-semibold text-success"
          >
            <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
            Confirmed
          </span>
          <button
            type="button"
            class="zaqa-btn zaqa-btn-primary px-4 py-2"
            :disabled="applicationLocked || declarationsForm.processing || !declarationsForm.accept_terms"
            @click="saveDeclarations"
          >
            Continue to payment
          </button>
        </div>
      </div>
    </section>

    <section v-else class="rounded-2xl border border-border bg-surface p-6">
      <h2 class="text-lg font-semibold text-text-primary">Payment</h2>
      <p class="mt-1 text-sm text-text-muted">One invoice covers all qualification records in this application reference.</p>

      <div class="mt-5">
        <ApplicantApplicationPaymentPanel
          :application="application"
          :bank-transfer="bankTransfer"
          :cgrate="cgrate"
          :payment-blocked="!paymentReady"
          blocked-step-label="Review & submit"
          @go-to-blocked-step="goToStep(paymentBlocked ? 'review' : 'consent')"
        />
      </div>

      <div class="mt-6 flex justify-between">
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2" @click="goToStep('consent')">Back</button>
      </div>
    </section>
  </ApplicantLayout>
</template>
