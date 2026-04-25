<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminViewModal from '@/Components/AdminViewModal.vue'
import { Link, router } from '@inertiajs/vue3'
import { Building2, Plus, Search } from 'lucide-vue-next'
import { ref, watch } from 'vue'

const props = defineProps<{
  institutions: any
  countries: Array<{ id: number; name: string; iso_code: string }>
  filters: { q: string; country_id: string | null; active: string | null }
  can: { create: boolean; edit: boolean; delete: boolean }
}>()

const q = ref(props.filters.q ?? '')
const countryId = ref<string>(props.filters.country_id ?? '')
const active = ref<string>(props.filters.active ?? '')
const viewOpen = ref(false)
const selected = ref<any | null>(null)

watch([q, countryId, active], () => {
  router.get(
    '/admin/settings/awarding-institutions',
    { q: q.value, country_id: countryId.value || null, active: active.value || null },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})

function deactivate(id: number) {
  if (!confirm('Deactivate this awarding institution?')) return
  router.delete(`/admin/settings/awarding-institutions/${id}`, { preserveScroll: true })
}

function openView(i: any) {
  selected.value = i
  viewOpen.value = true
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

      <div class="flex items-center gap-2">
        <Link v-if="can.create" href="/admin/settings/awarding-institutions/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add institution
        </Link>
      </div>
    </div>

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
                <span class="zaqa-badge" :class="i.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ i.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs" @click="openView(i)">
                    View
                  </button>
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
    </div>

    <AdminViewModal
      v-model="viewOpen"
      :title="selected ? `Awarding Institution: ${selected.name}` : 'Awarding Institution'"
      description="Quick view (read-only)."
    >
      <div v-if="selected" class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-border bg-surface-muted p-4 sm:col-span-2">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Name</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.name }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Country</div>
          <div class="mt-2 text-sm font-semibold text-text-primary">{{ selected.country?.name ?? '—' }}</div>
          <div class="mt-1 text-xs text-text-muted">{{ selected.country?.iso_code ?? '' }}</div>
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

