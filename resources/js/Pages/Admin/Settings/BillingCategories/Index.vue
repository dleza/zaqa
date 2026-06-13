<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminViewModal from '@/Components/AdminViewModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { Plus, Search, Tags } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  categories: any
  filters: { q: string; active: string | null }
  can: { create: boolean; edit: boolean; delete: boolean }
}>()

const q = ref(props.filters.q ?? '')
const active = ref<string>(props.filters.active ?? '')
const viewOpen = ref(false)
const selected = ref<any | null>(null)

watch([q, active], () => {
  router.get(
    '/admin/settings/billing-categories',
    { q: q.value, active: active.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function deactivate(id: number, isSystem: boolean) {
  if (isSystem) return
  if (!confirm('Deactivate this billing category? It will no longer appear in fee and qualification type forms.')) return
  router.delete(`/admin/settings/billing-categories/${id}`, { preserveScroll: true })
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
          <Tags class="h-4 w-4" aria-hidden="true" />
          System Settings
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Billing Categories</h1>
        <p class="mt-1 text-sm text-text-muted">
          Manage fee and processing-time groupings used by qualification types and fee structures.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Link v-if="can.create" href="/admin/settings/billing-categories/create" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add category
        </Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">All categories</div>
            <div class="mt-1 text-xs text-text-muted">Search by name or code.</div>
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

      <div v-if="categories.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No billing categories found</div>
          <div class="mt-1 text-xs text-text-muted">Adjust your filters or add a new category.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Name</th>
              <th class="px-5 py-3 text-left">Code</th>
              <th class="px-5 py-3 text-left">Processing days</th>
              <th class="px-5 py-3 text-left">Usage</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="c in categories.data" :key="c.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ c.name }}</div>
                <div v-if="c.is_system" class="mt-0.5 text-xs text-text-muted">System category</div>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-text-primary">{{ c.code }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div class="text-xs">Local: {{ c.local_processing_days ?? '—' }}</div>
                <div class="text-xs">Foreign: {{ c.foreign_processing_days ?? '—' }}</div>
              </td>
              <td class="px-5 py-3 text-xs text-text-primary">
                {{ c.qualification_types_count }} qual. types · {{ c.fee_structures_count }} fee versions
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="c.is_active ? 'zaqa-badge-success' : 'zaqa-badge-secondary'">
                  {{ c.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="flex justify-end gap-2">
                  <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="openView(c)">View</button>
                  <Link v-if="can.edit" :href="`/admin/settings/billing-categories/${c.id}/edit`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Edit</Link>
                  <button
                    v-if="can.delete && c.is_active && !c.is_system"
                    type="button"
                    class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs text-danger"
                    @click="deactivate(c.id, c.is_system)"
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

    <AdminViewModal v-model="viewOpen" :title="selected ? selected.name : 'Billing category'" description="Quick view (read-only).">
      <div v-if="selected" class="space-y-4 text-sm">
        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Code</div>
          <div class="mt-2 font-mono text-text-primary">{{ selected.code }}</div>
        </div>
        <div v-if="selected.description">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Description</div>
          <div class="mt-2 text-text-primary">{{ selected.description }}</div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Local processing days</div>
            <div class="mt-2 text-text-primary">{{ selected.local_processing_days ?? '—' }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Foreign processing days</div>
            <div class="mt-2 text-text-primary">{{ selected.foreign_processing_days ?? '—' }}</div>
          </div>
        </div>
        <div>
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Sort order</div>
          <div class="mt-2 text-text-primary">{{ selected.sort_order }}</div>
        </div>
      </div>
    </AdminViewModal>
  </AdminLayout>
</template>
