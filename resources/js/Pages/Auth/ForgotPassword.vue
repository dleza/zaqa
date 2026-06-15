<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import FlashMessages from '@/Components/FlashMessages.vue'
import { Mail } from 'lucide-vue-next'

const form = useForm({
  identifier: '',
})

function submit() {
  form.post('/forgot-password')
}
</script>

<template>
  <GuestLayout>
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand/10">
      <Mail class="h-6 w-6 text-brand" aria-hidden="true" />
    </div>

    <h2 class="mt-5 text-xl font-semibold tracking-tight text-text-primary">Forgot password</h2>
    <p class="mt-2 text-sm text-text-muted">
      Enter the same email or phone number you use to log in. We will send a reset link or verification code depending on your account.
    </p>

    <FlashMessages class="mt-4" />

    <form class="mt-6 space-y-4" @submit.prevent="submit">
      <div>
        <label class="text-xs font-semibold uppercase tracking-wider text-text-muted">Email or phone number</label>
        <input
          v-model="form.identifier"
          type="text"
          class="zaqa-input mt-2"
          autocomplete="username"
          placeholder="you@example.com or 097…"
        />
        <InputError :message="form.errors.identifier" class="mt-1" />
      </div>

      <button
        type="submit"
        class="zaqa-btn zaqa-btn-primary w-full"
        :disabled="form.processing"
      >
        {{ form.processing ? 'Sending…' : 'Continue' }}
      </button>
    </form>

    <div class="mt-6 text-center text-sm">
      <Link href="/login" class="zaqa-link">Back to login</Link>
    </div>
  </GuestLayout>
</template>
