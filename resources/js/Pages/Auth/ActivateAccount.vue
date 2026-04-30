<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import { CheckCircle2, Info, Mail, ShieldCheck, Smartphone } from 'lucide-vue-next'

const props = defineProps<{
  emailVerified: boolean
  phoneVerified: boolean
  isActive: boolean
}>()

const otpForm = useForm({
  code: '',
})

const resendEmailForm = useForm({})
const resendOtpForm = useForm({})

const activationComplete = computed(() => props.isActive)
const completedCount = computed(() => (props.emailVerified ? 1 : 0) + (props.phoneVerified ? 1 : 0))
const totalCount = computed(() => 2)
const progressPercent = computed(() => Math.round((completedCount.value / totalCount.value) * 100))

function verifyOtp() {
  otpForm.post('/activate/phone-otp')
}

function resendEmail() {
  resendEmailForm.post('/activate/resend-email')
}

function resendOtp() {
  resendOtpForm.post('/activate/resend-otp')
}
</script>

<template>
  <GuestLayout max-width-class="max-w-lg sm:max-w-xl md:max-w-2xl lg:max-w-3xl">
    <div class="space-y-8">
      <header class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <h2 class="text-2xl font-semibold tracking-tight text-text-primary">Activate account</h2>
            <p class="mt-2 text-sm text-text-muted">
              Verify your email and phone number to activate your account and keep your access secure.
            </p>
          </div>

          <div class="flex shrink-0 items-center">
            <div class="inline-flex items-center gap-3 rounded-full border border-border bg-surface-muted px-3 py-2 text-xs font-semibold text-text-primary" aria-label="Activation progress">
              <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand/10 text-brand">
                {{ completedCount }}/{{ totalCount }}
              </span>
              <span class="whitespace-nowrap">{{ completedCount }}/{{ totalCount }} completed</span>
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <div
            class="h-2 rounded-full bg-surface-muted"
            role="progressbar"
            :aria-valuenow="completedCount"
            aria-valuemin="0"
            :aria-valuemax="totalCount"
            aria-label="Activation progress bar"
          >
            <div
              class="h-2 rounded-full bg-gradient-to-r from-brand to-accent transition-[width] duration-500 ease-out"
              :style="{ width: `${progressPercent}%` }"
            />
          </div>
          <p class="text-xs text-text-muted">
            Complete both steps to unlock your dashboard.
          </p>
        </div>
      </header>

      <div class="space-y-6">
        <!-- Email panel -->
        <section class="rounded-2xl border border-border bg-surface-muted/60 p-6 transition-shadow hover:shadow-sm">
          <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_auto] lg:items-start">
            <div class="flex items-start gap-4">
              <div class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                <Mail class="h-5 w-5" aria-hidden="true" />
              </div>
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="text-base font-semibold text-text-primary">Email verification</h3>
                  <span v-if="emailVerified" class="zaqa-badge zaqa-badge-success">Verified</span>
                  <span v-else class="zaqa-badge zaqa-badge-warning">Pending</span>
                </div>
                <p class="mt-1 text-sm text-text-muted">
                  Open the verification link sent to your email address. You can resend it if it hasn’t arrived.
                </p>
              </div>
            </div>

            <div class="flex flex-col gap-2 lg:items-end">
              <button
                v-if="!emailVerified"
                type="button"
                class="zaqa-btn zaqa-btn-secondary h-11 w-full px-5 lg:w-auto"
                :disabled="resendEmailForm.processing"
                :aria-busy="resendEmailForm.processing ? 'true' : 'false'"
                @click="resendEmail"
              >
                {{ resendEmailForm.processing ? 'Resending…' : 'Resend email' }}
              </button>
            </div>
          </div>

          <div
            v-if="emailVerified"
            class="mt-5 flex items-start gap-2 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success"
            role="status"
            aria-live="polite"
          >
            <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
            <span>Email verified successfully.</span>
          </div>
        </section>

        <!-- Phone panel -->
        <section class="rounded-2xl border border-border bg-surface-muted/60 p-6 transition-shadow hover:shadow-sm">
          <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_auto] lg:items-start">
            <div class="flex items-start gap-4">
              <div class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent/10 text-accent-deep">
                <Smartphone class="h-5 w-5" aria-hidden="true" />
                <span class="absolute -bottom-1 -right-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-surface text-accent-deep shadow-sm ring-1 ring-border">
                  <ShieldCheck class="h-4 w-4" aria-hidden="true" />
                </span>
              </div>
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="text-base font-semibold text-text-primary">Phone verification</h3>
                  <span v-if="phoneVerified" class="zaqa-badge zaqa-badge-success">Verified</span>
                  <span v-else class="zaqa-badge zaqa-badge-warning">Pending</span>
                </div>
                <p class="mt-1 text-sm text-text-muted">
                  Enter the 6-digit OTP sent to your primary phone number to confirm it’s really you.
                </p>
              </div>
            </div>

            <div class="flex flex-col gap-2 lg:items-end">
              <button
                v-if="!phoneVerified"
                type="button"
                class="zaqa-btn zaqa-btn-secondary h-11 w-full px-5 lg:w-auto"
                :disabled="resendOtpForm.processing"
                :aria-busy="resendOtpForm.processing ? 'true' : 'false'"
                @click="resendOtp"
              >
                {{ resendOtpForm.processing ? 'Resending…' : 'Resend OTP' }}
              </button>
            </div>
          </div>

          <div
            v-if="phoneVerified"
            class="mt-5 flex items-start gap-2 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success"
            role="status"
            aria-live="polite"
          >
            <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
            <span>Phone verified successfully.</span>
          </div>

          <form v-else class="mt-5" @submit.prevent="verifyOtp">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div class="flex-1">
                <label for="otp-code" class="text-xs font-semibold uppercase tracking-wider text-text-muted">OTP code</label>
                <input
                  id="otp-code"
                  v-model="otpForm.code"
                  inputmode="numeric"
                  pattern="[0-9]*"
                  maxlength="6"
                  autocomplete="one-time-code"
                  class="zaqa-input h-12 rounded-lg tracking-[0.45em] text-center font-semibold"
                  placeholder="••••••"
                  aria-label="Enter OTP code"
                  :disabled="otpForm.processing"
                />
                <InputError :message="otpForm.errors.code" />
              </div>

              <button
                type="submit"
                class="zaqa-btn zaqa-btn-primary h-12 w-full rounded-lg sm:w-auto sm:px-8"
                :disabled="otpForm.processing"
                :aria-busy="otpForm.processing ? 'true' : 'false'"
              >
                {{ otpForm.processing ? 'Verifying…' : 'Verify' }}
              </button>
            </div>
          </form>
        </section>

        <!-- Completion CTA -->
        <section v-if="activationComplete" class="rounded-2xl border border-success/20 bg-success/10 p-6">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
              <CheckCircle2 class="mt-0.5 h-6 w-6 text-success" aria-hidden="true" />
              <div>
                <div class="text-base font-semibold text-success">Account activated</div>
                <div class="mt-1 text-sm text-text-muted">
                  Your verification is complete. You can now access the applicant portal.
                </div>
              </div>
            </div>
            <Link href="/applicant/dashboard" class="zaqa-btn w-full bg-success px-6 text-white hover:bg-success/90 sm:w-auto">
              Continue to dashboard
            </Link>
          </div>
        </section>

        <div v-else class="rounded-2xl border border-brand/15 bg-brand/5 p-6 text-sm text-text-muted">
          <div class="flex items-start gap-3">
            <Info class="mt-0.5 h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
            <p class="leading-relaxed">
              Your account becomes active once both <span class="font-semibold text-text-primary">email</span> and
              <span class="font-semibold text-text-primary">phone</span> verification are completed.
            </p>
          </div>
        </div>
      </div>
    </div>
  </GuestLayout>
</template>
