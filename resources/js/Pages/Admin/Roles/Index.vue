<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { ShieldCheck } from 'lucide-vue-next'

defineProps<{
  roles: Array<{ id: number; name: string; permissions_count: number }>
  permissions: Array<{ name: string }>
  can_manage: boolean
}>()
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          User management
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Roles & permissions</h1>
        <p class="mt-1 text-sm text-text-muted">Manage role definitions and permission assignments.</p>
      </div>

      <div class="flex items-center gap-2">
        <Link v-if="can_manage" href="/admin/roles/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
          Create role
        </Link>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="text-sm font-semibold text-text-primary">Roles</div>
            <div class="mt-1 text-xs text-text-muted">Select a role to view and edit its permissions.</div>
          </div>

          <div v-if="roles.length === 0" class="px-5 py-6">
            <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
              <div class="text-sm font-semibold text-text-primary">No roles found</div>
              <div class="mt-1 text-xs text-text-muted">Seeders will create baseline roles automatically.</div>
            </div>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-5 py-3 text-left">Role</th>
                  <th class="px-5 py-3 text-left">Permissions</th>
                  <th class="px-5 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="r in roles" :key="r.id" class="hover:bg-surface-muted/60">
                  <td class="px-5 py-3">
                    <div class="font-semibold text-text-primary">{{ r.name }}</div>
                  </td>
                  <td class="px-5 py-3 text-text-primary">
                    {{ r.permissions_count }}
                  </td>
                  <td class="px-5 py-3 text-right">
                    <Link v-if="can_manage" :href="`/admin/roles/${r.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                      Edit
                    </Link>
                    <span v-else class="text-xs text-text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="lg:col-span-1">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Available permissions</div>
          <div class="mt-1 text-xs text-text-muted">These can be assigned to roles.</div>

          <div class="mt-4 max-h-[28rem] space-y-2 overflow-auto pr-2">
            <div v-for="p in permissions" :key="p.name" class="flex items-center justify-between gap-3 rounded-xl border border-border bg-surface-muted px-3 py-2">
              <div class="min-w-0 truncate text-xs font-semibold text-text-primary">{{ p.name }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

