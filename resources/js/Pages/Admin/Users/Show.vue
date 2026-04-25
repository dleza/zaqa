<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ShieldCheck, User as UserIcon } from 'lucide-vue-next'

const props = defineProps<{
  user: any
  activity: Array<any>
}>()

const blockForm = useForm({})
const unblockForm = useForm({})

function block() {
  blockForm.post(`/admin/users/${props.user.id}/block`, { preserveScroll: true })
}

function unblock() {
  unblockForm.post(`/admin/users/${props.user.id}/unblock`, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <ShieldCheck class="h-4 w-4" aria-hidden="true" />
          User management
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">User profile</h1>
        <p class="mt-1 text-sm text-text-muted">Account details and staff activity.</p>
      </div>

      <div class="flex items-center gap-2">
        <Link href="/admin/users" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Back</Link>
        <button
          v-if="user.disabled_at"
          type="button"
          class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          :disabled="unblockForm.processing"
          @click="unblock"
        >
          Unblock user
        </button>
        <button
          v-else
          type="button"
          class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
          :disabled="blockForm.processing"
          @click="block"
        >
          Block user
        </button>
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-1">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-surface-muted">
              <UserIcon class="h-5 w-5 text-text-muted" aria-hidden="true" />
            </div>
            <div class="min-w-0">
              <div class="truncate text-base font-semibold text-text-primary">{{ user.name }}</div>
              <div class="mt-0.5 truncate text-xs text-text-muted">{{ user.email }}</div>
            </div>
          </div>

          <div class="mt-5 grid gap-3 text-sm">
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">First name</div>
              <div class="font-semibold text-text-primary">{{ user.first_name ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Last name</div>
              <div class="font-semibold text-text-primary">{{ user.last_name ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Phone</div>
              <div class="font-semibold text-text-primary">{{ user.phone_primary ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Department</div>
              <div class="font-semibold text-text-primary">{{ user.department?.name ?? '—' }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Status</div>
              <span
                class="zaqa-badge"
                :class="user.disabled_at ? 'zaqa-badge-danger' : (user.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning')"
              >
                {{ user.disabled_at ? 'Disabled' : (user.is_active ? 'Active' : 'Inactive') }}
              </span>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Last login</div>
              <div class="font-semibold text-text-primary">{{ user.last_login_at ?? '—' }}</div>
            </div>
          </div>

          <div class="mt-5">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Roles</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="r in (user.roles ?? [])" :key="r" class="zaqa-badge">{{ r }}</span>
              <span v-if="(user.roles ?? []).length === 0" class="text-xs text-text-muted">—</span>
            </div>
          </div>
        </div>
      </div>

      <div class="lg:col-span-2">
        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="text-sm font-semibold text-text-primary">Recent activity</div>
            <div class="mt-1 text-xs text-text-muted">Latest 25 actions captured by the audit log.</div>
          </div>

          <div v-if="activity.length === 0" class="px-5 py-6">
            <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
              <div class="text-sm font-semibold text-text-primary">No activity yet</div>
              <div class="mt-1 text-xs text-text-muted">Actions performed by this user will appear here.</div>
            </div>
          </div>

          <div v-else class="divide-y divide-border/60">
            <div v-for="a in activity" :key="a.id" class="px-5 py-4">
              <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <div class="truncate text-sm font-semibold text-text-primary">{{ a.message }}</div>
                  <div class="mt-0.5 text-xs text-text-muted">
                    <span class="font-semibold text-text-primary">{{ a.module }}</span>
                    <span class="mx-1">•</span>
                    <span class="font-mono">{{ a.event_type }}</span>
                  </div>
                </div>
                <div class="text-xs text-text-muted">
                  <div class="text-right">{{ a.created_at ?? '—' }}</div>
                  <div class="mt-0.5 text-right">IP {{ a.ip_address ?? '—' }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

