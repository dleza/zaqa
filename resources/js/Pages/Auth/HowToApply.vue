<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { ArrowLeft, ArrowRight } from 'lucide-vue-next'

const steps = [
  {
    title: 'Create an account',
    description: 'Sign up as an individual or institution using your email or phone number.',
  },
  {
    title: 'Start a verification application',
    description: 'Log in and create a new application for the qualification you want verified.',
  },
  {
    title: 'Add qualification details and documents',
    description: 'Enter your qualification information and upload the required supporting documents.',
  },
  {
    title: 'Pay the application fee',
    description: 'Complete payment for your application using the available payment options.',
  },
  {
    title: 'Track your application',
    description: 'Monitor progress from your dashboard while ZAQA reviews your submission.',
  },
]
</script>

<template>
  <Head title="How to apply" />

  <GuestLayout max-width-class="max-w-2xl">
    <div>
      <Link
        href="/login"
        class="inline-flex items-center gap-1.5 text-sm font-medium text-[#0073BA] transition hover:underline"
      >
        <ArrowLeft class="h-4 w-4" aria-hidden="true" />
        Back to login
      </Link>

      <h1 class="mt-4 text-2xl font-bold tracking-tight text-[#0F2A4D] sm:text-3xl">How to apply</h1>
      <p class="mt-3 text-sm leading-relaxed text-slate-600 sm:text-base">
        Follow these steps to submit a qualification verification application through the ZAQA Verification Portal.
        This guide will be expanded with more detail soon.
      </p>

      <ol class="mt-8 space-y-5">
        <li
          v-for="(step, index) in steps"
          :key="step.title"
          class="flex gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm"
        >
          <span
            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#0073BA]/10 text-sm font-bold text-[#0073BA]"
          >
            {{ index + 1 }}
          </span>
          <div>
            <h2 class="text-base font-semibold text-[#0F2A4D]">{{ step.title }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ step.description }}</p>
          </div>
        </li>
      </ol>

      <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
        <Link href="/register" class="zaqa-btn zaqa-btn-primary inline-flex items-center justify-center gap-2">
          Sign-up and Apply
          <ArrowRight class="h-4 w-4" aria-hidden="true" />
        </Link>
        <Link href="/login" class="zaqa-btn zaqa-btn-secondary inline-flex items-center justify-center">
          Log in
        </Link>
      </div>
    </div>
  </GuestLayout>
</template>
