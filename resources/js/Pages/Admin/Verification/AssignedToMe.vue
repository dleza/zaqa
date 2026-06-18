<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ExternalLink, Search, UserCheck } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  qualifications: any
  pageVariant?: 'assigned' | 'awaiting_applicant'
  filters?: {
    q?: string
    overdue?: string | null
    overdue_days?: string | null
    submitted_from?: string | null
    submitted_to?: string | null
    qualification_q?: string | null
  }
}>()

const variant = computed(() => props.pageVariant ?? 'assigned')
const listBasePath = computed(() =>
  variant.value === 'awaiting_applicant'
    ? '/admin/verification/awaiting-applicant-resubmission'
    : '/admin/verification/assigned-to-me',
)
const pageTitle = computed(() =>
  variant.value === 'awaiting_applicant' ? 'Awaiting applicant resubmission' : 'Assigned to me',
)
const pageDescription = computed(() =>
  variant.value === 'awaiting_applicant'
    ? 'Qualifications you sent back for corrections — the applicant has not yet resubmitted changes. Use this list to follow up or open the task for context.'
    : 'Qualifications currently waiting for your action.',
)
const emptyMessage = computed(() =>
  variant.value === 'awaiting_applicant'
    ? 'No qualifications are waiting on applicants from your send-backs.'
    : 'You have no qualifications waiting for your action.',
)

const q = ref((props.filters?.q ?? '').toString())
const overdue = ref((props.filters?.overdue ?? '').toString())
const overdueDays = ref((props.filters?.overdue_days ?? '').toString())
const submittedFrom = ref((props.filters?.submitted_from ?? '').toString())
const submittedTo = ref((props.filters?.submitted_to ?? '').toString())
const qualificationQ = ref((props.filters?.qualification_q ?? '').toString())

/** Display label for per-qualification verification workflow state */
function formatQualVerificationState(raw: string | null | undefined): string {
  const s = (raw ?? '').toString().trim()
  if (!s) return '—'
  const labels: Record<string, string> = {
    awaiting_assignment: 'Awaiting assignment',
    assigned_to_level1: 'Assigned — Level 1',
    under_level1_review: 'Under Level 1 review',
    under_level2_review: 'Under Level 2 review',
    auto_verified_pending_level2: 'Auto-verified — pending Level 2',
    returned_to_applicant: 'Returned to applicant',
    approved_for_certificate: 'Approved for certificate',
    rejected: 'Rejected',
    certificate_issued: 'Certificate issued',
    closed: 'Closed',
  }
  return labels[s] ?? s.replace(/_/g, ' ')
}

const statusBadgeClass = computed(() => {
  return (status: string | null | undefined) => {
    const s = (status ?? '').toString()
    if (['approved', 'certificate_ready', 'completed'].includes(s)) return 'zaqa-badge-success'
    if (['rejected', 'failed'].includes(s)) return 'zaqa-badge-danger'
    if (['submitted', 'resubmitted'].includes(s)) return 'zaqa-badge-warning'
    if (['in_progress', 'under_review'].includes(s)) return 'zaqa-badge-info'
    if (['sent_back', 'returned_to_applicant'].includes(s)) return 'zaqa-badge-warning'
    return 'zaqa-badge-secondary'
  }
})

watch([q, overdue, overdueDays, submittedFrom, submittedTo, qualificationQ, listBasePath], () => {
  router.get(
    listBasePath.value,
    {
      q: q.value || null,
      overdue: overdue.value || null,
      overdue_days: overdueDays.value || null,
      submitted_from: submittedFrom.value || null,
      submitted_to: submittedTo.value || null,
      qualification_q: qualificationQ.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <UserCheck class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">{{ pageTitle }}</h1>
        <p class="mt-1 text-sm text-text-muted">
          {{ pageDescription }}
        </p>

      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back to pool</Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div class="relative">
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
            <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search application #, holder, NRC/Passport..." />
          </div>
          <input v-model="qualificationQ" type="text" class="zaqa-input h-10" placeholder="Qualification title contains…" />
          <input v-model="submittedFrom" type="date" class="zaqa-input h-10" />
          <input v-model="submittedTo" type="date" class="zaqa-input h-10" />
          <select v-model="overdue" class="zaqa-input h-10" @change="overdueDays = ''">
            <option value="">All SLA</option>
            <option value="1">Overdue</option>
          </select>
          <select v-model="overdueDays" class="zaqa-input h-10" @change="overdue = ''">
            <option value="">Overdue by</option>
            <option value="30">30+ days</option>
            <option value="60">60+ days</option>
            <option value="90">90+ days</option>
          </select>
        </div>
      </div>

      <div v-if="qualifications.data.length === 0" class="px-5 py-6 text-sm text-text-muted">
        {{ emptyMessage }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Qualification task</th>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Applicant</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-left">NRC / Passport</th>
              <th class="px-5 py-3 text-left">Deadline</th>
              <th class="px-5 py-3 text-left">Task status</th>
              <th class="px-5 py-3 text-left">Application status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in qualifications.data" :key="row.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.qualification_title ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">
                  Task #{{ row.id }}
                  <span v-if="row.qualification_type" class="text-text-muted"> · {{ row.qualification_type }}</span>
                </div>
              </td>
              <td class="px-5 py-3 font-mono text-text-primary">{{ row.application?.application_number ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.applicant_name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div v-if="row.holder_name" class="font-semibold">{{ row.holder_name }}</div>
                <span v-else class="zaqa-badge zaqa-badge-danger">Missing</span>
              </td>
              <td class="px-5 py-3 text-text-primary">
                <span v-if="row.holder_nrc_passport" class="font-mono">{{ row.holder_nrc_passport }}</span>
                <span v-else class="zaqa-badge zaqa-badge-danger">Missing</span>
              </td>
              <td class="px-5 py-3 text-text-primary">
                <span v-if="row.service_deadline_at">{{
                  new Date(row.service_deadline_at).toLocaleDateString()
                }}</span>
                <span v-else class="text-text-muted">—</span>
              </td>
              <td class="px-5 py-3 text-text-primary">
                <span class="text-xs leading-snug">{{ formatQualVerificationState(row.verification_state) }}</span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(row.application?.current_status)">{{
                  row.application?.current_status ?? '—'
                }}</span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="flex flex-wrap items-center justify-end gap-2">
                  <Link
                    :href="`/admin/verification/qualifications/${row.id}`"
                    class="zaqa-btn zaqa-btn-primary h-9 px-3 py-2 text-xs font-semibold"
                  >
                    Open task
                  </Link>
                  <Link
                    v-if="row.application?.id"
                    :href="`/admin/verification/applications/${row.application.id}`"
                    class="zaqa-btn zaqa-btn-secondary inline-flex h-9 items-center gap-1 px-3 py-2 text-xs"
                  >
                    <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                    Application
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
