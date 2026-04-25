<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()

const success = computed(() => (page.props.flash as any)?.success)
const error = computed(() => (page.props.flash as any)?.error)
const generatedPassword = computed(() => (page.props.flash as any)?.generated_password)
const generatedPasswordFor = computed(() => (page.props.flash as any)?.generated_password_for)
</script>

<template>
  <div class="mb-4 space-y-2">
    <div
      v-if="success"
      class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success"
    >
      {{ success }}
    </div>
    <div
      v-if="generatedPassword"
      class="rounded-lg border border-border bg-surface-muted px-4 py-3 text-sm text-text-primary"
    >
      <div class="font-semibold">Temporary password generated</div>
      <div class="mt-1 text-xs text-text-muted">
        For: <span class="font-semibold text-text-primary">{{ generatedPasswordFor ?? '—' }}</span>
      </div>
      <div class="mt-3 rounded-md border border-border bg-surface px-3 py-2 font-mono text-sm">
        {{ generatedPassword }}
      </div>
      <div class="mt-2 text-xs text-text-muted">
        Copy this now. For security, it’s shown only once.
      </div>
    </div>
    <div
      v-if="error"
      class="rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
    >
      {{ error }}
    </div>
  </div>
</template>

