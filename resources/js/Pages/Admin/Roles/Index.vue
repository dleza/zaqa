<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import PermissionSelector from '@/Components/Admin/PermissionSelector.vue'
import { Link } from '@inertiajs/vue3'
import { Ban, KeyRound, ShieldCheck, Sparkles, Users } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  roles: Array<{ id: number; name: string; permissions_count: number }>
  permissions: Array<{ name: string }>
  can_manage: boolean
}>()

const totalRoles = computed(() => props.roles.length)
const totalPermissions = computed(() => props.permissions.length)
const highestPermissionRole = computed(() => props.roles.reduce((top, role) => {
  if (!top || role.permissions_count > top.permissions_count) {
    return role
  }

  return top
}, null as null | { id: number; name: string; permissions_count: number }))
const rolesWithoutPermissions = computed(() => props.roles.filter((role) => role.permissions_count === 0).length)

const summaryCards = computed(() => [
  {
    key: 'roles',
    label: 'Total roles',
    value: totalRoles.value,
    meta: 'Defined staff and system roles',
    icon: Users,
  },
  {
    key: 'permissions',
    label: 'Total permissions',
    value: totalPermissions.value,
    meta: 'Assignable capability entries',
    icon: KeyRound,
  },
  {
    key: 'highest',
    label: 'Highest permission role',
    value: highestPermissionRole.value?.name ?? '—',
    meta: highestPermissionRole.value ? `${highestPermissionRole.value.permissions_count} permissions` : 'No roles available',
    icon: ShieldCheck,
  },
  {
    key: 'empty',
    label: 'Roles without permissions',
    value: rolesWithoutPermissions.value,
    meta: rolesWithoutPermissions.value === 0 ? 'All roles have assignments' : 'Needs review',
    icon: Ban,
  },
])
</script>

<template>
  <AdminLayout>
    <div class="space-y-6">
      <section class="rounded-3xl border border-border/70 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              User management / Roles
            </div>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-text-primary">Roles & permissions</h1>
            <p class="mt-1 text-sm text-text-muted">Manage role definitions and permission assignments.</p>
          </div>

          <Link v-if="can_manage" href="/admin/roles/create" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm">
            Create role
          </Link>
        </div>
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article
          v-for="card in summaryCards"
          :key="card.key"
          class="rounded-2xl border border-border/70 bg-surface p-5 shadow-[0_18px_45px_-30px_rgba(15,23,42,0.35)]"
        >
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">{{ card.label }}</div>
              <div class="mt-3 text-2xl font-semibold text-text-primary">{{ card.value }}</div>
              <div class="mt-1 text-sm text-text-muted">{{ card.meta }}</div>
            </div>
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-surface-muted/70 text-text-primary ring-1 ring-border/60">
              <component :is="card.icon" class="h-5 w-5" aria-hidden="true" />
            </span>
          </div>
        </article>
      </section>

      <section class="rounded-3xl border border-border/70 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Role registry</div>
            <h2 class="mt-2 text-lg font-semibold text-text-primary">Roles</h2>
            <p class="mt-1 text-sm text-text-muted">Open a role to adjust the permissions assigned to it.</p>
          </div>

          <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/45 px-3 py-1 text-xs font-semibold text-text-primary">
            {{ roles.length }} roles
          </span>
        </div>

        <div v-if="roles.length === 0" class="mt-6 rounded-2xl border border-dashed border-border bg-surface-muted/35 px-6 py-12 text-center">
          <Sparkles class="mx-auto h-10 w-10 text-text-muted" aria-hidden="true" />
          <div class="mt-3 text-sm font-semibold text-text-primary">No roles found</div>
          <div class="mt-1 text-sm text-text-muted">Seeders will create baseline roles automatically.</div>
        </div>

        <div v-else class="mt-6">
          <div class="hidden overflow-hidden rounded-2xl border border-border/70 md:block">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted/70 text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                <tr>
                  <th class="px-5 py-4">Role</th>
                  <th class="px-5 py-4">Permission count</th>
                  <th class="px-5 py-4">Notes</th>
                  <th class="px-5 py-4 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="role in roles" :key="role.id" class="transition hover:bg-surface-muted/35">
                  <td class="px-5 py-4">
                    <div class="font-semibold text-text-primary">{{ role.name }}</div>
                  </td>
                  <td class="px-5 py-4">
                    <span class="inline-flex items-center rounded-full border border-brand/15 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">
                      {{ role.permissions_count }}
                    </span>
                  </td>
                  <td class="px-5 py-4 text-sm text-text-muted">
                    {{ role.permissions_count === 0 ? 'No permissions assigned yet' : 'Permission assignments configured' }}
                  </td>
                  <td class="px-5 py-4 text-right">
                    <Link
                      v-if="can_manage"
                      :href="`/admin/roles/${role.id}`"
                      class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                    >
                      Edit
                    </Link>
                    <span v-else class="text-xs text-text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="space-y-3 md:hidden">
            <article
              v-for="role in roles"
              :key="role.id"
              class="rounded-2xl border border-border/70 bg-surface-muted/25 p-4"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="font-semibold text-text-primary">{{ role.name }}</div>
                  <div class="mt-2">
                    <span class="inline-flex items-center rounded-full border border-brand/15 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">
                      {{ role.permissions_count }} permissions
                    </span>
                  </div>
                </div>
                <Link
                  v-if="can_manage"
                  :href="`/admin/roles/${role.id}`"
                  class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs"
                >
                  Edit
                </Link>
              </div>
              <div class="mt-3 text-sm text-text-muted">
                {{ role.permissions_count === 0 ? 'No permissions assigned yet' : 'Permission assignments configured' }}
              </div>
            </article>
          </div>
        </div>
      </section>

      <section class="rounded-3xl border border-border/70 bg-surface p-6 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.38)] sm:p-7">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-text-muted">Permission reference</div>
            <h2 class="mt-2 text-lg font-semibold text-text-primary">Available permissions</h2>
            <p class="mt-1 text-sm text-text-muted">Browse assignable permissions grouped by module.</p>
          </div>

          <span class="inline-flex items-center rounded-full border border-border/70 bg-surface-muted/45 px-3 py-1 text-xs font-semibold text-text-primary">
            {{ permissions.length }} permissions
          </span>
        </div>

        <div class="mt-6">
          <PermissionSelector :permissions="permissions" read-only />
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
