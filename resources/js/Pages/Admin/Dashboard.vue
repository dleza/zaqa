<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { LayoutDashboard } from 'lucide-vue-next'

const page = usePage()
const permissions = computed<string[]>(() => ((page.props as any).auth?.permissions ?? []) as string[])

function canAny(perms: string[]) {
  const set = new Set(permissions.value ?? [])
  return perms.some((p) => set.has(p))
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <LayoutDashboard class="h-4 w-4" aria-hidden="true" />
          Admin portal
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Dashboard</h1>
        <p class="mt-1 text-sm text-text-muted">Welcome to ZAQA back-office operations.</p>
      </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="lg:col-span-2 rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="text-sm font-semibold text-text-primary">Getting started</div>
        <div class="mt-1 text-xs text-text-muted">Use the sidebar to access only the modules you’re permitted to use.</div>

        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Link v-if="canAny(['admin.applications.view'])" href="/admin/applications" class="rounded-2xl border border-border bg-surface-muted p-4 transition hover:bg-surface">
            <div class="text-sm font-semibold text-text-primary">Applications</div>
            <div class="mt-1 text-xs text-text-muted">View applications pool and tracking.</div>
          </Link>
          <Link v-if="canAny(['admin.finance.view'])" href="/finance/payment-proofs" class="rounded-2xl border border-border bg-surface-muted p-4 transition hover:bg-surface">
            <div class="text-sm font-semibold text-text-primary">Finance</div>
            <div class="mt-1 text-xs text-text-muted">Review payment proofs and payment actions.</div>
          </Link>
          <Link v-if="canAny(['admin.users.view', 'admin.roles.view', 'admin.roles.manage'])" href="/admin/users" class="rounded-2xl border border-border bg-surface-muted p-4 transition hover:bg-surface">
            <div class="text-sm font-semibold text-text-primary">User management</div>
            <div class="mt-1 text-xs text-text-muted">Users, roles, and permissions.</div>
          </Link>
          <Link v-if="canAny(['admin.settings.manage'])" href="/admin/settings" class="rounded-2xl border border-border bg-surface-muted p-4 transition hover:bg-surface">
            <div class="text-sm font-semibold text-text-primary">Settings</div>
            <div class="mt-1 text-xs text-text-muted">System settings and configuration.</div>
          </Link>
        </div>
      </div>

      <aside class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="text-sm font-semibold text-text-primary">Access</div>
        <div class="mt-1 text-xs text-text-muted">Your menu adapts to your permissions.</div>
        <div class="mt-4 rounded-xl border border-border bg-surface-muted px-4 py-3 text-xs text-text-muted">
          If you need access to a module, contact a Super Admin to grant the appropriate permissions.
        </div>
      </aside>
    </div>
  </AdminLayout>
</template>

