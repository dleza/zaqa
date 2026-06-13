<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { MessageSquare } from 'lucide-vue-next'

defineProps<{
  log: Record<string, unknown>
  index_url: string
}>()
</script>

<template>
  <AdminLayout>
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <MessageSquare class="h-4 w-4" aria-hidden="true" />
          SMS Log #{{ log.id }}
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">SMS details</h1>
      </div>
      <Link :href="index_url" class="zaqa-btn px-4 py-2 text-sm">Back to logs</Link>
    </div>

    <div class="mt-6 rounded-2xl border border-border bg-surface p-6 shadow-sm">
      <dl class="grid gap-4 md:grid-cols-2 text-sm">
        <div><dt class="text-text-muted">Status</dt><dd class="font-semibold">{{ log.status }}</dd></div>
        <div><dt class="text-text-muted">Type</dt><dd class="font-semibold">{{ log.message_type }}</dd></div>
        <div><dt class="text-text-muted">Recipient</dt><dd class="font-semibold">{{ log.phone_number }}</dd></div>
        <div><dt class="text-text-muted">Normalized</dt><dd class="font-semibold">{{ log.normalized_phone ?? '—' }}</dd></div>
        <div><dt class="text-text-muted">Provider</dt><dd class="font-semibold">{{ log.provider }}</dd></div>
        <div><dt class="text-text-muted">HTTP status</dt><dd class="font-semibold">{{ log.http_status ?? '—' }}</dd></div>
        <div><dt class="text-text-muted">Length</dt><dd class="font-semibold">{{ log.message_length ?? '—' }}</dd></div>
        <div><dt class="text-text-muted">Skip reason</dt><dd class="font-semibold">{{ log.skip_reason ?? '—' }}</dd></div>
      </dl>

      <div class="mt-6">
        <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Message</div>
        <pre class="mt-2 whitespace-pre-wrap rounded-xl border border-border bg-surface-muted p-4 text-sm">{{ log.message_body }}</pre>
      </div>

      <div v-if="log.provider_response" class="mt-6">
        <div class="text-xs font-semibold uppercase tracking-wide text-text-muted">Provider response</div>
        <pre class="mt-2 overflow-x-auto rounded-xl border border-border bg-surface-muted p-4 text-xs">{{ JSON.stringify(log.provider_response, null, 2) }}</pre>
      </div>
    </div>
  </AdminLayout>
</template>
