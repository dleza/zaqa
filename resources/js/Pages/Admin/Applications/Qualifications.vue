<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { GraduationCap, Search } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  qualifications: any
  filters: { q: string; status?: string | null }
}>()

const q = ref(props.filters.q ?? '')
const status = ref<string>(props.filters.status ?? '')

watch([q, status], () => {
  router.get(
    '/admin/applications/qualifications',
    { q: q.value || null, status: status.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

const statusBadgeClass = computed(() => {
  return (value: string | null | undefined) => {
    const s = (value ?? '').toString()
    if (['approved_for_certificate', 'certificate_issued', 'closed'].includes(s)) return 'zaqa-badge-success'
    if (s === 'rejected') return 'zaqa-badge-danger'
    return 'zaqa-badge-secondary'
  }
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
          <GraduationCap class="h-4 w-4" aria-hidden="true" />
          Applications
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Qualifications</h1>
        <p class="mt-1 text-sm text-text-muted">Qualifications that have already been concluded with an approved or rejected outcome.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/applications" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Applications pool</Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Closed qualifications</div>
            <div class="mt-1 text-xs text-text-muted">Search by application #, verification ref, holder name, qualification title, or institution.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="status" class="zaqa-input h-10">
              <option value="">All outcomes</option>
              <option value="approved_for_certificate">Approved for certificate</option>
              <option value="certificate_issued">Certificate issued</option>
              <option value="closed">Closed</option>
              <option value="rejected">Rejected</option>
            </select>
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search qualifications..." />
            </div>
          </div>
        </div>
      </div>

      <div v-if="qualifications.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No closed qualifications found</div>
          <div class="mt-1 text-xs text-text-muted">Approved and rejected qualifications will appear here after they are processed.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Outcome</th>
              <th class="px-5 py-3 text-left">Application</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-left">Type</th>
              <th class="px-5 py-3 text-left">Award date</th>
              <th class="px-5 py-3 text-left">Updated</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="qualification in qualifications.data" :key="qualification.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ qualification.title ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted font-mono">{{ qualification.verification_reference_number ?? 'No verification ref' }}</div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(qualification.verification_state)">{{ qualification.verification_state ?? '—' }}</span>
              </td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ qualification.application_number ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ qualification.application_status ?? '—' }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ qualification.holder_name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ qualification.awarding_institution ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ qualification.qualification_type ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ qualification.award_date ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ formatDateTime(qualification.updated_at) }}</td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <Link :href="`/admin/verification/qualifications/${qualification.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    View
                  </Link>
                  <Link
                    v-if="qualification.application_id"
                    :href="`/admin/verification/applications/${qualification.application_id}`"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                  >
                    Application
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminPagination :links="qualifications.links ?? []" />
  </AdminLayout>
</template>
