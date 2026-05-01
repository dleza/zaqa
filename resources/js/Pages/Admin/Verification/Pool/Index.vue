<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Search, ShieldCheck, UserCheck } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  qualifications: any
  filters: {
    q: string
    assigned?: string | null
    mine?: string | null
    foreign?: string | null
    qualification_type_id?: string | null
    awarding_institution_id?: string | null
    country_id?: string | null
    assigned_verifier_id?: string | null
    verification_state?: string | null
    payment_status?: string | null
    submitted_from?: string | null
    submitted_to?: string | null
  }
  can: { assign: boolean }
}>()

const q = ref(props.filters.q ?? '')
const assigned = ref<string>(props.filters.assigned ?? '')
const mine = ref<string>(props.filters.mine ?? '')
const foreign = ref<string>(props.filters.foreign ?? '')
const qualificationTypeId = ref<string>(props.filters.qualification_type_id ?? '')
const awardingInstitutionId = ref<string>(props.filters.awarding_institution_id ?? '')
const countryId = ref<string>(props.filters.country_id ?? '')
const assignedVerifierId = ref<string>(props.filters.assigned_verifier_id ?? '')
const verificationState = ref<string>(props.filters.verification_state ?? '')
const paymentStatus = ref<string>(props.filters.payment_status ?? '')

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
const submittedFrom = ref<string>(props.filters.submitted_from ?? '')
const submittedTo = ref<string>(props.filters.submitted_to ?? '')

watch(
  [
    q,
    assigned,
    mine,
    foreign,
    qualificationTypeId,
    awardingInstitutionId,
    countryId,
    assignedVerifierId,
    verificationState,
    paymentStatus,
    submittedFrom,
    submittedTo,
  ],
  () => {
  router.get(
    '/admin/verification/pool',
    {
      q: q.value,
      assigned: assigned.value || null,
      mine: mine.value || null,
      foreign: foreign.value || null,
      qualification_type_id: qualificationTypeId.value || null,
      awarding_institution_id: awardingInstitutionId.value || null,
      country_id: countryId.value || null,
      assigned_verifier_id: assignedVerifierId.value || null,
      verification_state: verificationState.value || null,
      payment_status: paymentStatus.value || null,
      submitted_from: submittedFrom.value || null,
      submitted_to: submittedTo.value || null,
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
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Verification Pool</h1>
        <p class="mt-1 text-sm text-text-muted">Intake queue for verification review and assignment.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool/country" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">By country</Link>
        <Link href="/admin/verification/pool/awarding-institution" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          By awarding institution
        </Link>
        <Link href="/admin/verification/assigned-to-me" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">
          <UserCheck class="h-4 w-4" aria-hidden="true" />
          Assigned to me
        </Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Pool</div>
            <div class="mt-1 text-xs text-text-muted">Search and filter qualification verification tasks.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search application #, holder, NRC/Passport..." />
            </div>
            <input v-model="submittedFrom" type="date" class="zaqa-input h-10" />
            <input v-model="submittedTo" type="date" class="zaqa-input h-10" />
            <select v-model="assigned" class="zaqa-input h-10">
              <option value="">All assignments</option>
              <option value="1">Assigned</option>
              <option value="0">Unassigned</option>
            </select>
            <select v-model="foreign" class="zaqa-input h-10">
              <option value="">Local + Foreign</option>
              <option value="0">Local</option>
              <option value="1">Foreign</option>
            </select>
            <select v-model="paymentStatus" class="zaqa-input h-10">
              <option value="">Paid + Unpaid</option>
              <option value="paid">Paid</option>
              <option value="unpaid">Unpaid</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="qualifications.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No qualification tasks found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust your filters.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Applicant</th>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Local/Foreign</th>
              <th class="px-5 py-3 text-left">Assigned verifier</th>
              <th class="px-5 py-3 text-left">Qualification status</th>
              <th class="px-5 py-3 text-left">Payment</th>
              <th class="px-5 py-3 text-left">Submitted</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in qualifications.data" :key="row.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.application?.application_number ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Task #{{ row.id }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.applicant_name ?? '—' }}</td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.qualification_title ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">
                  {{ row.qualification_type ?? '—' }} • {{ row.country_of_award ?? '—' }} • {{ row.awarding_institution ?? '—' }}
                </div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.is_foreign ? 'Foreign' : 'Local' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.assigned_verifier ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.verification_state ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.application?.payment_status ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">
                <span v-if="row.application?.submitted_at">{{ new Date(row.application.submitted_at).toLocaleDateString() }}</span>
                <span v-else class="text-text-muted">—</span>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="`/admin/verification/qualifications/${row.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Open</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>

