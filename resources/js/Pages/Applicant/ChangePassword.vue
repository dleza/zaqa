<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import ApplicantLayout from '@/Layouts/ApplicantLayout.vue'
import InputError from '@/Components/InputError.vue'
import { ArrowLeft, KeyRound, ShieldCheck } from 'lucide-vue-next'

const form = useForm({
  password: '',
  password_confirmation: '',
})

function submit() {
  form.post('/applicant/change-password')
}
</script>

<template>
  <ApplicantLayout>
    <div class="relative min-h-[50vh]">
      <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-20 top-0 h-64 w-64 rounded-full bg-brand/10 blur-3xl" />
        <div class="absolute right-0 top-24 h-72 w-72 rounded-full bg-accent/10 blur-3xl" />
      </div>

      <div class="zaqa-wizard-shell">
        <Link
          href="/applicant/profile"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-text-muted transition hover:text-brand"
        >
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          Back to profile
        </Link>

        <div class="mt-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="flex items-start gap-4">
            <span
              class="zaqa-brand-hero flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-text-on-dark shadow-lg shadow-brand/25"
            >
              <KeyRound class="h-6 w-6" aria-hidden="true" />
            </span>
            <div>
              <h1 class="text-2xl font-semibold tracking-tight text-text-primary sm:text-3xl">Change password</h1>
              <p class="mt-2 max-w-xl text-sm leading-relaxed text-text-muted">
                Choose a strong password.
              </p>
            </div>
          </div>
        </div>

        <div
          class="mx-auto mt-10 max-w-xl overflow-hidden rounded-3xl border border-border/80 bg-surface shadow-[0_20px_50px_-12px_rgba(11,58,102,0.12)] ring-1 ring-black/[0.04]"
        >
          <div
            class="border-b border-border/70 bg-gradient-to-r from-brand/[0.06] via-surface-muted/80 to-transparent px-6 py-5 sm:px-8"
          >
            <div class="flex items-center gap-3">
              <ShieldCheck class="h-5 w-5 text-brand" aria-hidden="true" />
              <div>
                <div class="text-sm font-semibold text-text-primary">Secure update</div>
                <div class="mt-0.5 text-xs text-text-muted">Your session stays active after the password is changed.</div>
              </div>
            </div>
          </div>

          <form class="space-y-6 px-6 py-8 sm:px-8" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <label class="text-sm font-medium text-text-primary">New password</label>
                <input
                  v-model="form.password"
                  type="password"
                  class="zaqa-input mt-2"
                  autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
              </div>
              <div class="sm:col-span-2">
                <label class="text-sm font-medium text-text-primary">Confirm new password</label>
                <input
                  v-model="form.password_confirmation"
                  type="password"
                  class="zaqa-input mt-2"
                  autocomplete="new-password"
                />
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 pt-2">
              <button type="submit" class="zaqa-btn zaqa-btn-primary px-6 py-2.5 text-sm font-semibold shadow-md shadow-brand/20" :disabled="form.processing">
                Update password
              </button>
              <Link href="/applicant/profile" class="text-sm font-semibold text-text-muted hover:text-brand">Cancel</Link>
            </div>
          </form>
        </div>
      </div>
    </div>
  </ApplicantLayout>
</template>
