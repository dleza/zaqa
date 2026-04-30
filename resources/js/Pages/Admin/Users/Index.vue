<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminPagination from '@/Components/AdminPagination.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ArrowDown, ArrowUp, ArrowUpDown, Search, Users } from 'lucide-vue-next'
import type { Component } from 'vue'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  users: {
    data: Array<any>
    links: Array<any>
    meta?: any
    current_page?: number
    last_page?: number
  }
  filters: { q?: string; sort?: string; dir?: 'asc' | 'desc' }
}>()

const page = usePage()
const permissions = computed<string[]>(() => ((page.props as any).auth?.permissions ?? []) as string[])
const canCreate = computed(() => permissions.value.includes('admin.users.create'))

const q = ref(props.filters.q ?? '')
const sort = ref(props.filters.sort ?? 'id')
const dir = ref<'asc' | 'desc'>(props.filters.dir === 'asc' ? 'asc' : 'desc')

function applyFilters() {
  router.get(
    '/admin/users',
    { q: q.value || null, sort: sort.value, dir: dir.value },
    { preserveState: true, replace: true, preserveScroll: true },
  )
}

let debounce: number | null = null
watch(q, () => {
  if (debounce) window.clearTimeout(debounce)
  debounce = window.setTimeout(() => applyFilters(), 250)
})

watch([sort, dir], () => applyFilters())

function toggleSort(field: string) {
  if (sort.value === field) {
    dir.value = dir.value === 'asc' ? 'desc' : 'asc'
    return
  }

  sort.value = field
  dir.value = 'asc'
}

function sortIcon(field: string): Component {
  if (sort.value !== field) return ArrowUpDown
  return dir.value === 'asc' ? ArrowUp : ArrowDown
}

function ariaSort(field: string) {
  if (sort.value !== field) return 'none'
  return dir.value === 'asc' ? 'ascending' : 'descending'
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Users class="h-4 w-4" aria-hidden="true" />
          User management
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Users</h1>
        <p class="mt-1 text-sm text-text-muted">Staff accounts that manage applicants.</p>
      </div>

      <div class="flex items-center gap-2">
        <Link v-if="canCreate" href="/admin/users/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          Add new user
        </Link>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="text-sm font-semibold text-text-primary">Staff users</div>
            <div class="mt-1 text-xs text-text-muted">Search by name, email, or phone.</div>
          </div>
          <div class="relative">
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
            <input v-model="q" class="zaqa-input h-10 pl-9" placeholder="Search..." />
          </div>
        </div>
      </div>

      <div v-if="users.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No users found</div>
          <div class="mt-1 text-xs text-text-muted">Users will appear here when created.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left" :aria-sort="ariaSort('name')">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 transition hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
                  @click="toggleSort('name')"
                >
                  Name
                  <component :is="sortIcon('name')" class="h-3.5 w-3.5 opacity-70" aria-hidden="true" />
                </button>
              </th>
              <th class="px-5 py-3 text-left" :aria-sort="ariaSort('email')">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 transition hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
                  @click="toggleSort('email')"
                >
                  Email
                  <component :is="sortIcon('email')" class="h-3.5 w-3.5 opacity-70" aria-hidden="true" />
                </button>
              </th>
              <th class="px-5 py-3 text-left" :aria-sort="ariaSort('phone_primary')">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 transition hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
                  @click="toggleSort('phone_primary')"
                >
                  Phone
                  <component :is="sortIcon('phone_primary')" class="h-3.5 w-3.5 opacity-70" aria-hidden="true" />
                </button>
              </th>
              <th class="px-5 py-3 text-left">Roles</th>
              <th class="px-5 py-3 text-left" :aria-sort="ariaSort('status')">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 transition hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
                  @click="toggleSort('status')"
                >
                  Status
                  <component :is="sortIcon('status')" class="h-3.5 w-3.5 opacity-70" aria-hidden="true" />
                </button>
              </th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="u in users.data" :key="u.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ u.name }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Created {{ u.created_at ?? '—' }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ u.email }}</td>
              <td class="px-5 py-3 text-text-primary">{{ u.phone_primary ?? '—' }}</td>
              <td class="px-5 py-3">
                <div class="flex flex-wrap gap-2">
                  <span v-for="r in (u.roles ?? [])" :key="r" class="zaqa-badge">{{ r }}</span>
                  <span v-if="(u.roles ?? []).length === 0" class="text-xs text-text-muted">—</span>
                </div>
              </td>
              <td class="px-5 py-3">
                <span class="zaqa-badge" :class="u.disabled_at ? 'zaqa-badge-danger' : (u.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning')">
                  {{ u.disabled_at ? 'Disabled' : (u.is_active ? 'Active' : 'Inactive') }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="`/admin/users/${u.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                  View
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminPagination :links="users.links ?? []" />
  </AdminLayout>
</template>
