<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminExcelImportModal from '@/Components/AdminExcelImportModal.vue'
import AdminViewModal from '@/Components/AdminViewModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { BookOpen, FileSpreadsheet, GraduationCap, Plus, Search } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  titles: any
  filters: { q: string; active: string | null; qualification_type_id: string | null }
  qualificationTypes: Array<{ id: number; name: string; zqf_level_code: string }>
  can: { create: boolean; edit: boolean; delete: boolean }
  excel_import: { template_url: string; import_url: string; can_import: boolean }
}>()

const q = ref(props.filters.q ?? '')
const active = ref<string>(props.filters.active ?? '')
const qualificationTypeId = ref<string>(props.filters.qualification_type_id ?? '')
const viewOpen = ref(false)
const selected = ref<any | null>(null)
const excelImportOpen = ref(false)

watch([q, active, qualificationTypeId], () => {
  router.get(
    '/admin/settings/qualification-titles',
    {
      q: q.value,
      active: active.value || null,
      qualification_type_id: qualificationTypeId.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function deactivate(id: number) {
  if (!confirm('Deactivate this qualification title? Applicants will no longer be able to select it.')) return
  router.delete(`/admin/settings/qualification-titles/${id}`, { preserveScroll: true })
}

function openView(t: any) {
  selected.value = t
  viewOpen.value = true
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <GraduationCap class="h-4 w-4" aria-hidden="true" />
          System settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Qualification Titles</h1>
        <p class="mt-1 text-sm text-text-muted">
          Managed titles applicants choose when adding qualifications. Learner records remain used for verification matching only.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="excel_import.can_import"
          type="button"
          class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm"
          @click="excelImportOpen = true"
        >
          <FileSpreadsheet class="h-4 w-4" aria-hidden="true" />
          Excel import
        </button>
        <Link v-if="can.create" href="/admin/settings/qualification-titles/create" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add title
        </Link>
      </div>
    </div>

    <AdminExcelImportModal
      v-model="excelImportOpen"
      title="Import qualification titles from Excel"
      description="Columns: title, qualification_type (optional), is_active (1/0), sort_order, description. Existing rows match by normalized title name."
      :template-url="excel_import.template_url"
      :import-url="excel_import.import_url"
      :can-import="excel_import.can_import"
    />

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">All titles</div>
            <div class="mt-1 text-xs text-text-muted">Search by title name.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search..." />
            </div>
            <select v-model="active" class="zaqa-input h-10">
              <option value="">All statuses</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
            <select v-model="qualificationTypeId" class="zaqa-input h-10">
              <option value="">All types</option>
              <option v-for="t in qualificationTypes" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="titles.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No qualification titles found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust your filters or add a new title.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Title</th>
              <th class="px-5 py-3 text-left">Type</th>
              <th class="px-5 py-3 text-left">Institutions</th>
              <th class="px-5 py-3 text-left">Usage</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="t in titles.data" :key="t.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ t.name }}</div>
                <div class="text-xs text-text-muted">Sort {{ t.sort_order }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ t.qualification_type?.name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ t.linked_institutions_count }}</td>
              <td class="px-5 py-3 text-text-primary">{{ t.usage_count }}</td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="t.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ t.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="openView(t)">View</button>
                  <Link v-if="can.edit" :href="`/admin/settings/qualification-titles/${t.id}/edit`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    Edit
                  </Link>
                  <button
                    v-if="can.delete && t.is_active"
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                    @click="deactivate(t.id)"
                  >
                    Deactivate
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminViewModal v-model="viewOpen" :title="selected ? `Title: ${selected.name}` : 'Qualification title'" description="Quick view (read-only).">
      <div v-if="selected" class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-border bg-surface-muted p-4 sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Title</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.name }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Qualification type</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.qualification_type?.name ?? '—' }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Linked institutions</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.linked_institutions_count }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Usage</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.usage_count }} qualification(s)</div>
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
