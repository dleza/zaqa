<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { BookOpen } from 'lucide-vue-next'

const props = defineProps<{
  record: any
}>()
</script>

<template>
  <AdminLayout>
    <div>
      <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
        <BookOpen class="h-4 w-4" aria-hidden="true" />
        Learner Records
      </div>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-text-primary">Learner record #{{ record.id }}</h1>
          <p class="mt-1 text-sm text-text-muted">Read-only detail view.</p>
        </div>
        <Link href="/admin/learner-records" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
      </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-2">
      <div class="rounded-2xl border border-border bg-surface p-5">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Institution</div>
        <div class="mt-2 text-sm font-semibold text-text-primary">
          {{ record.awarding_institution?.name || record.institution_name_raw || '—' }}
        </div>
        <div v-if="record.import" class="mt-3 text-xs text-text-muted">
          Imported via:
          <Link :href="`/admin/learner-records/imports/${record.import.id}`" class="font-semibold text-brand hover:underline">
            {{ record.import.original_filename }}
          </Link>
          ({{ record.import.status }})
        </div>
      </div>

      <div class="rounded-2xl border border-border bg-surface p-5">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Program</div>
        <div class="mt-2 text-sm font-semibold text-text-primary">{{ record.program_of_study || '—' }}</div>
        <div class="mt-3 text-xs text-text-muted">Year awarded: {{ record.year_awarded || '—' }}</div>
        <div class="mt-1 text-xs text-text-muted">Award date: {{ record.award_date || '—' }}</div>
      </div>

      <div class="rounded-2xl border border-border bg-surface p-5">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Identifiers</div>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <div class="text-xs font-medium text-text-muted">Student ID</div>
            <div class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ record.student_id || '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-text-muted">Certificate no</div>
            <div class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ record.certificate_no || '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-text-muted">NRC</div>
            <div class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ record.nrc_number || '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-medium text-text-muted">Passport</div>
            <div class="mt-1 font-mono text-sm font-semibold text-text-primary">{{ record.passport_no || '—' }}</div>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-border bg-surface p-5">
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Learner</div>
        <div class="mt-2 text-sm font-semibold text-text-primary">
          {{ [record.first_name, record.other_names, record.last_name].filter(Boolean).join(' ') || '—' }}
        </div>
        <div class="mt-2 text-xs text-text-muted">Gender: {{ record.gender || '—' }}</div>
      </div>
    </div>
  </AdminLayout>
</template>

