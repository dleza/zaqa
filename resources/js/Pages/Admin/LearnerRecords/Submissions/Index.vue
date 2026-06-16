<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { AlertTriangle, ClipboardList, Search } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  submissions: any
  institutions: Array<{ id: number; name: string }>
  filters: {
    q: string
    status: string | null
    source_type: string | null
    source_institution_id: string | null
    received_from: string | null
    received_to: string | null
  }
  can: { review: boolean; approve: boolean; reject: boolean }
}>()

const q = ref(props.filters.q ?? '')
const status = ref(props.filters.status ?? '')
const sourceType = ref(props.filters.source_type ?? '')
const institutionId = ref(props.filters.source_institution_id ?? '')
const receivedFrom = ref(props.filters.received_from ?? '')
const receivedTo = ref(props.filters.received_to ?? '')

watch([q, status, sourceType, institutionId, receivedFrom, receivedTo], () => {
  router.get(
    '/admin/learner-records/submissions',
    {
      q: q.value || null,
      status: status.value || null,
      source_type: sourceType.value || null,
      source_institution_id: institutionId.value || null,
      received_from: receivedFrom.value || null,
      received_to: receivedTo.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function statusClass(value: string) {
  switch (value) {
    case 'pending':
      return 'zaqa-badge-warning'
    case 'approved':
      return 'zaqa-badge-success'
    case 'rejected':
    case 'duplicate':
      return 'zaqa-badge-danger'
    default:
      return 'zaqa-badge-muted'
  }
}
</script>

<template>
  <AdminLayout>
    <div>
      <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
        <ClipboardList class="h-4 w-4" aria-hidden="true" />
        Learner Records
      </div>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Pending submissions</h1>
      <p class="mt-1 text-sm text-text-muted">Review third-party learner records before they enter the trusted catalog.</p>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="grid gap-3 lg:grid-cols-6">
          <div class="relative lg:col-span-2">
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
            <input v-model="q" class="zaqa-input h-10 w-full pl-9" placeholder="Search name, student ID, certificate..." />
          </div>
          <select v-model="status" class="zaqa-input h-10">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="duplicate">Duplicate</option>
          </select>
          <select v-model="sourceType" class="zaqa-input h-10">
            <option value="">All sources</option>
            <option value="institution_push">Institution push</option>
            <option value="institution_pull">Institution pull</option>
          </select>
          <select v-model="institutionId" class="zaqa-input h-10">
            <option value="">All institutions</option>
            <option v-for="inst in institutions" :key="inst.id" :value="String(inst.id)">{{ inst.name }}</option>
          </select>
          <input v-model="receivedFrom" type="date" class="zaqa-input h-10" title="Received from" />
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="border-b border-border bg-surface-muted/60 text-left text-xs uppercase tracking-wide text-text-muted">
            <tr>
              <th class="px-4 py-3">Received</th>
              <th class="px-4 py-3">Institution</th>
              <th class="px-4 py-3">Learner</th>
              <th class="px-4 py-3">Programme</th>
              <th class="px-4 py-3">Year</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Dupes</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in submissions.data" :key="row.id" class="hover:bg-surface-muted/40">
              <td class="px-4 py-3 text-xs text-text-muted">{{ row.received_at ? new Date(row.received_at).toLocaleString() : '—' }}</td>
              <td class="px-4 py-3">{{ row.source_institution?.name ?? '—' }}</td>
              <td class="px-4 py-3">
                <div class="font-medium text-text-primary">{{ row.display_name }}</div>
                <div class="text-xs text-text-muted">{{ row.student_id || row.certificate_no || '—' }}</div>
              </td>
              <td class="px-4 py-3">{{ row.program_of_study || '—' }}</td>
              <td class="px-4 py-3">{{ row.year_awarded ?? '—' }}</td>
              <td class="px-4 py-3"><span class="zaqa-badge" :class="statusClass(row.status)">{{ row.status }}</span></td>
              <td class="px-4 py-3">
                <span v-if="row.duplicate_candidate_count > 0" class="inline-flex items-center gap-1 text-xs font-semibold text-warning">
                  <AlertTriangle class="h-3.5 w-3.5" aria-hidden="true" />
                  {{ row.duplicate_candidate_count }}
                </span>
                <span v-else class="text-xs text-text-muted">0</span>
              </td>
              <td class="px-4 py-3 text-right">
                <Link :href="`/admin/learner-records/submissions/${row.id}`" class="text-xs font-semibold text-primary hover:underline">Review</Link>
              </td>
            </tr>
            <tr v-if="submissions.data.length === 0">
              <td colspan="8" class="px-4 py-8 text-center text-sm text-text-muted">No submissions found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="submissions" />
    </div>
  </AdminLayout>
</template>
