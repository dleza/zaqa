<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminExcelImportModal from '@/Components/AdminExcelImportModal.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { BadgeCheck, FileSpreadsheet, Search } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  certificates: any
  filters: { q: string; status?: string; type?: string }
  status_options: Array<{ value: string; label: string }>
  type_options: Array<{ value: string; label: string }>
  excel_import: { template_url: string; import_url: string; can_import: boolean }
}>()

const q = ref(props.filters.q ?? '')
const status = ref(props.filters.status ?? '')
const type = ref(props.filters.type ?? '')
const excelImportOpen = ref(false)

const statusBadgeClass = computed(() => {
  return (value: string | null | undefined) => {
    const s = (value ?? '').toString()
    if (s === 'issued') return 'zaqa-badge-success'
    if (s === 'reissued') return 'zaqa-badge-warning'
    if (s === 'revoked') return 'zaqa-badge-danger'
    return 'zaqa-badge-secondary'
  }
})

watch([q, status, type], () => {
  router.get(
    '/admin/certificates',
    { q: q.value || null, status: status.value || null, type: type.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function formatIssued(iso: string | null | undefined) {
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
          <BadgeCheck class="h-4 w-4" aria-hidden="true" />
          Certificates
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">CVEQ registry</h1>
        <p class="mt-1 text-sm text-text-muted">
          Search issued certificates by CVEQ number, qualification title, or holder name. Open a row to download, verify, or
          revoke.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
          @click="excelImportOpen = true"
        >
          <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
          Bulk issue (Excel)
        </button>
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Verification pool</Link>
      </div>
    </div>

    <AdminExcelImportModal
      v-model="excelImportOpen"
      title="Bulk issue CVEQs from Excel"
      description="One column: qualification_id (internal ID from each qualification task). Each row runs the same checks as Issue Certificate (paid, approved for certificate). Applicants are emailed when issuance succeeds."
      :template-url="excel_import.template_url"
      :import-url="excel_import.import_url"
      :can-import="excel_import.can_import"
    />

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Issued certificates</div>
            <div class="mt-1 text-xs text-text-muted">Includes superseded and revoked rows for full certificate history.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select v-model="type" class="zaqa-input h-10 min-w-[180px]">
              <option v-for="opt in type_options" :key="opt.value || 'all-types'" :value="opt.value">{{ opt.label }}</option>
            </select>
            <select v-model="status" class="zaqa-input h-10 min-w-[200px]">
              <option v-for="opt in status_options" :key="opt.value || 'all'" :value="opt.value">{{ opt.label }}</option>
            </select>
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search…" />
            </div>
          </div>
        </div>
      </div>

      <div v-if="certificates.data.length === 0" class="px-5 py-8">
        <div class="rounded-2xl border border-border bg-surface-muted p-8 text-center">
          <div class="text-sm font-semibold text-text-primary">No certificates found</div>
          <div class="mt-1 text-xs text-text-muted">
            Nothing matches your filters yet — certificates appear here after staff issue a CVEQ for a verified, paid
            qualification.
          </div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">CVEQ number</th>
              <th class="px-5 py-3 text-left">Type</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Issued</th>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in certificates.data" :key="row.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <span class="font-mono font-semibold text-text-primary">{{ row.certificate_number }}</span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge zaqa-badge-secondary">{{ row.certificate_type_label ?? 'Verification' }}</span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="statusBadgeClass(row.status)">{{ row.status_label ?? row.status }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ formatIssued(row.issued_at) }}</td>
              <td class="max-w-[280px] px-5 py-3 text-text-primary">
                <span class="line-clamp-2">{{ row.qualification_title ?? '—' }}</span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.holder_name ?? '—' }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="row.show_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">View</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="certificates.data.length > 0" class="border-t border-border px-5 py-4">
        <AdminPagination :links="certificates.links ?? []" />
      </div>
    </div>
  </AdminLayout>
</template>
