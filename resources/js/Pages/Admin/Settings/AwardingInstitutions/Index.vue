<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminExcelImportModal from '@/Components/AdminExcelImportModal.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { Building2, FileSpreadsheet, Plus, Search } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import Swal from 'sweetalert2'

const props = defineProps<{
  institutions: any
  countries: Array<{ id: number; name: string; iso_code: string }>
  filters: { q: string; country_id: string | null; active: string | null; missing_statement: string | null }
  can: { create: boolean; edit: boolean; delete: boolean }
  excel_import: { template_url: string; import_url: string; can_import: boolean }
}>()

const q = ref(props.filters.q ?? '')
const countryId = ref<string>(props.filters.country_id ?? '')
const active = ref<string>(props.filters.active ?? '')
const missingStatement = ref<string>(props.filters.missing_statement ?? '')
const excelImportOpen = ref(false)

watch([q, countryId, active, missingStatement], () => {
  router.get(
    '/admin/settings/awarding-institutions',
    {
      q: q.value,
      country_id: countryId.value || null,
      active: active.value || null,
      missing_statement: missingStatement.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

async function deactivate(id: number) {
  const res = await Swal.fire({
    icon: 'warning',
    title: 'Deactivate institution?',
    html: `<div class="text-left text-sm text-text-muted">This institution will no longer be available for new applicant selections. Existing applications and learner records will remain unchanged.</div>`,
    showCancelButton: true,
    confirmButtonText: 'Deactivate',
    cancelButtonText: 'Cancel',
  })
  if (!res.isConfirmed) return
  router.post(`/admin/settings/awarding-institutions/${id}/deactivate`, {}, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Building2 class="h-4 w-4" aria-hidden="true" />
          System Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Awarding Institutions</h1>
        <p class="mt-1 text-sm text-text-muted">Manage awarding institutions linked to countries.</p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
          @click="excelImportOpen = true"
        >
          <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
          Excel import
        </button>
        <Link v-if="can.create" href="/admin/settings/awarding-institutions/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add institution
        </Link>
      </div>
    </div>

    <AdminExcelImportModal
      v-model="excelImportOpen"
      title="Import awarding institutions from Excel"
      description="Columns: name, country_iso_code (3-letter ISO matching Countries), is_active (1/0), sort_order. Existing rows match country + exact institution name."
      :template-url="excel_import.template_url"
      :import-url="excel_import.import_url"
      :can-import="excel_import.can_import"
    />

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">All institutions</div>
            <div class="mt-1 text-xs text-text-muted">Search by name and filter by country.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search..." />
            </div>
            <select v-model="countryId" class="zaqa-input h-10">
              <option value="">All countries</option>
              <option v-for="c in countries" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
            <select v-model="active" class="zaqa-input h-10">
              <option value="">All</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
            <select v-model="missingStatement" class="zaqa-input h-10">
              <option value="">All statements</option>
              <option value="1">Missing statement</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="institutions.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No institutions found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust your filters or add a new institution.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Institution</th>
              <th class="px-5 py-3 text-left">Country</th>
              <th class="px-5 py-3 text-left">Statement</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="i in institutions.data" :key="i.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ i.name }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ i.country?.name ?? '—' }}</td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="i.has_accreditation_statement ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ i.has_accreditation_statement ? 'On file' : 'Missing' }}
                </span>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="i.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ i.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <Link :href="i.show_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">View</Link>
                  <Link v-if="can.edit" :href="`/admin/settings/awarding-institutions/${i.id}/edit`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    Edit
                  </Link>
                  <button
                    v-if="can.delete && i.is_active"
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                    @click="deactivate(i.id)"
                  >
                    Deactivate
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="institutions" label="institutions" />
    </div>
  </AdminLayout>
</template>
