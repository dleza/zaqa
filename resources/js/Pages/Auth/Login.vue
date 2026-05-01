<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'

const form = useForm({
  identifier: '',
  password: '',
  remember: false,
})

const showPassword = ref(false)

function submit() {
  form.post('/login')
}
</script>

<template>
  <GuestLayout>
    <h2 class="text-lg font-semibold">Log in</h2>
    <p class="mt-1 text-sm text-text-muted">Use your email address or primary phone number.</p>

    <form class="mt-6 space-y-4" @submit.prevent="submit">
      <div>
        <label class="text-sm font-medium">Email or phone</label>
        <input
          v-model="form.identifier"
          type="text"
          class="zaqa-input text-center"
          autocomplete="username"
        />
        <InputError :message="form.errors.identifier" />
      </div>

      <div>
        <label class="text-sm font-medium">Password</label>
        <div class="relative">
          <input
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            class="zaqa-input pr-11 text-center"
            autocomplete="current-password"
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
        <InputError :message="form.errors.password" />
      </div>

      <label class="flex items-center gap-2 text-sm text-text-primary">
        <input v-model="form.remember" type="checkbox" class="rounded border-border text-brand focus:ring-brand/25" />
        Remember me
      </label>

      <button
        type="submit"
        class="zaqa-btn zaqa-btn-primary w-full"
        :disabled="form.processing"
      >
        Log in
      </button>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm">
      <Link href="/forgot-password" class="zaqa-link">Forgot password?</Link>
      <Link href="/register" class="zaqa-link">New here? Register and Apply</Link>
    </div>
  </GuestLayout>
</template>

