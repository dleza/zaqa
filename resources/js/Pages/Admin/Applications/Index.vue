<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { ClipboardList, Search } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { formatMoneyFromCents } from '@/utils/money'

const props = defineProps<{
  applications: any
  filters: { q: string; status?: string | null }
  can: { finance_view: boolean }
}>()

const q = ref(props.filters.q ?? '')
const status = ref<string>(props.filters.status ?? '')

const statusBadgeClass = computed(() => {
  return (value: string | null | undefined) => {
    const s = (value ?? '').toString()
    if (['approved', 'certificate_ready', 'completed'].includes(s)) return 'zaqa-badge-success'
    if (['rejected', 'failed'].includes(s)) return 'zaqa-badge-danger'
    if (['submitted', 'resubmitted', 'sent_back'].includes(s)) return 'zaqa-badge-warning'
    if (['in_progress', 'under_review'].includes(s)) return 'zaqa-badge-info'
    return 'zaqa-badge-secondary'
  }
})

watch([q, status], () => {
  router.get(
    '/admin/applications',
    { q: q.value || null, status: status.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function formatDateTime(iso: string | null | undefined) {
  if (!iso) return '—'

  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ClipboardList class="h-4 w-4" aria-hidden="true" />
          Applications
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Applications pool</h1>
        <p class="mt-1 text-sm text-text-muted">Submitted applications where all linked qualifications have already been fully processed.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/applications/qualifications" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Qualifications</Link>
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Verification pool</Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Closed application registry</div>
            <div class="mt-1 text-xs text-text-muted">Search by application #, applicant name, qualification holder, title, or invoice #.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="status" class="zaqa-input h-10">
              <option value="">All outcomes</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="certificate_ready">Certificate ready</option>
              <option value="completed">Completed</option>
            </select>
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search applications..." />
            </div>
          </div>
        </div>
      </div>

      <div v-if="applications.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No closed applications found</div>
          <div class="mt-1 text-xs text-text-muted">Applications appear here only after every linked qualification reaches a final outcome.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Applicant</th>
              <th class="px-5 py-3 text-left">Qualifications</th>
              <th class="px-5 py-3 text-left">Outcome summary</th>
              <th class="px-5 py-3 text-left">Invoice</th>
              <th class="px-5 py-3 text-left">Last activity</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="application in applications.data" :key="application.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ application.application_number }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Submitted {{ formatDateTime(application.submitted_at) }}</div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(application.current_status)">{{ application.current_status }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ application.applicant_name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div class="font-semibold">{{ application.qualification_count }} total</div>
                <div class="mt-1 text-xs text-text-muted">
                  <span v-if="(application.qualification_titles ?? []).length">
                    {{ application.qualification_titles.join(', ') }}
                    <span v-if="application.qualification_titles_more_count"> +{{ application.qualification_titles_more_count }} more</span>
                  </span>
                  <span v-else>—</span>
                </div>
                <div class="mt-1 text-xs text-text-muted">
                  <span v-if="(application.holder_names ?? []).length">
                    Holder{{ application.holder_names.length > 1 || application.holder_names_more_count ? 's' : '' }}:
                    {{ application.holder_names.join(', ') }}
                    <span v-if="application.holder_names_more_count"> +{{ application.holder_names_more_count }} more</span>
                  </span>
                </div>
              </td>
              <td class="px-5 py-3 text-text-primary">
                <div class="flex flex-wrap gap-2">
                  <span class="zaqa-badge zaqa-badge-success">Approved {{ application.approved_qualification_count }}</span>
                  <span class="zaqa-badge zaqa-badge-danger">Rejected {{ application.rejected_qualification_count }}</span>
                  <span class="zaqa-badge zaqa-badge-secondary">Closed {{ application.terminal_qualification_count }}</span>
                </div>
              </td>
              <td class="px-5 py-3 text-text-primary">
                <div v-if="application.invoice" class="font-semibold">{{ application.invoice.invoice_number }}</div>
                <div v-if="application.invoice" class="mt-0.5 text-xs text-text-muted">{{ formatMoneyFromCents(application.invoice.amount_cents, application.invoice.currency) }}</div>
                <div v-else class="text-text-muted">—</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ formatDateTime(application.updated_at) }}</td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <Link :href="`/admin/verification/applications/${application.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    View
                  </Link>
                  <Link :href="`/admin/applications/track?application_id=${application.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    Track
                  </Link>
                  <Link
                    v-if="can.finance_view"
                    :href="`/finance/applications/${application.id}/track`"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                  >
                    Finance
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminPagination :links="applications.links ?? []" />
  </AdminLayout>
</template>
