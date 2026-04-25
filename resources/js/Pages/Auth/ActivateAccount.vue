<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'

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
  <GuestLayout>
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-text-primary">Activate account</h2>
        <p class="mt-1 text-sm text-text-muted">Securely verify your email and phone number to activate your account.</p>
      </div>
      <span class="zaqa-badge" aria-label="Activation progress">
        {{ completedCount }} of {{ totalCount }} completed
      </span>
    </div>

    <div class="mt-6 space-y-4">
      <!-- Email panel -->
      <section class="rounded-xl border border-border bg-surface p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold text-text-primary">Email verification</h3>
              <span v-if="emailVerified" class="zaqa-badge zaqa-badge-success">
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                  <path
                    fill-rule="evenodd"
                    d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.6a1 1 0 0 1-1.423.007L3.29 9.814a1 1 0 1 1 1.414-1.414l3.73 3.73 6.79-6.84a1 1 0 0 1 1.47 0Z"
                    clip-rule="evenodd"
                  />
                </svg>
                Verified
              </span>
              <span v-else class="zaqa-badge zaqa-badge-warning">Pending</span>
            </div>
            <p class="mt-1 text-xs text-text-muted">
              Use the verification link sent to your email address. If you didn’t receive it, you can resend it.
            </p>
          </div>

          <div class="flex shrink-0 items-center gap-2">
            <button
              v-if="!emailVerified"
              type="button"
              class="zaqa-btn zaqa-btn-secondary px-4"
              :disabled="resendEmailForm.processing"
              :aria-busy="resendEmailForm.processing ? 'true' : 'false'"
              @click="resendEmail"
            >
              {{ resendEmailForm.processing ? 'Resending…' : 'Resend email' }}
            </button>
          </div>
        </div>

        <div v-if="emailVerified" class="mt-4 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success" role="status" aria-live="polite">
          Email verified successfully.
        </div>
      </section>

      <!-- Phone panel -->
      <section class="rounded-xl border border-border bg-surface p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold text-text-primary">Phone verification</h3>
              <span v-if="phoneVerified" class="zaqa-badge zaqa-badge-success">
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                  <path
                    fill-rule="evenodd"
                    d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.5 7.6a1 1 0 0 1-1.423.007L3.29 9.814a1 1 0 1 1 1.414-1.414l3.73 3.73 6.79-6.84a1 1 0 0 1 1.47 0Z"
                    clip-rule="evenodd"
                  />
                </svg>
                Verified
              </span>
              <span v-else class="zaqa-badge zaqa-badge-warning">Pending</span>
            </div>
            <p class="mt-1 text-xs text-text-muted">Enter the 6-digit OTP sent to your primary phone number.</p>
          </div>

          <div class="flex shrink-0 items-center gap-2">
            <button
              v-if="!phoneVerified"
              type="button"
              class="zaqa-btn zaqa-btn-secondary px-4"
              :disabled="resendOtpForm.processing"
              :aria-busy="resendOtpForm.processing ? 'true' : 'false'"
              @click="resendOtp"
            >
              {{ resendOtpForm.processing ? 'Resending…' : 'Resend OTP' }}
            </button>
          </div>
        </div>

        <div v-if="phoneVerified" class="mt-4 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success" role="status" aria-live="polite">
          Phone verified successfully.
        </div>

        <form v-else class="mt-4" @submit.prevent="verifyOtp">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-6 sm:items-start">
            <div class="sm:col-span-4">
              <label class="text-sm font-medium">OTP code</label>
              <input
                v-model="otpForm.code"
                inputmode="numeric"
                maxlength="6"
                autocomplete="one-time-code"
                class="zaqa-input tracking-[0.35em] text-center font-semibold"
                placeholder="••••••"
                aria-label="Enter OTP code"
                :disabled="otpForm.processing"
              />
              <InputError :message="otpForm.errors.code" />
            </div>

            <div class="sm:col-span-2 sm:pt-6">
              <button type="submit" class="zaqa-btn zaqa-btn-primary w-full" :disabled="otpForm.processing" :aria-busy="otpForm.processing ? 'true' : 'false'">
                {{ otpForm.processing ? 'Verifying…' : 'Verify' }}
              </button>
            </div>
          </div>
        </form>
      </section>

      <!-- Completion CTA -->
      <section v-if="activationComplete" class="rounded-xl border border-success/20 bg-success/10 p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-success">Account activated</div>
            <div class="mt-1 text-xs text-text-muted">Your verification is complete. You can now access the applicant portal.</div>
          </div>
          <Link href="/applicant/dashboard" class="zaqa-btn bg-success text-white hover:bg-success/90">
            Continue to dashboard
          </Link>
        </div>
      </section>

      <div v-else class="rounded-xl border border-border bg-surface-muted px-5 py-4 text-xs text-text-muted">
        Your account becomes active once both <span class="font-semibold text-text-primary">email</span> and <span class="font-semibold text-text-primary">phone</span> verification are completed.
      </div>
    </div>
  </GuestLayout>
</template>

