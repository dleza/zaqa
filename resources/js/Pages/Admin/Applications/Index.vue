<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
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
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ClipboardList class="h-4 w-4" aria-hidden="true" />
          Applications
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Application outcomes</h1>
        <p class="mt-1 text-sm text-text-muted">Search and locate applications that are no longer pending verification.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Go to verification pool</Link>
        <Link href="/admin/certificates" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Certificates</Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Registry</div>
            <div class="mt-1 text-xs text-text-muted">Search by application #, applicant name, holder name, certificate/student/exam #, or invoice #.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="status" class="zaqa-input h-10">
              <option value="">All outcomes</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="certificate_ready">Certificate Ready</option>
              <option value="completed">Completed</option>
            </select>
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search..." />
            </div>
          </div>
        </div>
      </div>

      <div v-if="applications.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No records found</div>
          <div class="mt-1 text-xs text-text-muted">Try a different search term.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Applicant</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Certificate/Student/Exam #</th>
              <th class="px-5 py-3 text-left">Invoice</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="a in applications.data" :key="a.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ a.application_number }}</div>
                <div class="mt-0.5 text-xs text-text-muted">
                  {{ a.updated_at ? `Updated ${new Date(a.updated_at).toLocaleDateString()}` : a.current_status }}
                </div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(a.current_status)">{{ a.current_status }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ a.applicant_name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ a.qualification?.holder_name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ a.qualification?.title ?? '—' }}</td>
              <td class="px-5 py-3 font-mono text-text-primary">{{ a.qualification?.certificate_number ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div v-if="a.invoice" class="font-semibold">{{ a.invoice.invoice_number }}</div>
                <div v-if="a.invoice" class="mt-0.5 text-xs text-text-muted">{{ formatMoneyFromCents(a.invoice.amount_cents, a.invoice.currency) }}</div>
                <div v-else class="text-text-muted">—</div>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <Link :href="`/admin/verification/applications/${a.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    View
                  </Link>
                  <Link
                    v-if="can.finance_view"
                    :href="`/finance/applications/${a.id}/track`"
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
  </AdminLayout>
</template>

