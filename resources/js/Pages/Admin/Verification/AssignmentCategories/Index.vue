<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import { Link, router } from '@inertiajs/vue3'
import { Plus, Search, ShieldCheck } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  categories: any
  filters: { q: string; type?: string | null; active?: string | null }
  can: { manage: boolean }
}>()

const q = ref(props.filters.q ?? '')
const type = ref<string>(props.filters.type ?? '')
const active = ref<string>(props.filters.active ?? '')

watch([q, type, active], () => {
  router.get(
    '/admin/verification/assignment-categories',
    { q: q.value, type: type.value || null, active: active.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function typeLabel(t: string): string {
  if (t === 'foreign_country') return 'Foreign (Country)'
  if (t === 'local_institution') return 'Local (Institution)'
  return t
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Assignment Categories</h1>
        <p class="mt-1 text-sm text-text-muted">Manage category-based Level 1 auto-assignment routing.</p>
      </div>
      <div class="flex items-center gap-2">
        <Link v-if="can.manage" href="/admin/verification/assignment-categories/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          New category
        </Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Categories</div>
            <div class="mt-1 text-xs text-text-muted">Filter and open categories to manage officers and availability.</div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search name..." />
            </div>
            <select v-model="type" class="zaqa-input h-10">
              <option value="">All types</option>
              <option value="foreign_country">Foreign (Country)</option>
              <option value="local_institution">Local (Institution)</option>
            </select>
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
          <div class="text-sm font-semibold text-text-primary">No categories found</div>
          <div class="mt-1 text-xs text-text-muted">Create categories for countries (foreign) and institutions (local).</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Category</th>
              <th class="px-5 py-3 text-left">Mappings</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-left">Officers</th>
              <th class="px-5 py-3 text-left">Last assigned</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="c in categories.data" :key="c.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ c.name }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ typeLabel(c.type) }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">
                <div class="text-xs text-text-muted">{{ (c.mapped_count ?? 0) }} mapped</div>
                <div class="mt-0.5 text-xs font-semibold text-text-primary">
                  <span v-if="(c.mapped_sample ?? []).length === 0">—</span>
                  <span v-else>
                    {{ (c.mapped_sample ?? []).join(', ') }}
                    <span v-if="(c.mapped_count ?? 0) > (c.mapped_sample ?? []).length" class="text-text-muted">
                      +{{ (c.mapped_count ?? 0) - (c.mapped_sample ?? []).length }} more
                    </span>
                  </span>
                </div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="c.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ c.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ c.members_count }}</td>
              <td class="px-5 py-3 text-text-primary">
                <div class="text-xs text-text-muted">{{ c.last_assigned_user?.name ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">{{ c.last_assigned_at ? new Date(c.last_assigned_at).toLocaleString() : '' }}</div>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <Link :href="c.show_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Open</Link>
                  <Link :href="c.edit_url" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">Edit</Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="categories" label="categories" />
    </div>
  </AdminLayout>
</template>
