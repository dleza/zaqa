<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import { ArrowRight } from 'lucide-vue-next'

const mounted = ref(false)

onMounted(() => {
  mounted.value = true
})

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
    description: 'Enter your qualification information and upload the required supporting documents. ',
    note: 'Please note you will need  clear scanned copies of your qualification and NRC/Passport.',
  },
  {
    title: 'Pay the applicable fee',
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

  <GuestLayout
    :hide-header="true"
    max-width-class="max-w-3xl"
    content-padding-class="px-4 py-6 sm:px-6 sm:py-8"
  >
    <div class="transition-all duration-500 ease-out" :class="mounted ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0'">
      <div class="flex justify-center">
        <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-16 w-auto object-contain sm:h-[4.5rem]" />
      </div>

      <h2 class="mt-4 text-center text-xl font-semibold tracking-tight text-text-primary sm:text-2xl">How to apply</h2>
      <p class="mt-1.5 text-center text-sm text-text-muted">
        Follow these steps to submit a qualification verification application through the ZAQA Verification Portal.
      </p>

      <ol class="mt-5 space-y-3">
        <li
          v-for="(step, index) in steps"
          :key="step.title"
          class="flex gap-4 rounded-xl border border-border bg-surface-muted/60 p-4"
        >
          <span
            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand/10 text-sm font-bold text-brand"
          >
            {{ index + 1 }}
          </span>
          <div>
            <h3 class="text-sm font-semibold text-text-primary sm:text-base">{{ step.title }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-text-muted">
              {{ step.description }}<span v-if="step.note" class="font-medium text-accent"> {{ step.note }}</span>
            </p>
          </div>
        </li>
      </ol>

      <Link
        href="/register"
        class="zaqa-btn zaqa-btn-primary mt-5 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl"
      >
        Sign-up and Apply
        <ArrowRight class="h-4 w-4" aria-hidden="true" />
      </Link>

      <div class="mt-5 text-center text-sm text-text-muted">
        Already have an account?
        <Link href="/login" class="zaqa-link font-semibold">Log in</Link>
      </div>
    </div>
  </GuestLayout>
</template>
