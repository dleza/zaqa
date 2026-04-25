<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'

defineProps<{
  canLogin: boolean
}>()
</script>

<template>
  <GuestLayout>
    <h2 class="text-lg font-semibold">Welcome</h2>
    <p class="mt-2 text-sm text-text-muted">
      Create an account to start a qualification verification application, upload supporting documents, and track your
      status.
    </p>

    <div class="mt-6 flex flex-col gap-3">
      <Link
        href="/register"
        class="zaqa-btn zaqa-btn-primary"
      >
        Create an account
      </Link>
      <Link
        href="/login"
        class="zaqa-btn zaqa-btn-secondary"
      >
        Log in
      </Link>
    </div>
  </GuestLayout>
</template>

