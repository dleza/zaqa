<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'

const form = useForm({
  email: '',
})

function submit() {
  form.post('/forgot-password')
}
</script>

<template>
  <GuestLayout>
    <h2 class="text-lg font-semibold">Forgot password</h2>
    <p class="mt-1 text-sm text-text-muted">Enter your email address and we will send a reset link.</p>

    <form class="mt-6 space-y-4" @submit.prevent="submit">
      <div>
        <label class="text-sm font-medium">Email</label>
        <input
          v-model="form.email"
          type="email"
          class="zaqa-input"
          autocomplete="email"
        />
        <InputError :message="form.errors.email" />
      </div>

      <button
        type="submit"
        class="zaqa-btn zaqa-btn-primary w-full"
        :disabled="form.processing"
      >
        Send reset link
      </button>
    </form>

    <div class="mt-6 text-center text-sm">
      <Link href="/login" class="zaqa-link">Back to login</Link>
    </div>
  </GuestLayout>
</template>

