<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'

const props = defineProps<{
  token: string
  email: string
}>()

const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

function submit() {
  form.post('/reset-password')
}
</script>

<template>
  <GuestLayout>
    <h2 class="text-lg font-semibold">Reset password</h2>

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

      <div>
        <label class="text-sm font-medium">New password</label>
        <input
          v-model="form.password"
          type="password"
          class="zaqa-input"
          autocomplete="new-password"
        />
        <InputError :message="form.errors.password" />
      </div>

      <div>
        <label class="text-sm font-medium">Confirm new password</label>
        <input
          v-model="form.password_confirmation"
          type="password"
          class="zaqa-input"
          autocomplete="new-password"
        />
      </div>

      <button
        type="submit"
        class="zaqa-btn zaqa-btn-primary w-full"
        :disabled="form.processing"
      >
        Reset password
      </button>
    </form>

    <div class="mt-6 text-center text-sm">
      <Link href="/login" class="zaqa-link">Back to login</Link>
    </div>
  </GuestLayout>
</template>

