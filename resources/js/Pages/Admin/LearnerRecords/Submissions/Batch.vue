<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import { Link } from '@inertiajs/vue3'
import { ArrowLeft, ClipboardList } from 'lucide-vue-next'

defineProps<{
  batch: any
  submissions: any
}>()
</script>

<template>
  <AdminLayout>
    <div class="flex items-center gap-3">
      <Link href="/admin/learner-records/submissions" class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-3 py-2 text-sm">
        <ArrowLeft class="h-4 w-4" aria-hidden="true" />
        Back
      </Link>
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ClipboardList class="h-4 w-4" aria-hidden="true" />
          Batch {{ batch.reference }}
        </div>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-text-primary">{{ batch.source_institution?.name ?? 'Submission batch' }}</h1>
      </div>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-4">
      <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm"><div class="text-text-muted">Total</div><div class="text-lg font-semibold">{{ batch.total_records }}</div></div>
      <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm"><div class="text-text-muted">Pending</div><div class="text-lg font-semibold">{{ batch.pending_count }}</div></div>
      <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm"><div class="text-text-muted">Approved</div><div class="text-lg font-semibold">{{ batch.approved_count }}</div></div>
      <div class="rounded-xl border border-border bg-surface px-4 py-3 text-sm"><div class="text-text-muted">Failed validation</div><div class="text-lg font-semibold">{{ batch.failed_validation_count }}</div></div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <table class="min-w-full text-sm">
        <thead class="border-b border-border bg-surface-muted/60 text-left text-xs uppercase tracking-wide text-text-muted">
          <tr>
            <th class="px-4 py-3">Row</th>
            <th class="px-4 py-3">Learner</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Dupes</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border/60">
          <tr v-for="row in submissions.data" :key="row.id">
            <td class="px-4 py-3">{{ row.row_number ?? '—' }}</td>
            <td class="px-4 py-3">
              <div class="font-medium">{{ row.display_name }}</div>
              <div class="text-xs text-text-muted">{{ row.student_id || row.certificate_no || '—' }}</div>
            </td>
            <td class="px-4 py-3">{{ row.status }}</td>
            <td class="px-4 py-3">{{ row.duplicate_candidate_count }}</td>
            <td class="px-4 py-3 text-right">
              <Link :href="`/admin/learner-records/submissions/${row.id}`" class="text-xs font-semibold text-primary hover:underline">Review</Link>
            </td>
          </tr>
        </tbody>
      </table>
      <AdminTablePagination :paginator="submissions" />
    </div>
  </AdminLayout>
</template>
