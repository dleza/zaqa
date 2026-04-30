<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next'

const page = usePage()

const success = computed(() => (page.props.flash as any)?.success)
const error = computed(() => (page.props.flash as any)?.error)
const generatedPassword = computed(() => (page.props.flash as any)?.generated_password)
const generatedPasswordFor = computed(() => (page.props.flash as any)?.generated_password_for)
</script>

<template>
  <div class="mb-4 space-y-3">
    <div
      v-if="success"
      class="flex items-start gap-2 rounded-xl border border-success/20 bg-success/10 px-5 py-4 text-sm text-success"
      role="status"
      aria-live="polite"
    >
      <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
      <span class="flex-1">{{ success }}</span>
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
      class="flex items-start gap-2 rounded-xl border border-danger/20 bg-danger/10 px-5 py-4 text-sm text-danger"
      role="alert"
    >
      <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
      <span class="flex-1">{{ error }}</span>
    </div>
  </div>
</template>
