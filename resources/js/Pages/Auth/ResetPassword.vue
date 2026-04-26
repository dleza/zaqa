<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import { Eye, EyeOff } from 'lucide-vue-next'
import { ref } from 'vue'

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

const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

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
        <div class="relative">
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
        <InputError :message="form.errors.password" />
      </div>

      <div>
        <label class="text-sm font-medium">Confirm new password</label>
        <div class="relative">
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
        Reset password
      </button>
    </form>

    <div class="mt-6 text-center text-sm">
      <Link href="/login" class="zaqa-link">Back to login</Link>
    </div>
  </GuestLayout>
</template>

