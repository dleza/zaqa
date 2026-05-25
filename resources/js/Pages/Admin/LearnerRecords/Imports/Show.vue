<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { FileSpreadsheet } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  import: any
}>()

const learnerImport = computed(() => props.import)
const errors = computed(() => (Array.isArray(learnerImport.value?.errors) ? learnerImport.value.errors : []) as Array<any>)
</script>

<template>
  <AdminLayout>
    <div>
      <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
        <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
        Learner Records
      </div>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Import #{{ learnerImport.id }}</h1>
          <p class="mt-1 text-sm text-text-muted">{{ learnerImport.original_filename }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <Link href="/admin/learner-records/imports" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
          <Link href="/admin/learner-records" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Records</Link>
        </div>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-2">
      <div class="rounded-2xl border border-border bg-surface p-5">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Status</div>
        <div class="mt-2">
          <span
            class="zaqa-badge"
            :class="learnerImport.status === 'completed' ? 'zaqa-badge-success' : learnerImport.status === 'failed' ? 'zaqa-badge-danger' : 'zaqa-badge-warning'"
          >
            {{ learnerImport.status }}
          </span>
        </div>
        <div class="mt-3 text-xs text-text-muted">Institution: {{ learnerImport.awarding_institution?.name || '—' }}</div>
        <div class="mt-1 text-xs text-text-muted">Uploaded by: {{ learnerImport.uploaded_by?.name || '—' }}</div>
        <div class="mt-1 text-xs text-text-muted">Created: {{ learnerImport.created_at }}</div>
      </div>

      <div class="rounded-2xl border border-border bg-surface p-5">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Progress</div>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <div class="text-xs font-medium text-text-muted">Processed</div>
            <div class="mt-1 text-sm font-semibold text-text-primary">{{ learnerImport.processed_rows ?? 0 }}/{{ learnerImport.total_rows ?? '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-text-muted">Inserted</div>
            <div class="mt-1 text-sm font-semibold text-text-primary">{{ learnerImport.inserted_rows ?? 0 }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-text-muted">Updated</div>
            <div class="mt-1 text-sm font-semibold text-text-primary">{{ learnerImport.updated_rows ?? 0 }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-text-muted">Failed rows</div>
            <div class="mt-1 text-sm font-semibold text-text-primary">{{ learnerImport.failed_rows ?? 0 }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">Errors</div>
        <div class="mt-1 text-xs text-text-muted">Row-level issues captured during import (truncated).</div>
      </div>
      <div v-if="errors.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No errors recorded</div>
          <div class="mt-1 text-xs text-text-muted">This import completed without row-level failures.</div>
        </div>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Row</th>
              <th class="px-5 py-3 text-left">Message</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="(e, idx) in errors" :key="idx" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3 font-mono text-xs text-text-primary">{{ e.row ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ e.message ?? e }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
