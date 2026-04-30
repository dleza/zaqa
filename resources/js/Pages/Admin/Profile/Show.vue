<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link } from '@inertiajs/vue3'
import { User as UserIcon } from 'lucide-vue-next'

const props = defineProps<{
  profile: any
}>()

function labelOrDash(v: any) {
  const s = (v ?? '').toString().trim()
  return s.length ? s : '—'
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <UserIcon class="h-4 w-4" aria-hidden="true" />
          Account
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">My Profile</h1>
        <p class="mt-1 text-sm text-text-muted">Your account details for ZAQA admin operations.</p>
      </div>

      <div class="flex flex-wrap gap-2">
        <Link href="/admin/change-password" class="zaqa-btn zaqa-btn-secondary h-10 px-4 py-2 text-sm">
          Change Password
        </Link>
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
              <div class="truncate text-base font-semibold text-text-primary">{{ labelOrDash(profile?.name) }}</div>
              <div class="mt-0.5 truncate text-xs text-text-muted">{{ labelOrDash(profile?.email) }}</div>
            </div>
          </div>

          <div class="mt-5 grid gap-3 text-sm">
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Primary phone</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.phone_primary) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Secondary phone</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.phone_secondary) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Department</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.department?.name) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Last login</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.last_login_at) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Joined</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.created_at) }}</div>
            </div>
          </div>

          <div class="mt-5">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Roles</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="r in (profile?.roles ?? [])" :key="r" class="zaqa-badge">{{ r }}</span>
              <span v-if="(profile?.roles ?? []).length === 0" class="text-xs text-text-muted">—</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

