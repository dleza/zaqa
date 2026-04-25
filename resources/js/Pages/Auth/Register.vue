<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'

type Mode = 'individual' | 'institution'
const mode = ref<Mode>('individual')

const individualForm = useForm({
  first_name: '',
  middle_name: '',
  surname: '',
  phone_primary: '',
  phone_secondary: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const institutionForm = useForm({
  institution_name: '',
  tpin: '',
  contact_person_name: '',
  phone_primary: '',
  phone_secondary: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const activeForm = computed(() => (mode.value === 'individual' ? individualForm : institutionForm))

const showPassword = ref(false)
const showConfirmPassword = ref(false)

const termsAccepted = ref(false)
const showTermsModal = ref(false)
const termsError = ref<string | null>(null)

function submit() {
  termsError.value = null
  if (!termsAccepted.value) {
    termsError.value = 'Please accept the Terms & Conditions to continue.'
    return
  }

  if (mode.value === 'individual') {
    individualForm.post('/register/individual')
    return
  }

  institutionForm.post('/register/institution')
}
</script>

<template>
  <GuestLayout max-width-class="max-w-lg sm:max-w-2xl lg:max-w-3xl">
    <h2 class="text-lg font-semibold">Create account</h2>
    <p class="mt-1 text-sm text-text-muted">Register as an individual applicant or an institution.</p>

    <div class="mt-6 flex gap-2">
      <button
        type="button"
        class="flex-1 rounded-lg px-3 py-2 text-sm font-medium"
        :class="mode === 'individual' ? 'bg-brand text-brand-foreground' : 'border border-border bg-surface text-text-primary hover:bg-surface-muted'"
        @click="mode = 'individual'"
      >
        Individual
      </button>
      <button
        type="button"
        class="flex-1 rounded-lg px-3 py-2 text-sm font-medium"
        :class="mode === 'institution' ? 'bg-brand text-brand-foreground' : 'border border-border bg-surface text-text-primary hover:bg-surface-muted'"
        @click="mode = 'institution'"
      >
        Institution
      </button>
    </div>

    <form class="mt-6 space-y-4" @submit.prevent="submit">
      <template v-if="mode === 'individual'">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="text-sm font-medium">First name</label>
            <input v-model="individualForm.first_name" class="zaqa-input" />
            <InputError :message="individualForm.errors.first_name" />
          </div>
          <div>
            <label class="text-sm font-medium">Middle name (optional)</label>
            <input v-model="individualForm.middle_name" class="zaqa-input" />
            <InputError :message="individualForm.errors.middle_name" />
          </div>
          <div class="sm:col-span-2">
            <label class="text-sm font-medium">Surname</label>
            <input v-model="individualForm.surname" class="zaqa-input" />
            <InputError :message="individualForm.errors.surname" />
          </div>
        </div>
      </template>

      <template v-else>
        <div>
          <label class="text-sm font-medium">Institution / organization name</label>
          <input
            v-model="institutionForm.institution_name"
            class="zaqa-input"
          />
          <InputError :message="institutionForm.errors.institution_name" />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="text-sm font-medium">TPIN</label>
            <input v-model="institutionForm.tpin" class="zaqa-input" />
            <InputError :message="institutionForm.errors.tpin" />
          </div>
          <div>
            <label class="text-sm font-medium">Contact person (optional)</label>
            <input
              v-model="institutionForm.contact_person_name"
              class="zaqa-input"
            />
            <InputError :message="institutionForm.errors.contact_person_name" />
          </div>
        </div>
      </template>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="text-sm font-medium">Primary phone</label>
          <input
            v-model="activeForm.phone_primary"
            class="zaqa-input"
            autocomplete="tel"
          />
          <InputError :message="activeForm.errors.phone_primary" />
        </div>
        <div class="sm:col-span-2">
          <label class="text-sm font-medium">Secondary phone (optional)</label>
          <input v-model="activeForm.phone_secondary" class="zaqa-input" />
          <InputError :message="activeForm.errors.phone_secondary" />
        </div>
      </div>

      <div>
        <label class="text-sm font-medium">Email</label>
        <input v-model="activeForm.email" type="email" class="zaqa-input" />
        <InputError :message="activeForm.errors.email" />
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="text-sm font-medium">Password</label>
          <div class="relative">
            <input
              v-model="activeForm.password"
              :type="showPassword ? 'text' : 'password'"
              class="zaqa-input pr-11"
              autocomplete="new-password"
            />
            <button
              type="button"
              class="absolute inset-y-0 right-2 inline-flex items-center justify-center rounded-md px-2 text-text-muted hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
              @click="showPassword = !showPassword"
            >
              <svg v-if="!showPassword" viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                <path
                  d="M2.25 12s3.75-7.5 9.75-7.5S21.75 12 21.75 12s-3.75 7.5-9.75 7.5S2.25 12 2.25 12Z"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <svg v-else viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                <path
                  d="M3 3l18 18"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M10.585 10.585A2.999 2.999 0 0 0 12 15a3 3 0 0 0 2.415-4.415"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M6.71 6.71C4.08 8.446 2.25 12 2.25 12s3.75 7.5 9.75 7.5c1.7 0 3.23-.39 4.56-1.03"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M9.53 4.77A9.34 9.34 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a16.46 16.46 0 0 1-3.27 4.46"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </button>
          </div>
          <InputError :message="activeForm.errors.password" />
        </div>
        <div>
          <label class="text-sm font-medium">Confirm password</label>
          <div class="relative">
            <input
              v-model="activeForm.password_confirmation"
              :type="showConfirmPassword ? 'text' : 'password'"
              class="zaqa-input pr-11"
              autocomplete="new-password"
            />
            <button
              type="button"
              class="absolute inset-y-0 right-2 inline-flex items-center justify-center rounded-md px-2 text-text-muted hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
              :aria-label="showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'"
              @click="showConfirmPassword = !showConfirmPassword"
            >
              <svg v-if="!showConfirmPassword" viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                <path
                  d="M2.25 12s3.75-7.5 9.75-7.5S21.75 12 21.75 12s-3.75 7.5-9.75 7.5S2.25 12 2.25 12Z"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <svg v-else viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                <path
                  d="M3 3l18 18"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M10.585 10.585A2.999 2.999 0 0 0 12 15a3 3 0 0 0 2.415-4.415"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M6.71 6.71C4.08 8.446 2.25 12 2.25 12s3.75 7.5 9.75 7.5c1.7 0 3.23-.39 4.56-1.03"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M9.53 4.77A9.34 9.34 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a16.46 16.46 0 0 1-3.27 4.46"
                  stroke="currentColor"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <div class="rounded-lg border border-border bg-surface-muted p-4 text-sm">
        <label class="flex items-start gap-3">
          <input
            v-model="termsAccepted"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-border text-brand focus:ring-brand/25"
          />
          <span class="leading-relaxed">
            I have read and accept the
            <button
              type="button"
              class="zaqa-link inline"
              @click="showTermsModal = true"
            >
              Terms & Conditions
            </button>
            .
          </span>
        </label>
        <p v-if="termsError" class="mt-2 text-xs text-danger">
          {{ termsError }}
        </p>
      </div>

      <button
        type="submit"
        class="zaqa-btn zaqa-btn-primary w-full"
        :disabled="activeForm.processing"
      >
        Create account
      </button>
    </form>

    <div
      v-if="showTermsModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Terms and Conditions"
      @keydown.esc="showTermsModal = false"
    >
      <button
        type="button"
        class="absolute inset-0 bg-black/60"
        aria-label="Close terms modal"
        @click="showTermsModal = false"
      />
      <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl border border-border bg-surface shadow-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div>
            <div class="text-sm font-semibold text-text-primary">ZAQA Portal — Terms & Conditions</div>
            <div class="mt-1 text-xs text-text-muted">Effective date: {{ new Date().toLocaleDateString() }}</div>
          </div>
          <button
            type="button"
            class="zaqa-btn zaqa-btn-secondary px-3 py-2 text-xs"
            @click="showTermsModal = false"
          >
            Close
          </button>
        </div>

        <div class="max-h-[70vh] overflow-auto px-5 py-4 text-sm leading-relaxed text-text-primary">
          <p class="text-text-muted">
            These Terms & Conditions govern your use of the Zambia Qualifications Authority (ZAQA) Qualification Verification
            Portal.
          </p>

          <div class="mt-4 space-y-3">
            <section>
              <div class="font-semibold">1. Purpose of the portal</div>
              <p class="mt-1 text-text-muted">
                This portal is used to submit qualification verification applications, upload supporting documents, track
                application progress, and receive outcomes where applicable.
              </p>
            </section>

            <section>
              <div class="font-semibold">2. Accuracy of information</div>
              <p class="mt-1 text-text-muted">
                You are responsible for ensuring that all information and documents you submit are accurate, complete, and
                belong to the correct applicant. Submitting false or misleading information may lead to rejection and may be
                subject to further action under applicable laws and policies.
              </p>
            </section>

            <section>
              <div class="font-semibold">3. Document handling and security</div>
              <p class="mt-1 text-text-muted">
                Uploaded files are processed and stored securely. Access to your documents is restricted to authorized
                personnel for verification and operational purposes.
              </p>
            </section>

            <section>
              <div class="font-semibold">4. Privacy</div>
              <p class="mt-1 text-text-muted">
                ZAQA processes personal information for qualification verification and related services. Use of this portal
                indicates your consent to such processing for the stated purposes.
              </p>
            </section>

            <section>
              <div class="font-semibold">5. Availability</div>
              <p class="mt-1 text-text-muted">
                ZAQA may update, suspend, or maintain the portal to ensure security and reliability. Temporary interruptions
                may occur.
              </p>
            </section>

            <section>
              <div class="font-semibold">6. Acceptance</div>
              <p class="mt-1 text-text-muted">
                By ticking “I accept”, you confirm you understand these terms and agree to be bound by them when using the
                portal.
              </p>
            </section>
          </div>
        </div>

        <div class="flex flex-col gap-3 border-t border-border bg-surface-muted px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs text-text-muted">
            You must accept to create an account.
          </p>
          <div class="flex gap-2">
            <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="showTermsModal = false">
              Cancel
            </button>
            <button
              type="button"
              class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm"
              @click="
                termsAccepted = true;
                showTermsModal = false;
              "
            >
              Accept terms
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-6 text-center text-sm">
      <Link href="/login" class="zaqa-link">Already have an account? Log in</Link>
    </div>
  </GuestLayout>
</template>

