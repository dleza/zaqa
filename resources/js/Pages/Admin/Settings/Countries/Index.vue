<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminExcelImportModal from '@/Components/AdminExcelImportModal.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import AdminViewModal from '@/Components/AdminViewModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { FileSpreadsheet, Globe, Plus, Search } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  countries: any
  filters: { q: string; active: string | null }
  can: { create: boolean; edit: boolean; delete: boolean }
  excel_import: { template_url: string; import_url: string; can_import: boolean }
}>()

const q = ref(props.filters.q ?? '')
const active = ref<string>(props.filters.active ?? '')
const viewOpen = ref(false)
const selected = ref<any | null>(null)
const excelImportOpen = ref(false)

watch([q, active], () => {
  router.get(
    '/admin/settings/countries',
    { q: q.value, active: active.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function deactivate(id: number) {
  if (!confirm('Deactivate this country?')) return
  router.delete(`/admin/settings/countries/${id}`, { preserveScroll: true })
}

function openView(c: any) {
  selected.value = c
  viewOpen.value = true
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Globe class="h-4 w-4" aria-hidden="true" />
          System Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Countries</h1>
        <p class="mt-1 text-sm text-text-muted">Manage country master data used across the platform.</p>
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
        <Link v-if="can.create" href="/admin/settings/countries/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add country
        </Link>
      </div>
    </div>

    <AdminExcelImportModal
      v-model="excelImportOpen"
      title="Import countries from Excel"
      description="Columns: name, iso_code (3 letters), is_active (1/0), sort_order. Existing ISO codes are updated if you have edit permission."
      :template-url="excel_import.template_url"
      :import-url="excel_import.import_url"
      :can-import="excel_import.can_import"
    />

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">All countries</div>
            <div class="mt-1 text-xs text-text-muted">Search by name or ISO3 code.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search..." />
            </div>
            <select v-model="active" class="zaqa-input h-10">
              <option value="">All</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="countries.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No countries found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust your filters or add a new country.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Name</th>
              <th class="px-5 py-3 text-left">ISO3</th>
              <th class="px-5 py-3 text-left">Sort</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="c in countries.data" :key="c.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ c.name }}</div>
              </td>
              <td class="px-5 py-3 font-mono text-text-primary">{{ c.iso_code }}</td>
              <td class="px-5 py-3 text-text-primary">{{ c.sort_order }}</td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="c.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ c.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="openView(c)">
                    View
                  </button>
                  <Link v-if="can.edit" :href="`/admin/settings/countries/${c.id}/edit`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    Edit
                  </Link>
                  <button
                    v-if="can.delete && c.is_active"
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                    @click="deactivate(c.id)"
                  >
                    Deactivate
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="countries" label="countries" />
    </div>

    <AdminViewModal v-model="viewOpen" :title="selected ? `Country: ${selected.name}` : 'Country'" description="Quick view (read-only).">
      <div v-if="selected" class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Name</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.name }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">ISO3</div>
          <div class="mt-2 font-mono text-sm font-semibold text-text-primary">{{ selected.iso_code }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Sort order</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.sort_order }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Status</div>
          <div class="mt-2">
            <span class="zaqa-badge" :class="selected.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
              {{ selected.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>
      </div>
    </AdminViewModal>
  </AdminLayout>
</template>

