<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import FlashMessages from '@/Components/FlashMessages.vue'
import { Eye, EyeOff, Smartphone } from 'lucide-vue-next'
import { ref } from 'vue'

const props = defineProps<{
  phone_hint: string
}>()

const form = useForm({
  code: '',
  password: '',
  password_confirmation: '',
})

const resendForm = useForm({})

const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

function submit() {
  form.post('/reset-password/phone')
}

function resendCode() {
  resendForm.post('/reset-password/phone/resend', { preserveScroll: true })
}
</script>

<template>
  <GuestLayout>
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand/10">
      <Smartphone class="h-6 w-6 text-brand" aria-hidden="true" />
    </div>

    <h2 class="mt-5 text-xl font-semibold tracking-tight text-text-primary">Reset password with SMS code</h2>
    <p class="mt-2 text-sm text-text-muted">
      Enter the 6-digit code sent to <span class="font-medium text-text-primary">{{ props.phone_hint }}</span> and choose a new password.
    </p>

    <FlashMessages class="mt-4" />

    <form class="mt-6 space-y-4" @submit.prevent="submit">
      <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Verification code</label>
        <input
          v-model="form.code"
          type="text"
          inputmode="numeric"
          maxlength="6"
          class="zaqa-input mt-2 tracking-[0.3em]"
          autocomplete="one-time-code"
          placeholder="000000"
        />
        <InputError :message="form.errors.code" class="mt-1" />
      </div>

      <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">New password</label>
        <div class="relative mt-2">
          <input
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            class="zaqa-input pr-11"
            autocomplete="new-password"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-text-muted hover:text-text-primary"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-4 w-4" aria-hidden="true" />
            <Eye v-else class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
        <InputError :message="form.errors.password" class="mt-1" />
      </div>

      <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Confirm new password</label>
        <div class="relative mt-2">
          <input
            v-model="form.password_confirmation"
            :type="showPasswordConfirmation ? 'text' : 'password'"
            class="zaqa-input pr-11"
            autocomplete="new-password"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-text-muted hover:text-text-primary"
            :aria-label="showPasswordConfirmation ? 'Hide password confirmation' : 'Show password confirmation'"
            @click="showPasswordConfirmation = !showPasswordConfirmation"
          >
            <EyeOff v-if="showPasswordConfirmation" class="h-4 w-4" aria-hidden="true" />
            <Eye v-else class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </div>

      <button
        type="submit"
        class="zaqa-btn zaqa-btn-primary w-full"
        :disabled="form.processing"
      >
        {{ form.processing ? 'Resetting…' : 'Reset password' }}
      </button>
    </form>

    <div class="mt-4 flex flex-col items-center gap-2 text-sm">
      <button
        type="button"
        class="zaqa-link disabled:opacity-50"
        :disabled="resendForm.processing"
        @click="resendCode"
      >
        {{ resendForm.processing ? 'Sending…' : 'Resend code' }}
      </button>
      <Link href="/forgot-password" class="zaqa-link text-text-muted">Use a different email or phone</Link>
      <Link href="/login" class="zaqa-link">Back to login</Link>
    </div>
  </GuestLayout>
</template>
