<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import { ArrowRight, Building2, Eye, EyeOff, Hash, Lock, Mail, Phone, ShieldCheck, User } from 'lucide-vue-next'
import AuthTopBar from '@/Components/AuthTopBar.vue'

type Mode = 'individual' | 'institution'
const mode = ref<Mode>('individual')

type ContactMethod = 'email' | 'phone'

const mounted = ref(false)

const individualForm = useForm({
  first_name: '',
  middle_name: '',
  surname: '',
  login_identifier_type: 'email' as ContactMethod,
  phone_primary: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const institutionForm = useForm({
  institution_name: '',
  tpin: '',
  contact_person_name: '',
  login_identifier_type: 'email' as ContactMethod,
  phone_primary: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const activeForm = computed(() => (mode.value === 'individual' ? individualForm : institutionForm))
const contactMethod = computed<ContactMethod>({
  get: () => (activeForm.value.login_identifier_type as ContactMethod) ?? 'email',
  set: (value) => {
    activeForm.value.login_identifier_type = value
  },
})

function setContactMethod(method: ContactMethod) {
  contactMethod.value = method

  // Keep the UI clean by clearing the non-primary field (and its errors).
  if (method === 'email') {
    activeForm.value.phone_primary = ''
    activeForm.value.clearErrors('phone_primary')
    return
  }

  activeForm.value.email = ''
  activeForm.value.clearErrors('email')
}

const showPassword = ref(false)
const showConfirmPassword = ref(false)

const termsAccepted = ref(false)
const showTermsModal = ref(false)
const termsError = ref<string | null>(null)

onMounted(() => {
  mounted.value = true
})

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
  <GuestLayout :card="false" max-width-class="max-w-none" content-padding-class="p-0" :hide-header="true" :center-content="false">
    <div class="relative min-h-screen w-full">
      <AuthTopBar right-href="/login" right-label="Log in" />

      <div class="grid w-full lg:min-h-[calc(100vh-56px)] lg:grid-cols-2">
        <!-- Desktop branding panel (hidden on mobile) -->
        <aside class="relative hidden overflow-hidden bg-gradient-to-br from-[#061a3a] via-[#0b3a66] to-[#092a48] px-10 py-10 text-white sm:px-14 sm:py-14 lg:block lg:px-16 lg:py-16">
          <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-20 -left-20 h-72 w-72 rounded-full bg-white/10 blur-3xl" />
            <div class="absolute -bottom-28 -right-20 h-[26rem] w-[26rem] rounded-full bg-amber-400/10 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_18%,rgba(255,255,255,0.18),transparent_55%),radial-gradient(circle_at_80%_70%,rgba(255,255,255,0.12),transparent_55%)]" />
            <div class="absolute right-10 top-16 h-20 w-20 rounded-2xl bg-white/5 ring-1 ring-white/10" />
            <div class="absolute right-20 top-24 h-2 w-2 rounded-full bg-white/30" />
            <div class="absolute right-28 top-28 h-2 w-2 rounded-full bg-white/20" />
            <div class="absolute inset-0 opacity-[0.08] [background-image:radial-gradient(rgba(255,255,255,0.8)_1px,transparent_1px)] [background-size:18px_18px]" />
          </div>

          <div class="relative">
            <div class="mt-10">
              <div class="text-xs font-semibold uppercase tracking-wider text-white/55">Zambia Qualifications Authority</div>
              <h1 class="mt-3 text-5xl font-semibold tracking-tight">Create account</h1>
              <div class="mt-4 h-0.5 w-10 rounded-full bg-amber-300/80" />
              <p class="mt-6 max-w-md text-sm leading-relaxed text-white/75">
                Register to submit verification applications and track your qualification verification progress securely.
              </p>
            </div>

            <div class="mt-10 space-y-5">
              <div class="flex gap-4">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/15">
                  <ShieldCheck class="h-5 w-5 text-white/90" aria-hidden="true" />
                </span>
                <div>
                  <div class="text-sm font-semibold">Trusted & Official</div>
                  <div class="mt-1 text-xs leading-relaxed text-white/70">
                    Official ZAQA verification workflow with secure access and audit trails.
                  </div>
                </div>
              </div>
              <div class="flex gap-4">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/15">
                  <ArrowRight class="h-5 w-5 text-white/90" aria-hidden="true" />
                </span>
                <div>
                  <div class="text-sm font-semibold">Fast & Reliable</div>
                  <div class="mt-1 text-xs leading-relaxed text-white/70">Start an application in minutes and track it end-to-end.</div>
                </div>
              </div>
              <div class="flex gap-4">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/15">
                  <Lock class="h-5 w-5 text-white/90" aria-hidden="true" />
                </span>
                <div>
                  <div class="text-sm font-semibold">Secure & Protected</div>
                  <div class="mt-1 text-xs leading-relaxed text-white/70">Your documents and data are protected.</div>
                </div>
              </div>
            </div>
          </div>
        </aside>

        <section class="relative overflow-hidden bg-white px-4 py-6 sm:bg-[#eef4fb] sm:px-10 sm:py-10 lg:px-16 lg:py-12">
          <!-- Desktop/tablet decorative background (hidden on small screens) -->
          <div aria-hidden="true" class="pointer-events-none absolute inset-0 hidden sm:block">
            <div class="absolute -top-28 left-1/2 h-[46rem] w-[46rem] -translate-x-1/2 rounded-full bg-brand/18 blur-3xl" />
            <div class="absolute -bottom-28 -right-28 h-[40rem] w-[40rem] rounded-full bg-accent/10 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_65%_35%,rgba(11,58,102,0.08),transparent_55%)]" />
          </div>

          <div class="relative flex h-full flex-col">
            <div class="flex flex-1 items-center justify-center py-2 sm:py-6 lg:py-10">
              <div
                class="w-full max-w-[780px] rounded-2xl border border-border/70 bg-white px-5 py-7 shadow-sm sm:rounded-3xl sm:bg-white/70 sm:px-10 sm:py-10 sm:shadow-2xl sm:backdrop-blur-sm"
              >
                <div class="transition-all duration-500 ease-out" :class="mounted ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0'">
      <div>
        <h2 class="text-2xl font-semibold tracking-tight text-text-primary">Create account</h2>
        <p class="mt-2 text-sm text-text-muted">Register as an individual applicant or an institution.</p>
      </div>

      <div class="mt-6 h-px bg-border/60" />

      <div class="mt-6 sm:mt-8">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Account type</div>
        <div class="relative mt-3 flex rounded-full border border-border bg-surface-muted p-1">
          <span
            class="absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-surface shadow-sm transition-transform duration-300"
            :class="mode === 'institution' ? 'translate-x-full' : 'translate-x-0'"
            aria-hidden="true"
          />
          <button
            type="button"
            class="relative z-10 inline-flex flex-1 items-center justify-center gap-2 rounded-full px-3 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
            :class="mode === 'individual' ? 'text-text-primary' : 'text-text-muted hover:text-text-primary'"
            @click="mode = 'individual'"
          >
            <User class="h-4 w-4" aria-hidden="true" />
            Individual
          </button>
          <button
            type="button"
            class="relative z-10 inline-flex flex-1 items-center justify-center gap-2 rounded-full px-3 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
            :class="mode === 'institution' ? 'text-text-primary' : 'text-text-muted hover:text-text-primary'"
            @click="mode = 'institution'"
          >
            <Building2 class="h-4 w-4" aria-hidden="true" />
            Institution
          </button>
        </div>
      </div>

      <form class="mt-6 space-y-6 sm:mt-8" @submit.prevent="submit">
        <template v-if="mode === 'individual'">
          <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">First name</label>
              <div class="relative">
                <User class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input v-model="individualForm.first_name" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="given-name" />
              </div>
              <InputError :message="individualForm.errors.first_name" />
            </div>
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Middle name (optional)</label>
              <div class="relative">
                <User class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input v-model="individualForm.middle_name" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="additional-name" />
              </div>
              <InputError :message="individualForm.errors.middle_name" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Surname</label>
              <div class="relative">
                <User class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input v-model="individualForm.surname" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="family-name" />
              </div>
              <InputError :message="individualForm.errors.surname" />
            </div>
          </div>
        </template>

        <template v-else>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Institution / organization name</label>
            <div class="relative">
              <Building2 class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="institutionForm.institution_name" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="organization" />
            </div>
            <InputError :message="institutionForm.errors.institution_name" />
          </div>

          <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">TPIN</label>
              <div class="relative">
                <Hash class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input v-model="institutionForm.tpin" class="zaqa-input h-12 rounded-lg pl-10" />
              </div>
              <InputError :message="institutionForm.errors.tpin" />
            </div>
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Contact person (optional)</label>
              <div class="relative">
                <User class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input v-model="institutionForm.contact_person_name" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="name" />
              </div>
              <InputError :message="institutionForm.errors.contact_person_name" />
            </div>
          </div>
        </template>

        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Contact method</div>
          <div class="relative mt-3 flex rounded-full border border-border bg-surface-muted p-1">
            <span
              class="absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-surface shadow-sm transition-transform duration-300"
              :class="contactMethod === 'phone' ? 'translate-x-full' : 'translate-x-0'"
              aria-hidden="true"
            />
            <button
              type="button"
              class="relative z-10 inline-flex flex-1 items-center justify-center gap-2 rounded-full px-3 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
              :class="contactMethod === 'email' ? 'text-text-primary' : 'text-text-muted hover:text-text-primary'"
              @click="setContactMethod('email')"
            >
              <Mail class="h-4 w-4" aria-hidden="true" />
              Email
            </button>
            <button
              type="button"
              class="relative z-10 inline-flex flex-1 items-center justify-center gap-2 rounded-full px-3 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
              :class="contactMethod === 'phone' ? 'text-text-primary' : 'text-text-muted hover:text-text-primary'"
              @click="setContactMethod('phone')"
            >
              <Phone class="h-4 w-4" aria-hidden="true" />
              Phone
            </button>
          </div>
        </div>

        <Transition name="fade" mode="out-in">
          <div v-if="contactMethod === 'email'" key="contact-email">
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Email</label>
            <div class="relative">
              <Mail class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="activeForm.email" type="email" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="email" />
            </div>
            <p class="mt-2 text-xs text-text-muted">We will send a verification link to your email.</p>
            <InputError :message="activeForm.errors.email" />
          </div>

          <div v-else key="contact-phone" class="space-y-5">
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Primary phone</label>
              <div class="relative">
                <Phone class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
                <input v-model="activeForm.phone_primary" class="zaqa-input h-12 rounded-lg pl-10" autocomplete="tel" />
              </div>
              <p class="mt-2 text-xs text-text-muted">We will send a one-time code (OTP) to your phone.</p>
              <InputError :message="activeForm.errors.phone_primary" />
            </div>
          </div>
        </Transition>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Password</label>
            <div class="relative">
              <Lock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input
                v-model="activeForm.password"
                :type="showPassword ? 'text' : 'password'"
                class="zaqa-input h-12 rounded-lg pl-10 pr-11"
                autocomplete="new-password"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-2 inline-flex items-center justify-center rounded-md px-2 text-text-muted transition hover:bg-surface-muted hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                @click="showPassword = !showPassword"
              >
                <Eye v-if="!showPassword" class="h-5 w-5" aria-hidden="true" />
                <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
              </button>
            </div>
            <InputError :message="activeForm.errors.password" />
          </div>
          <div>
            <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirm password</label>
            <div class="relative">
              <Lock class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input
                v-model="activeForm.password_confirmation"
                :type="showConfirmPassword ? 'text' : 'password'"
                class="zaqa-input h-12 rounded-lg pl-10 pr-11"
                autocomplete="new-password"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-2 inline-flex items-center justify-center rounded-md px-2 text-text-muted transition hover:bg-surface-muted hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
                :aria-label="showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'"
                @click="showConfirmPassword = !showConfirmPassword"
              >
                <Eye v-if="!showConfirmPassword" class="h-5 w-5" aria-hidden="true" />
                <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
              </button>
            </div>
            <InputError :message="activeForm.errors.password_confirmation" />
          </div>
        </div>

        <div class="rounded-xl border border-border bg-surface-muted/60 p-5 text-sm">
          <label class="flex items-start gap-3">
            <input
              v-model="termsAccepted"
              type="checkbox"
              class="mt-0.5 h-5 w-5 rounded-md border-border bg-surface text-brand shadow-sm focus:ring-brand/25"
            />
            <span class="leading-relaxed text-text-primary">
              I have read and accept the
              <button type="button" class="zaqa-link inline font-semibold" @click="showTermsModal = true">
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
          class="zaqa-btn h-12 w-full rounded-xl bg-gradient-to-r from-brand to-brand-dark text-brand-foreground shadow-md transition hover:from-brand-dark hover:to-brand-dark active:translate-y-[1px]"
          :disabled="activeForm.processing"
        >
          Create account
        </button>
      </form>

      <div class="mt-8 flex flex-wrap items-center justify-center gap-2 text-sm text-text-muted">
        <span>Already have an account?</span>
        <Link href="/login" class="zaqa-btn zaqa-btn-secondary h-10 px-4 py-2 text-sm">
          Log in
        </Link>
      </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

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
  </GuestLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 180ms ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
