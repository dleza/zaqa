<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { Users } from 'lucide-vue-next'

defineProps<{
  applicants: {
    data: Array<any>
    links: Array<any>
    meta?: any
    current_page?: number
    last_page?: number
  }
}>()
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <Users class="h-4 w-4" aria-hidden="true" />
          User management
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Applicants</h1>
        <p class="mt-1 text-sm text-text-muted">Registered applicant accounts (individuals and institutions).</p>
      </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="text-sm font-semibold text-text-primary">All applicants</div>
        <div class="mt-1 text-xs text-text-muted">Showing latest applicants first.</div>
      </div>

      <div v-if="applicants.data.length === 0" class="px-5 py-6">
        <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
          <div class="text-sm font-semibold text-text-primary">No applicants found</div>
          <div class="mt-1 text-xs text-text-muted">Applicants will appear here when they register.</div>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th class="px-5 py-3 text-left">Name</th>
              <th class="px-5 py-3 text-left">Email</th>
              <th class="px-5 py-3 text-left">Phone</th>
              <th class="px-5 py-3 text-left">Type</th>
              <th class="px-5 py-3 text-left">Role</th>
              <th class="px-5 py-3 text-left">Status</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="u in applicants.data" :key="u.id" class="hover:bg-surface-muted/60">
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ u.name }}</div>
                <div class="mt-0.5 text-xs text-text-muted">Created {{ u.created_at ?? '—' }}</div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ u.email }}</td>
              <td class="px-5 py-3 text-text-primary">{{ u.phone_primary ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ u.applicant_type ?? '—' }}</td>
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
                <Link :href="`/admin/applicants/${u.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                  View
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>

